<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\DDLTypeMapper;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAbort;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBeginTransaction;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeclare;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeleteCurrent;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstForeach;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIf;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDeclaration;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplaceCurrent;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReturn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstVariableAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstWhile;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;

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
		protected DDLTypeMapper $typeMapper;
		private RoutineCursorSource $cursorSource;

		/** @var array<string, AstRetrieve> Prepared query of each cursor, in declaration order */
		protected array $cursorQueries;

		/** @var array<string, AstRange[]> Ranges declared before each cursor */
		private array $cursorRanges;

		/** @var array<string, AstRetrieve> Original query of each cursor, as analyzed */
		private array $cursorDeclarations;

		/** @var array<string, true> Cursors whose loops write the current row */
		protected array $writeCursors;

		/** @var string[] Cursors of the loops enclosing the statement being lowered, outermost first */
		protected array $openLoops;

		/**
		 * @param EntityStore $entityStore Entity metadata
		 * @param RoutineStatementCompiler $statements Compiles embedded statements for the target engine
		 */
		public function __construct(EntityStore $entityStore, RoutineStatementCompiler $statements) {
			$this->entityStore = $entityStore;
			$this->statements = $statements;
			$this->platform = $statements->getPlatform();
			$this->quoter = new SqlIdentifierQuoter($this->platform);
			$this->typeMapper = new DDLTypeMapper($this->platform);
			$this->cursorSource = new RoutineCursorSource($entityStore);
		}

		/**
		 * @param AstRoutineDefinition $routine Routine that passed RoutineAnalyzer
		 * @return string[] Statements to run in order, the last one creating the routine
		 * @throws SemanticException When the routine uses something the engine can't express
		 * @throws EntityResolutionException|TransformationException|QuelException
		 */
		public function lower(AstRoutineDefinition $routine): array {
			$this->cursorQueries = [];
			$this->cursorRanges = [];
			$this->cursorDeclarations = [];
			$this->writeCursors = $this->collectWriteCursors($routine);
			$this->openLoops = [];

			if (!$routine->isVoid() && $this->contains($routine, [AstBeginTransaction::class])) {
				throw new SemanticException("'{$routine->getName()}' returns a value, so {$this->engineName()} creates it as a FUNCTION, which can't commit or roll back. Make it void to use 'begin transaction'.");
			}

			$this->validate($routine);
			$this->prepareCursors($routine);

			return $this->render($routine);
		}

		/**
		 * @return string Engine name for error messages
		 */
		abstract protected function engineName(): string;

		/**
		 * @param AstRoutineDefinition $routine The routine, with cursors prepared
		 * @return string[] Statements to run in order
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		abstract protected function render(AstRoutineDefinition $routine): array;

		/**
		 * @param string $name Variable name
		 * @param AstInterface $value Value expression
		 * @return string One assignment statement
		 * @throws SemanticException
		 */
		abstract protected function assignment(string $name, AstInterface $value): string;

		/**
		 * @param AstReturn $return The return
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException
		 */
		abstract protected function lowerReturn(AstReturn $return, int $depth): string;

		/**
		 * @param AstIf $if The if statement
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		abstract protected function lowerIf(AstIf $if, int $depth): string;

		/**
		 * @param AstWhile $while The loop
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		abstract protected function lowerWhile(AstWhile $while, int $depth): string;

		/**
		 * @param AstForeach $foreach The loop
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		abstract protected function lowerForeach(AstForeach $foreach, int $depth): string;

		/**
		 * @param AstBeginTransaction $transaction The transaction block
		 * @param int $depth Indentation depth
		 * @return string
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		abstract protected function lowerTransaction(AstBeginTransaction $transaction, int $depth): string;

		/**
		 * @return string The rollback statement for `abort`
		 */
		abstract protected function abortStatement(): string;

		/**
		 * @param AstRetrieve $retrieve A retrieve whose rows are discarded
		 * @return string One statement that runs it
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		abstract protected function discardRetrieve(AstRetrieve $retrieve): string;

		/**
		 * @param string $cursorName Cursor of the enclosing loop
		 * @return string SQL condition selecting the loop's current row
		 * @throws SemanticException|EntityResolutionException
		 */
		abstract protected function currentRowCondition(string $cursorName): string;

		/**
		 * Engine-specific checks before lowering starts.
		 * @param AstRoutineDefinition $routine The routine
		 * @return void
		 * @throws SemanticException
		 */
		protected function validate(AstRoutineDefinition $routine): void {
		}

		/**
		 * Prepares a cursor query; a cursor that takes current-row writes must still read one table.
		 * @param string $cursorName Cursor name
		 * @param AstRetrieve $initializer The cursor's retrieve, as analyzed
		 * @return AstRetrieve The prepared query
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		protected function prepareCursorQuery(string $cursorName, AstRetrieve $initializer): AstRetrieve {
			$query = $this->statements->prepareRetrieve($initializer);

			// Positioned writes need one plain table, which the optimizer may have changed
			if (isset($this->writeCursors[$cursorName]) && count($query->getRanges()) !== 1) {
				throw new SemanticException("Cursor '{$cursorName}' compiles to a query over more than one table, so {$this->engineName()} can't update it through 'WHERE CURRENT OF'; 'delete {$cursorName}'/'replace {$cursorName}' aren't possible here.");
			}

			return $query;
		}

		/**
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
		 * @param string $cursorName Cursor of the enclosing loop
		 * @return AstRangeDatabase The table a current-row write targets
		 * @throws SemanticException|EntityResolutionException
		 */
		protected function currentRowSource(string $cursorName): AstRangeDatabase {
			return $this->cursorSource->resolve($cursorName, $this->cursorDeclarations[$cursorName], $this->cursorRanges[$cursorName]);
		}

		/**
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
		 * @param AstRoutineDefinition $routine The routine
		 * @return bool True when the body writes a table
		 */
		protected function writesTables(AstRoutineDefinition $routine): bool {
			return $this->contains($routine, [AstAppend::class, AstReplace::class, AstDelete::class, AstDeleteCurrent::class, AstReplaceCurrent::class]);
		}

		/**
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
		 * @param string $text Line content
		 * @param int $depth Indentation depth
		 * @return string Indented line with a trailing newline
		 */
		protected function line(string $text, int $depth): string {
			return str_repeat(self::INDENT, $depth) . $text . "\n";
		}

		/**
		 * @param string[] $lines Lines without indentation or newline
		 * @param int $depth Indentation depth
		 * @return string
		 */
		protected function lines(array $lines, int $depth): string {
			return implode('', array_map(fn(string $line) => $this->line($line, $depth), $lines));
		}

		/**
		 * @param AstInterface $statement Statement to lower
		 * @param int $depth Indentation depth
		 * @return string Lowered lines
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		private function lowerStatement(AstInterface $statement, int $depth): string {
			return match (true) {
				// Ranges are compiled into the statements that read them
				$statement instanceof AstRangeDeclaration => '',
				$statement instanceof AstDeclare => $this->lowerDeclaration($statement, $depth),
				$statement instanceof AstVariableAssignment => $this->line($this->assignment($statement->getName(), $statement->getValue()), $depth),
				$statement instanceof AstReturn => $this->lowerReturn($statement, $depth),
				$statement instanceof AstIf => $this->lowerIf($statement, $depth),
				$statement instanceof AstWhile => $this->lowerWhile($statement, $depth),
				$statement instanceof AstForeach => $this->lowerForeach($statement, $depth),
				$statement instanceof AstBeginTransaction => $this->lowerTransaction($statement, $depth),
				$statement instanceof AstAbort => $this->line($this->abortStatement(), $depth),
				$statement instanceof AstDeleteCurrent => $this->line($this->statements->compileCurrentRowDelete(
					$this->currentRowSource($statement->getCursorName()),
					$this->currentRowCondition($statement->getCursorName())
				) . ';', $depth),
				$statement instanceof AstReplaceCurrent => $this->line($this->statements->compileCurrentRowReplace(
					$this->currentRowSource($statement->getCursorName()),
					$statement->getAssignments(),
					$this->currentRowCondition($statement->getCursorName())
				) . ';', $depth),
				$statement instanceof AstRetrieve => $this->line($this->discardRetrieve($statement), $depth),
				$statement instanceof AstAppend => $this->line($this->statements->compileAppend($statement) . ';', $depth),
				$statement instanceof AstReplace => $this->line($this->statements->compileReplace($statement) . ';', $depth),
				$statement instanceof AstDelete => $this->line($this->statements->compileDelete($statement) . ';', $depth),
				default => throw new \LogicException('Unsupported routine statement ' . get_class($statement) . '; RoutineAnalyzer should have rejected it.'),
			};
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
		 * Prepares every cursor query up front, so unused cursors are checked too.
		 * @param AstRoutineDefinition $routine The routine; declarations are top-level only
		 * @return void
		 * @throws SemanticException|EntityResolutionException|TransformationException|QuelException
		 */
		private function prepareCursors(AstRoutineDefinition $routine): void {
			$ranges = [];

			foreach ($routine->getBody() as $statement) {
				if ($statement instanceof AstRangeDeclaration) {
					$ranges[] = $statement->getRange();
					continue;
				}

				if (!$statement instanceof AstDeclare || !$statement->isCursor()) {
					continue;
				}

				$initializer = $statement->getInitializer();

				if (!$initializer instanceof AstRetrieve) {
					throw new \LogicException("Cursor '{$statement->getName()}' has no retrieve; RoutineAnalyzer should have rejected it.");
				}

				$name = $statement->getName();
				$this->cursorDeclarations[$name] = $initializer;
				$this->cursorRanges[$name] = $ranges;
				$this->cursorQueries[$name] = $this->prepareCursorQuery($name, $initializer);
			}
		}

		/**
		 * @param AstRoutineDefinition $routine The routine
		 * @return array<string, true> Cursors named by a current-row delete or replace
		 */
		private function collectWriteCursors(AstRoutineDefinition $routine): array {
			$collector = new CollectNodes([AstDeleteCurrent::class, AstReplaceCurrent::class]);
			$routine->accept($collector);

			$cursors = [];

			foreach ($collector->getCollectedNodes() as $write) {
				$cursors[$write->getCursorName()] = true;
			}

			return $cursors;
		}
	}
