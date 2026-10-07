<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines\Lowering;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\SqlDialect\DDLTypeMapper;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\SqlDialect\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRollback;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAtomic;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBreak;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCall;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstContinue;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeclare;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstForeach;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIf;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReturn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstVariableAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstWhile;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\RoutineAnalyzer;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\SqlDialect\RoutineReferenceSql;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\RoutineMetadata;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\RoutineStatementCompiler;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\RoutineTypeChecker;

	/**
	 * Lowers an analyzed routine to one engine's CREATE FUNCTION/PROCEDURE statements.
	 * Walks the body and compiles embedded statements; subclasses supply the engine's
	 * control flow, variables and cursor loops.
	 */
	abstract class RoutineLowering {

		protected const string INDENT = "\t";

		protected EntityStore $entityStore;
		protected RoutineStatementCompiler $statements;
		protected PlatformCapabilitiesInterface $platform;
		protected SqlIdentifierQuoter $quoter;

		/** Schema that qualifies routine names, or null for none */
		protected ?string $routineSchema;
		protected DDLTypeMapper $typeMapper;

		/** @var array<string, AstRetrieve> Prepared query of each cursor, in declaration order */
		protected array $cursorQueries;

		/** @var string[] Cursors of the loops enclosing the statement being lowered, outermost first */
		protected array $openLoops;

		/** Routine being lowered */
		protected AstRoutineDefinition $routine;

		/** JSON metadata for the routine being lowered (see RoutineMetadata); emitted only on the PROCEDURE path */
		protected string $metadataJson;

		/**
		 * Initializes shared SQL lowering with the target platform and routine scope.
		 * @param EntityStore $entityStore Entity metadata
		 * @param RoutineStatementCompiler $statements Compiles embedded statements for the target engine
		 */
		public function __construct(EntityStore $entityStore, RoutineStatementCompiler $statements) {
			$this->entityStore = $entityStore;
			$this->statements = $statements;
			$this->platform = $statements->getPlatform();
			$this->quoter = new SqlIdentifierQuoter($this->platform);
			$this->routineSchema = $statements->getRoutineSchema();
			$this->typeMapper = new DDLTypeMapper($this->platform);
		}

		/**
		 * Lowers an analyzed routine to target-platform SQL.
		 * @param AstRoutineDefinition $routine Routine that passed RoutineAnalyzer
		 * @return list<string> Statements to run in order; the first creates the routine, any later ones attach its metadata
		 * @throws SemanticException When the routine uses something the engine can't express
		 * @throws EntityResolutionException|TransformationException|QuelException
		 */
		public function lower(AstRoutineDefinition $routine): array {
			$this->routine = $routine;
			$this->cursorQueries = [];
			$this->openLoops = [];
			$this->metadataJson = RoutineMetadata::build($routine, $this->entityStore);

			if (!$routine->returnsNoValue() && $this->contains($routine, [AstAtomic::class])) {
				throw new SemanticException("'atomic' is only supported in void or trigger functions.");
			}

			$this->validate($routine);
			$this->declareVariableTypes($routine);
			$this->prepareCursors($routine);
			(new RoutineTypeChecker($this->statements->getFieldTypes()))->check($routine);

			return $this->render($routine);
		}

		/**
		 * Returns the target engine name.
		 * @return string Engine name for error messages
		 */
		abstract protected function engineName(): string;

		/**
		 * Renders the routine definition as SQL.
		 * @param AstRoutineDefinition $routine The routine, with cursors prepared
		 * @return list<string> Statements to run in order
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		abstract protected function render(AstRoutineDefinition $routine): array;

		/**
		 * Compiles a routine variable assignment.
		 * @param string $name Variable name
		 * @param AstInterface $value Value expression
		 * @return string One assignment statement
		 * @throws SemanticException
		 */
		abstract protected function assignment(string $name, AstInterface $value): string;

		/**
		 * Compiles a routine RETURN statement.
		 * @param AstReturn $return The return
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException
		 */
		abstract protected function lowerReturn(AstReturn $return, int $depth): string;

		/**
		 * Compiles a conditional routine statement.
		 * @param AstIf $if The if statement
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		abstract protected function lowerIf(AstIf $if, int $depth): string;

		/**
		 * Compiles a while loop for the target database engine.
		 * @param AstWhile $while The loop
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		abstract protected function lowerWhile(AstWhile $while, int $depth): string;

		/**
		 * Compiles a cursor iteration loop.
		 * @param AstForeach $foreach The loop
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		abstract protected function lowerForeach(AstForeach $foreach, int $depth): string;

		/**
		 * Compiles an atomic block in the routine.
		 * @param AstAtomic $atomic The atomic block
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		abstract protected function lowerAtomic(AstAtomic $atomic, int $depth): string;

		/**
		 * Compiles the routine rollback statement.
		 * @return string The rollback statement for `rollback`
		 */
		abstract protected function rollbackStatement(): string;

		/**
		 * Compiles a break statement for the target engine.
		 * @return string The statement that leaves the innermost loop, for `break`
		 */
		abstract protected function breakStatement(): string;

		/**
		 * Compiles a continue statement for the target engine.
		 * @return string The statement that starts the innermost loop's next iteration, for `continue`
		 */
		abstract protected function continueStatement(): string;

		/**
		 * Runs a retrieve and discards its rows.
		 * @param AstRetrieve $retrieve A retrieve whose rows are discarded
		 * @return string One statement that runs it
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		abstract protected function discardRetrieve(AstRetrieve $retrieve): string;

		/**
		 * Engine-specific checks before lowering starts.
		 * @param AstRoutineDefinition $routine The routine
		 * @return void
		 * @throws SemanticException
		 */
		protected function validate(AstRoutineDefinition $routine): void {
		}

		/**
		 * Prepares a cursor query.
		 * @param string $cursorName Cursor name
		 * @param AstRetrieve $initializer The cursor's retrieve, as analyzed
		 * @return AstRetrieve The prepared query
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		protected function prepareCursorQuery(string $cursorName, AstRetrieve $initializer): AstRetrieve {
			return $this->statements->prepareRetrieve($initializer);
		}

		/**
		 * Lowers a block of routine statements.
		 * @param AstInterface[] $statements Statements of one block
		 * @param int $depth Indentation depth
		 * @return string Lowered statements, one or more lines each
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		protected function lowerBlock(array $statements, int $depth): string {
			$result = '';

			foreach ($statements as $statement) {
				$result .= $this->lowerStatement($statement, $depth);
			}

			return $result;
		}

		/**
		 * Lowers a loop body with its cursor recorded as open.
		 * @param AstForeach $foreach The loop
		 * @param int $depth Indentation depth of the body
		 * @return string
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		protected function lowerLoopBody(AstForeach $foreach, int $depth): string {
			$this->openLoops[] = $foreach->getCursorName();
			$body = $this->lowerBlock($foreach->getBody(), $depth);
			array_pop($this->openLoops);

			return $body;
		}

		/**
		 * Collects every scalar local declaration in the routine, at any depth.
		 * @param AstRoutineDefinition $routine The routine, as analyzed
		 * @return AstDeclare[] Scalar local declarations, in source order
		 */
		protected function scalarDeclarations(AstRoutineDefinition $routine): array {
			$collector = new CollectNodes(AstDeclare::class);
			$routine->accept($collector);

			return array_values(array_filter(
				$collector->getCollectedNodes(),
				fn(AstDeclare $d) => !$d->isCursor()
			));
		}

		/**
		 * Collects every cursor declaration and cursor rebind (`name = retrieve (...)`) in the
		 * routine, at any depth. Each is an independent SQL cursor object with its own query.
		 * @param AstRoutineDefinition $routine The routine, as analyzed
		 * @return array<int, array{name: string, query: AstRetrieve}> Resolved cursor name and query, in source order
		 */
		protected function cursorBindings(AstRoutineDefinition $routine): array {
			$collector = new CollectNodes([AstDeclare::class, AstVariableAssignment::class]);
			$routine->accept($collector);

			$bindings = [];

			foreach ($collector->getCollectedNodes() as $node) {
				if ($node instanceof AstDeclare && $node->isCursor()) {
					$initializer = $node->getInitializer();

					if (!$initializer instanceof AstRetrieve) {
						throw new \LogicException("Cursor '{$node->getName()}' has no retrieve; RoutineAnalyzer should have rejected it.");
					}

					$bindings[] = ['name' => $node->getName(), 'query' => $initializer];
				} elseif ($node instanceof AstVariableAssignment && $node->getValue() instanceof AstRetrieve) {
					$bindings[] = ['name' => $node->getName(), 'query' => $node->getValue()];
				}
			}

			return $bindings;
		}

		/**
		 * Checks whether a routine block contains a node of the requested type.
		 * @param AstRoutineDefinition $routine The routine
		 * @param array<class-string<AstInterface>> $nodeClasses Node classes to look for
		 * @return bool True when the body contains one of them
		 */
		protected function contains(AstRoutineDefinition $routine, array $nodeClasses): bool {
			$collector = new CollectNodes($nodeClasses);
			$routine->accept($collector);
			return !empty($collector->getCollectedNodes());
		}

		/**
		 * Checks whether a routine block modifies database tables.
		 * @param AstRoutineDefinition $routine The routine
		 * @return bool True when the body writes a table
		 */
		protected function writesTables(AstRoutineDefinition $routine): bool {
			return $this->contains($routine, [AstAppend::class, AstReplace::class, AstDelete::class]);
		}

		/**
		 * Maps a routine type to its SQL representation.
		 * @param string $type Routine type name, e.g. `integer` or `int`
		 * @return string SQL type on the target engine
		 */
		protected function sqlType(string $type): string {
			return $this->typeMapper->getTempTableColumnType([
				'type'      => RoutineAnalyzer::normalizeType($type),
				'limit'     => null,
				'unsigned'  => false,
				'precision' => null,
				'scale'     => null,
				'values'    => null,
			]);
		}

		/**
		 * Indents one generated SQL line to the requested depth.
		 * @param string $text Line content
		 * @param int $depth Indentation depth
		 * @return string Indented line with a trailing newline
		 */
		protected function line(string $text, int $depth): string {
			return str_repeat(self::INDENT, $depth) . $text . "\n";
		}

		/**
		 * Indents and joins generated SQL lines.
		 * @param string[] $lines Lines without indentation or newline
		 * @param int $depth Indentation depth
		 * @return string
		 */
		protected function lines(array $lines, int $depth): string {
			return implode('', array_map(fn(string $line) => $this->line($line, $depth), $lines));
		}

		/**
		 * Lowers one routine statement to SQL.
		 * @param AstInterface $statement Statement to lower
		 * @param int $depth Indentation depth
		 * @return string Lowered lines
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		private function lowerStatement(AstInterface $statement, int $depth): string {
			return match (true) {
				$statement instanceof AstDeclare => $this->lowerDeclaration($statement, $depth),
				// A cursor rebind has no runtime SQL of its own; foreach reads whichever cursor is current at each site
				$statement instanceof AstVariableAssignment && $statement->getValue() instanceof AstRetrieve => '',
				$statement instanceof AstVariableAssignment => $this->line($this->assignment($statement->getName(), $statement->getValue()), $depth),
				$statement instanceof AstReturn => $this->lowerReturn($statement, $depth),
				$statement instanceof AstIf => $this->lowerIf($statement, $depth),
				$statement instanceof AstWhile => $this->lowerWhile($statement, $depth),
				$statement instanceof AstForeach => $this->lowerForeach($statement, $depth),
				$statement instanceof AstAtomic => $this->lowerAtomic($statement, $depth),
				$statement instanceof AstRollback => $this->line($this->rollbackStatement(), $depth),
				$statement instanceof AstBreak => $this->line($this->breakStatement(), $depth),
				$statement instanceof AstContinue => $this->line($this->continueStatement(), $depth),
				$statement instanceof AstRetrieve => $this->line($this->discardRetrieve($statement), $depth),
				$statement instanceof AstAppend => $this->line($this->statements->compileAppend($statement) . ';', $depth),
				$statement instanceof AstReplace => $this->line($this->statements->compileReplace($statement) . ';', $depth),
				$statement instanceof AstDelete => $this->line($this->statements->compileDelete($statement) . ';', $depth),
				$statement instanceof AstCall => $this->lowerCall($statement, $depth),
				default => throw new \LogicException('Unsupported routine statement ' . get_class($statement) . '; RoutineAnalyzer should have rejected it.'),
			};
		}

		/**
		 * Lowers a procedure call statement.
		 * @param AstCall $statement The call
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException|EntityResolutionException|QuelException
		 */
		protected function lowerCall(AstCall $statement, int $depth): string {
			return $this->line($this->statements->compileCall($statement) . ';', $depth);
		}

		/**
		 * A scalar initializer becomes an assignment at the declaration's position.
		 * @param AstDeclare $declaration The declaration
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException
		 */
		private function lowerDeclaration(AstDeclare $declaration, int $depth): string {
			$initializer = $declaration->getInitializer();

			if ($declaration->isCursor() || $initializer === null) {
				return '';
			}

			return $this->line($this->assignment($declaration->getName(), $initializer), $depth);
		}

		/**
		 * Prepares every cursor's query up front, so an unused cursor or rebind is checked too.
		 * @param AstRoutineDefinition $routine The routine, as analyzed
		 * @return void
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		private function prepareCursors(AstRoutineDefinition $routine): void {
			foreach ($this->cursorBindings($routine) as ['name' => $name, 'query' => $query]) {
				$this->cursorQueries[$name] = $this->prepareCursorQuery($name, $query);
				$this->statements->getFieldTypes()->recordCursor($name, $this->cursorQueries[$name]);
			}
		}

		/**
		 * Compiles and converts a value assigned in the routine.
		 * @param string $name Variable name
		 * @param AstInterface $value Value assigned to it
		 * @return string The value's SQL, as a datetime when a Unix timestamp goes into a datetime variable
		 * @throws SemanticException|EntityResolutionException|QuelException
		 */
		protected function assignedValue(string $name, AstInterface $value): string {
			$type = $this->statements->getFieldTypes()->variableType($name);
			return $type === null ? $this->statements->compileValue($value) : $this->statements->compileStoredValue($value, $name, $type);
		}

		/**
		 * Compiles the value returned by the routine.
		 * @param AstInterface $value The returned value
		 * @return string The returned value's SQL, as a datetime when a Unix timestamp is returned as one
		 * @throws SemanticException|EntityResolutionException|QuelException
		 */
		protected function returnedValue(AstInterface $value): string {
			return $this->statements->compileStoredValue($value, $this->routine->getName(), $this->routine->getDeclaredReturnType());
		}

		/**
		 * Expands the routine's parameter list, replacing each entity-row parameter with one
		 * entry per mapped column, in the entity's column-declaration order. No engine here has
		 * a native row/record parameter type, so this is the portable lowering for an entity-row
		 * parameter: every mapped column becomes its own native scalar parameter, in the same
		 * order at every call site (see objectquel-equel-triggers-design.md). The source body
		 * still reads it as `paramName.field`; RoutineReferenceSql renders that to the same bare
		 * name this produces.
		 * @param AstRoutineDefinition $routine The routine
		 * @return list<array{name: string, sqlType: string}> Bare variable name and SQL type, in order
		 * @throws EntityResolutionException
		 */
		protected function flattenedParameters(AstRoutineDefinition $routine): array {
			$flattened = [];

			foreach ($routine->getParameters() as $parameter) {
				$entityClass = RoutineAnalyzer::resolveEntityType($this->entityStore, $parameter->getType());

				if ($entityClass === null) {
					$flattened[] = ['name' => $parameter->getName(), 'sqlType' => $this->sqlType($parameter->getType())];
					continue;
				}

				$metadata = $this->entityStore->getMetadata($entityClass);

				foreach (array_keys($metadata->columnMap) as $property) {
					$flattened[] = [
						'name'    => RoutineReferenceSql::entityRowFieldVariable($parameter->getName(), $property),
						'sqlType' => $this->entityRowFieldSqlType($entityClass, $property),
					];
				}
			}

			return $flattened;
		}

		/**
		 * Maps an entity-row parameter field to its native SQL type, from the entity's mapped column.
		 * @param string $entityClass Fully qualified entity class
		 * @param string $property Mapped property name
		 * @return string SQL type on the target engine
		 */
		protected function entityRowFieldSqlType(string $entityClass, string $property): string {
			$definition = $this->statements->getFieldTypes()->columnType($entityClass, $property);

			if ($definition === null) {
				throw new \LogicException("'{$entityClass}.{$property}' has no mapped column type; RoutineAnalyzer should have rejected it.");
			}

			return $this->typeMapper->getTempTableColumnType($definition);
		}

		/**
		 * Records the declared type of every parameter and scalar local, for statements that read them.
		 * @param AstRoutineDefinition $routine The routine
		 * @return void
		 */
		private function declareVariableTypes(AstRoutineDefinition $routine): void {
			$fieldTypes = $this->statements->getFieldTypes();

			foreach ($routine->getParameters() as $parameter) {
				$entityClass = RoutineAnalyzer::resolveEntityType($this->entityStore, $parameter->getType());

				if ($entityClass !== null) {
					$fieldTypes->declareEntityRowParameter($parameter->getName(), $entityClass);
					continue;
				}

				$fieldTypes->declareVariable($parameter->getName(), $parameter->getType());
			}

			foreach ($this->scalarDeclarations($routine) as $statement) {
				$fieldTypes->declareVariable($statement->getName(), $statement->getType());
			}

			$fieldTypes->setDeclaredRanges($routine->getRanges());
		}
	}
