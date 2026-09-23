<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\TypeMapper;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAbort;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBeginTransaction;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeclare;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeleteCurrent;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstForeach;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIf;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
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
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ResolveRoutineReferences;

	/**
	 * Semantic analysis for a parsed routine: one flat scope with
	 * declare-before-use, top-level-only declarations, position-specific type
	 * names, cursor/foreach rules and control-flow checks. Types routine
	 * variable and cursor-field identifiers in place (see IdentifierType).
	 * Dialect restrictions are checked by the compiler, not here.
	 */
	class RoutineAnalyzer {

		/** Type names the source may use in place of a TypeMapper key */
		private const array TYPE_ALIASES = ['int' => 'integer'];

		private EntityStore $entityStore;
		private RoutineCursorSource $cursorSource;
		private RoutineScope $scope;
		private bool $isVoid;

		/**
		 * @param EntityStore $entityStore Entity metadata for property checks
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
			$this->cursorSource = new RoutineCursorSource($entityStore);
		}

		/**
		 * Validates the routine, typing variable and cursor-field identifiers.
		 * @param AstRoutineDefinition $routine Parsed routine
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		public function analyze(AstRoutineDefinition $routine): void {
			$this->scope = new RoutineScope($this->collectDeclaredNames($routine));
			$this->isVoid = $routine->isVoid();

			$placeholders = new CollectNodes(AstParameter::class);
			$routine->accept($placeholders);

			if (!empty($placeholders->getCollectedNodes())) {
				throw new SemanticException("Routines take values through their parameters; ':{$placeholders->getCollectedNodes()[0]->getName()}' placeholders aren't allowed.");
			}

			$returnType = self::normalizeType($routine->getDeclaredReturnType());

			if ($returnType !== 'void' && !TypeMapper::isValidColumnType($returnType)) {
				throw new SemanticException("Unknown return type '{$routine->getDeclaredReturnType()}' for routine '{$routine->getName()}'.");
			}

			foreach ($routine->getParameters() as $parameter) {
				if (!TypeMapper::isValidColumnType(self::normalizeType($parameter->getType()))) {
					throw new SemanticException("Unknown type '{$parameter->getType()}' for parameter '{$parameter->getName()}'. Parameters take column types; 'void' and 'cursor' aren't allowed.");
				}

				$this->scope->declareScalar($parameter->getName());
			}

			$this->analyzeBlock($routine->getBody(), true);

			(new RoutineControlFlowValidator())->validate($routine);
		}

		/**
		 * Analyzes a statement list in source order.
		 * @param AstInterface[] $statements Statements of one block
		 * @param bool $isTopLevel True for the routine body itself, the only place names may be declared
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function analyzeBlock(array $statements, bool $isTopLevel): void {
			foreach ($statements as $statement) {
				$this->analyzeStatement($statement, $isTopLevel);
			}
		}

		/**
		 * Dispatches one statement to its check.
		 * @param AstInterface $statement The statement
		 * @param bool $isTopLevel True when it sits directly in the routine body
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function analyzeStatement(AstInterface $statement, bool $isTopLevel): void {
			switch (true) {
				case $statement instanceof AstRangeDeclaration:
					$this->assertTopLevel($isTopLevel, "'range of {$statement->getRange()->getName()}'");

					// Declared first: a `via` condition refers to its own range
					$this->scope->declareRange($statement->getRange());
					$statement->getRange()->getJoinProperty()?->accept($this->referenceResolver(true));
					break;

				case $statement instanceof AstDeclare:
					$this->assertTopLevel($isTopLevel, "Declaration of '{$statement->getName()}'");
					$this->analyzeDeclaration($statement);
					break;

				case $statement instanceof AstVariableAssignment:
					$this->analyzeAssignment($statement);
					break;

				case $statement instanceof AstReturn:
					if ($this->isVoid) {
						throw new SemanticException("A void routine can't return a value.");
					}

					$statement->getValue()->accept($this->referenceResolver(false));
					break;

				case $statement instanceof AstIf:
					$statement->getCondition()->accept($this->referenceResolver(false));
					$this->analyzeBlock($statement->getThenBody(), false);
					$this->analyzeBlock($statement->getElseBody() ?? [], false);
					break;

				case $statement instanceof AstWhile:
					$statement->getCondition()->accept($this->referenceResolver(false));
					$this->analyzeBlock($statement->getBody(), false);
					break;

				case $statement instanceof AstForeach:
					$this->analyzeForeach($statement);
					break;

				case $statement instanceof AstBeginTransaction:
					$this->analyzeBlock($statement->getBody(), false);
					break;

				case $statement instanceof AstAbort:
					// Placement is checked by RoutineControlFlowValidator
					break;

				case $statement instanceof AstDeleteCurrent:
					$this->resolveCurrentRowSource($statement->getCursorName(), 'delete');
					break;

				case $statement instanceof AstReplaceCurrent:
					$this->analyzeReplaceCurrent($statement);
					break;

				case $statement instanceof AstRetrieve:
					$this->analyzeRoutineRetrieve($statement);
					break;

				case $statement instanceof AstAppend:
				case $statement instanceof AstReplace:
				case $statement instanceof AstDelete:
					$statement->accept($this->referenceResolver(true));
					break;

				default:
					throw new SemanticException('Unsupported statement in routine body: ' . get_class($statement));
			}
		}

		/**
		 * `type name [= initializer]`: checks the type position and initializer, then declares the name.
		 * @param AstDeclare $declaration The declaration
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function analyzeDeclaration(AstDeclare $declaration): void {
			$name = $declaration->getName();
			$type = self::normalizeType($declaration->getType());
			$initializer = $declaration->getInitializer();

			if ($type === 'cursor') {
				if (!$initializer instanceof AstRetrieve) {
					throw new SemanticException("Cursor '{$name}' must be initialized with a retrieve: 'cursor {$name} = retrieve (...)'.");
				}

				// The name isn't in scope yet, so the query can't refer to itself
				$this->analyzeRoutineRetrieve($initializer);
				$this->scope->declareCursor($declaration);
				return;
			}

			if (!TypeMapper::isValidColumnType($type)) {
				throw new SemanticException("Unknown type '{$declaration->getType()}' for local '{$name}'. Locals take column types or 'cursor'; 'void' isn't allowed.");
			}

			if ($initializer instanceof AstRetrieve) {
				throw new SemanticException("Only a cursor can be initialized with a retrieve; '{$name}' is declared '{$declaration->getType()}'.");
			}

			$initializer?->accept($this->referenceResolver(false));
			$this->scope->declareScalar($name);
		}

		/**
		 * `name = expr`: the target must be a parameter or scalar local declared earlier.
		 * @param AstVariableAssignment $assignment The assignment
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function analyzeAssignment(AstVariableAssignment $assignment): void {
			$name = $assignment->getName();

			if (!$this->scope->isScalar($name)) {
				throw new SemanticException(match (true) {
					$this->scope->isCursor($name) => "Cursor '{$name}' can't be reassigned.",
					$this->scope->isRange($name) => "Range '{$name}' can't be assigned; use replace to change its rows.",
					$this->scope->isDeclaredAnywhere($name) => "'{$name}' is assigned before its declaration.",
					default => "Assignment to undeclared variable '{$name}'.",
				});
			}

			$assignment->getValue()->accept($this->referenceResolver(false));
		}

		/**
		 * `foreach x { }`: x must be a cursor with no loop over it already open.
		 * @param AstForeach $foreach The loop
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function analyzeForeach(AstForeach $foreach): void {
			$cursorName = $foreach->getCursorName();
			$this->assertCursor($cursorName, 'foreach');

			if ($this->scope->isLoopOpen($cursorName)) {
				throw new SemanticException("'foreach {$cursorName}' is nested inside another loop over the same cursor, which is still open.");
			}

			$this->scope->openLoop($cursorName);
			$this->analyzeBlock($foreach->getBody(), false);
			$this->scope->closeLoop();
		}

		/**
		 * `replace x (attr = value, ...)`: attributes must be columns of the cursor's source entity.
		 * @param AstReplaceCurrent $replace The current-row replace
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function analyzeReplaceCurrent(AstReplaceCurrent $replace): void {
			$source = $this->resolveCurrentRowSource($replace->getCursorName(), 'replace');
			$columnMap = $this->entityStore->getMetadata($source->getEntityName())->columnMap;

			foreach ($replace->getAssignments() as $assignment) {
				if (!isset($columnMap[$assignment->getProperty()])) {
					throw new SemanticException("'{$assignment->getProperty()}' is not a column of {$source->getEntityName()}, the entity cursor '{$replace->getCursorName()}' reads.");
				}

				$assignment->getValue()->accept($this->referenceResolver(false));
			}
		}

		/**
		 * Checks a current-row write names an open loop's cursor and returns the table it writes to.
		 * @param string $cursorName Cursor named by the write
		 * @param string $verb 'delete' or 'replace', for error messages
		 * @return AstRangeDatabase
		 * @throws SemanticException|EntityResolutionException
		 */
		private function resolveCurrentRowSource(string $cursorName, string $verb): AstRangeDatabase {
			$this->assertCursor($cursorName, $verb);

			if (!$this->scope->isLoopOpen($cursorName)) {
				throw new SemanticException("'{$verb} {$cursorName}' writes the current row, so it must be inside 'foreach {$cursorName}'.");
			}

			return $this->cursorSource->resolve($cursorName, $this->scope->getCursorQuery($cursorName), $this->scope->getRanges());
		}

		/**
		 * Checks a standalone or cursor-initializer retrieve, then types its references.
		 * @param AstRetrieve $retrieve The retrieve
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function analyzeRoutineRetrieve(AstRetrieve $retrieve): void {
			if (!empty($retrieve->getSort())) {
				throw new SemanticException("'sort by' is not supported in a routine retrieve.");
			}

			if ($retrieve->getWindow() !== null || $retrieve->getWindowSize() !== null) {
				throw new SemanticException("'window' is not supported in a routine retrieve.");
			}

			// Target-list entries become cursor-row fields fetched into scalar variables
			foreach ($retrieve->getValues() as $value) {
				$expression = $value->getExpression();

				if ($expression instanceof AstIdentifier && $expression->getNext() === null && $this->scope->isRange($expression->getName())) {
					throw new SemanticException("Routine retrieves select values, not whole entities: retrieve properties of '{$expression->getName()}' instead.");
				}
			}

			$retrieve->accept($this->referenceResolver(true));
		}

		/**
		 * @param string $name Name used as a cursor
		 * @param string $statement Statement keyword, for error messages
		 * @return void
		 * @throws SemanticException When $name is not a cursor declared so far
		 */
		private function assertCursor(string $name, string $statement): void {
			if ($this->scope->isCursor($name)) {
				return;
			}

			throw new SemanticException(match (true) {
				$this->scope->isScalar($name), $this->scope->isRange($name) => "'{$statement} {$name}' needs a cursor, but '{$name}' is not one.",
				$this->scope->isDeclaredAnywhere($name) => "Cursor '{$name}' is used before its declaration.",
				default => "Undefined cursor '{$name}'.",
			});
		}

		/**
		 * @param bool $isTopLevel True when the statement sits directly in the routine body
		 * @param string $what Description of the declaration, for the error message
		 * @return void
		 * @throws SemanticException When not at top level
		 */
		private function assertTopLevel(bool $isTopLevel, string $what): void {
			if (!$isTopLevel) {
				throw new SemanticException("{$what} must be at the top level of the routine body, not inside if/else, while, foreach or begin transaction.");
			}
		}

		/**
		 * @param bool $inQueryStatement True inside retrieve/append/replace/delete
		 * @return ResolveRoutineReferences Visitor bound to the current scope
		 */
		private function referenceResolver(bool $inQueryStatement): ResolveRoutineReferences {
			return new ResolveRoutineReferences($this->entityStore, $this->scope, $inQueryStatement);
		}

		/**
		 * Lowercases a type name and applies aliases such as `int`.
		 * @param string $type Type name as written
		 * @return string Normalized type name
		 */
		public static function normalizeType(string $type): string {
			$type = strtolower($type);
			return self::TYPE_ALIASES[$type] ?? $type;
		}

		/**
		 * @param AstRoutineDefinition $routine Parsed routine
		 * @return string[] Every local and range name the body declares, at any depth
		 */
		private function collectDeclaredNames(AstRoutineDefinition $routine): array {
			$collector = new CollectNodes([AstDeclare::class, AstRangeDeclaration::class]);
			$routine->accept($collector);

			return array_map(
				fn(AstDeclare|AstRangeDeclaration $node) => $node instanceof AstDeclare ? $node->getName() : $node->getRange()->getName(),
				$collector->getCollectedNodes()
			);
		}
	}
