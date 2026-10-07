<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Routines;

	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\TypeMapper;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRollback;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAtomic;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBreak;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCall;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstContinue;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeclare;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstForeach;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIf;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReturn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstVariableAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstWhile;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLCall;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\QueryFunction;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\RoutineBlock;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ResolveRoutineReferences;

	/**
	 * Semantic analysis for a parsed routine: block-scoped locals/cursors with
	 * declare-before-use, position-specific type names, cursor/foreach rules
	 * and control-flow checks. Types routine variable and cursor-field
	 * identifiers in place (see IdentifierType). Dialect restrictions are
	 * checked by the compiler, not here.
	 */
	class RoutineAnalyzer {

		private EntityStore $entityStore;
		private RoutineScope $scope;
		private bool $returnsNoValue;
		private AstRoutineDefinition $routine;

		/**
		 * Initializes semantic analysis with the entity metadata store.
		 * @param EntityStore $entityStore Entity metadata for property checks
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
		}

		/**
		 * Validates the routine, typing variable and cursor-field identifiers.
		 * @param AstRoutineDefinition $routine Parsed routine
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		public function analyze(AstRoutineDefinition $routine): void {
			$this->routine = $routine;
			$this->scope = new RoutineScope($this->collectDeclaredNames($routine));
			$this->returnsNoValue = $routine->returnsNoValue();

			if (QueryFunction::isBuiltin($routine->getName())) {
				throw new SemanticException("'{$routine->getName()}' is a built-in function, so a routine by that name could never be called.");
			}

			if ($this->isStatementKeyword($routine->getName())) {
				throw new SemanticException("'{$routine->getName()}' is a statement keyword, so a routine by that name couldn't be called as a statement.");
			}

			$placeholders = new CollectNodes(AstParameter::class);
			$routine->accept($placeholders);

			if (!empty($placeholders->getCollectedNodes())) {
				throw new SemanticException("Routines take values through their parameters; ':{$placeholders->getCollectedNodes()[0]->getName()}' placeholders aren't allowed.");
			}

			$returnType = self::normalizeType($routine->getDeclaredReturnType());

			if ($returnType !== 'void' && $returnType !== 'trigger' && !TypeMapper::isValidColumnType($returnType)) {
				throw new SemanticException("Unknown return type '{$routine->getDeclaredReturnType()}' for routine '{$routine->getName()}'.");
			}

			$hasEntityRowParameter = false;

			foreach ($routine->getParameters() as $parameter) {
				$entityClass = self::resolveEntityType($this->entityStore, $parameter->getType());

				if ($entityClass !== null) {
					$hasEntityRowParameter = true;
					$this->scope->declareEntityRow($parameter->getName(), $entityClass);
					continue;
				}

				if (!TypeMapper::isValidColumnType(self::normalizeType($parameter->getType()))) {
					throw new SemanticException("Unknown type '{$parameter->getType()}' for parameter '{$parameter->getName()}'. Parameters take column types or a declared entity name; 'void' and 'cursor' aren't allowed.");
				}

				$this->scope->declareScalar($parameter->getName());
			}

			if ($routine->isTrigger() && !$hasEntityRowParameter) {
				throw new SemanticException("'{$routine->getName()}' declares return type 'trigger' but has no entity-row parameter; a trigger routine needs at least one, e.g. '(UserEntity old)'.");
			}

			if ($hasEntityRowParameter && !$routine->isTrigger()) {
				throw new SemanticException("'{$routine->getName()}' has an entity-row parameter, so it must declare return type 'trigger', not '{$routine->getDeclaredReturnType()}'.");
			}

			$this->analyzeRanges($routine->getRanges());
			$this->analyzeBlock($routine->getBody());

			(new RoutineControlFlowValidator())->validate($routine);
		}

		/**
		 * Checks and declares the ranges declared ahead of `define function`.
		 * @param AstRange[] $ranges Ranges in declaration order
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function analyzeRanges(array $ranges): void {
			foreach ($ranges as $range) {
				if ($range instanceof AstRangeJsonSource) {
					throw new SemanticException("JSON ranges aren't supported in routines; declare a plain entity range.");
				}

				// Declared first: a `via` condition refers to its own range
				$this->scope->declareRange($range);
				$range->getJoinProperty()?->accept($this->referenceResolver(true));
			}
		}

		/**
		 * Analyzes a statement list in source order.
		 * @param AstInterface[] $statements Statements of one block
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function analyzeBlock(array $statements): void {
			foreach ($statements as $statement) {
				$this->analyzeStatement($statement);
			}
		}

		/**
		 * Dispatches one statement to its check.
		 * @param AstInterface $statement The statement
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function analyzeStatement(AstInterface $statement): void {
				switch (true) {
				case $statement instanceof AstDeclare:
					$this->analyzeDeclaration($statement);
					break;

				case $statement instanceof AstVariableAssignment:
					$this->analyzeAssignment($statement);
					break;

				case $statement instanceof AstReturn:
					if ($this->returnsNoValue) {
						if ($statement->getValue() !== null) {
							throw new SemanticException("'{$this->routine->getName()}' declares return type '{$this->routine->getDeclaredReturnType()}' and can't return a value.");
						}

						break;
					}

					if ($statement->getValue() === null) {
						throw new SemanticException("A non-void routine must return a value; bare 'return' is only allowed in a void or trigger routine.");
					}

					$statement->getValue()->accept($this->referenceResolver(false));
					break;

				case $statement instanceof AstIf:
					$statement->getCondition()->accept($this->referenceResolver(false));
					$this->scope->pushScope();
					$this->analyzeBlock($statement->getThenBody());
					$this->scope->popScope();
					$this->scope->pushScope();
					$this->analyzeBlock($statement->getElseBody() ?? []);
					$this->scope->popScope();
					break;

				case $statement instanceof AstWhile:
					$statement->getCondition()->accept($this->referenceResolver(false));
					$this->scope->pushScope();
					$this->analyzeBlock($statement->getBody());
					$this->scope->popScope();
					break;

				case $statement instanceof AstForeach:
					$this->analyzeForeach($statement);
					break;

				case $statement instanceof AstAtomic:
					$this->scope->pushScope();
					$this->analyzeBlock($statement->getBody());
					$this->scope->popScope();
					break;

				case $statement instanceof AstRollback:
				case $statement instanceof AstBreak:
				case $statement instanceof AstContinue:
					// Placement is checked by RoutineControlFlowValidator
					break;

				case $statement instanceof AstRetrieve:
					$this->analyzeRoutineRetrieve($statement);
					break;

				case $statement instanceof AstAppend:
				case $statement instanceof AstReplace:
				case $statement instanceof AstDelete:
					$statement->accept($this->referenceResolver(true));
					break;

				case $statement instanceof AstCall:
					$this->analyzeCall($statement);
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
				$this->assertFieldNamesDistinctIgnoringCase($name, $initializer);
				$resolved = $this->scope->declareCursor($declaration);

				if ($resolved !== $name) {
					$declaration->setName($resolved);
				}

				return;
			}

			if (!TypeMapper::isValidColumnType($type)) {
				throw new SemanticException("Unknown type '{$declaration->getType()}' for local '{$name}'. Locals take column types or 'cursor'; 'void' isn't allowed.");
			}

			if ($initializer instanceof AstRetrieve) {
				throw new SemanticException("Only a cursor can be initialized with a retrieve; '{$name}' is declared '{$declaration->getType()}'.");
			}

			$initializer?->accept($this->referenceResolver(false));
			$resolved = $this->scope->declareScalar($name);

			if ($resolved !== $name) {
				$declaration->setName($resolved);
			}
		}

		/**
		 * `name = expr`: the target must be a parameter or scalar local declared earlier.
		 * `name = retrieve (...)` instead rebinds an existing cursor to a new query.
		 * @param AstVariableAssignment $assignment The assignment
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function analyzeAssignment(AstVariableAssignment $assignment): void {
			$name = $assignment->getName();
			$value = $assignment->getValue();

			if ($value instanceof AstRetrieve) {
				$this->analyzeCursorRebind($assignment, $name, $value);
				return;
			}

			if (!$this->scope->isScalar($name)) {
				throw new SemanticException(match (true) {
					$this->scope->isCursor($name) => "Cursor '{$name}' can only be assigned a retrieve: '{$name} = retrieve (...)'.",
					$this->scope->isEntityRow($name) => "'{$name}' is a row parameter and read-only; it can't be assigned.",
					$this->scope->isRange($name) => "Range '{$name}' can't be assigned; use replace to change its rows.",
					$this->scope->isDeclaredAnywhere($name) => "'{$name}' is assigned before its declaration.",
					default => "Assignment to undeclared variable '{$name}'.",
				});
			}

			// isScalar($name) was already confirmed above, so resolveScalar() can't return null here
			$resolved = $this->scope->resolveScalar($name) ?? $name;

			if ($resolved !== $name) {
				$assignment->setName($resolved);
			}

			$value->accept($this->referenceResolver(false));
		}

		/**
		 * `name = retrieve (...)`: rebinds an existing cursor to a new query, from this point
		 * in the current block onward. Mints a fresh resolved name (see rebindCursor()), so this
		 * is legal even while `name`'s own `foreach` loop is open: the open loop keeps iterating
		 * the old resolved cursor, unaffected by `name` now shadowing a new one.
		 * @param AstVariableAssignment $assignment The assignment
		 * @param string $name Cursor name as written
		 * @param AstRetrieve $query The new query
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function analyzeCursorRebind(AstVariableAssignment $assignment, string $name, AstRetrieve $query): void {
			if (!$this->scope->isCursor($name)) {
				throw new SemanticException(match (true) {
					$this->scope->isScalar($name) => "'{$name}' is declared as a scalar, so it can't be assigned a retrieve.",
					$this->scope->isEntityRow($name) => "'{$name}' is a row parameter and read-only; it can't be assigned a retrieve.",
					$this->scope->isRange($name) => "Range '{$name}' can't be assigned; use replace to change its rows.",
					$this->scope->isDeclaredAnywhere($name) => "Cursor '{$name}' is used before its declaration.",
					default => "Assignment to undeclared variable '{$name}'.",
				});
			}

			// The name isn't rebound yet, so the query can't refer to the cursor's own current row
			$this->analyzeRoutineRetrieve($query);
			$this->assertFieldNamesDistinctIgnoringCase($name, $query);

			$assignment->setName($this->scope->rebindCursor($name, $query));
		}

		/**
		 * `foreach x as row { }`: x must be a cursor with no loop over it already open;
		 * row is bound to its fields for the body, checked against whatever else is in scope.
		 * @param AstForeach $foreach The loop
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function analyzeForeach(AstForeach $foreach): void {
			$cursorName = $foreach->getCursorName();
			$this->assertCursor($cursorName, 'foreach');

			// assertCursor() already confirmed isCursor($cursorName), so resolveCursor() can't return null here
			$resolvedCursorName = $this->scope->resolveCursor($cursorName) ?? $cursorName;
			$foreach->setCursorName($resolvedCursorName);

			if ($this->scope->isLoopOpen($resolvedCursorName)) {
				throw new SemanticException("'foreach {$cursorName}' is nested inside another loop over the same cursor, which is still open.");
			}

			$this->scope->openLoop($resolvedCursorName);
			$this->scope->pushScope();
			$this->scope->bindRow($foreach->getRowName(), $resolvedCursorName);
			$this->analyzeBlock($foreach->getBody());
			$this->scope->unbindRow();
			$this->scope->popScope();
			$this->scope->closeLoop();
		}

		/**
		 * `name(args)` statement: arguments read variables and cursor fields, like an assigned value.
		 * @param AstCall $statement The call
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function analyzeCall(AstCall $statement): void {
			$statement->accept($this->referenceResolver(false));
		}

		/**
		 * Checks whether a token begins a routine statement.
		 * @param string $name Routine name
		 * @return bool True when a top-level or routine-body statement starts with this word
		 */
		private function isStatementKeyword(string $name): bool {
			$name = strtolower($name);
			return in_array($name, Parser::STATEMENT_KEYWORDS, true) || in_array($name, RoutineBlock::STATEMENT_KEYWORDS, true);
		}

		/**
		 * Checks a standalone or cursor-initializer retrieve, then types its references.
		 * @param AstRetrieve $retrieve The retrieve
		 * @return void
		 * @throws SemanticException
		 */
		private function analyzeRoutineRetrieve(AstRetrieve $retrieve): void {
			foreach ($retrieve->getRanges() as $range) {
				if ($range instanceof AstRangeJsonSource) {
					throw new SemanticException("JSON ranges aren't supported in routine retrieves; use a plain entity range.");
				}
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
		 * Validates that a cursor reference is declared and in scope.
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
				$this->scope->isScalar($name), $this->scope->isRange($name), $this->scope->isEntityRow($name) => "'{$statement} {$name}' needs a cursor, but '{$name}' is not one.",
				$this->scope->isDeclaredAnywhere($name) => "Cursor '{$name}' is used before its declaration.",
				default => "Undefined cursor '{$name}'.",
			});
		}

		/**
		 * Rejects cursor fields that differ only in case; each field is fetched into its own variable on some engines.
		 * @param string $cursorName Cursor name, for the error message
		 * @param AstRetrieve $query The cursor's query
		 * @return void
		 * @throws SemanticException
		 */
		private function assertFieldNamesDistinctIgnoringCase(string $cursorName, AstRetrieve $query): void {
			$seen = [];

			foreach ($query->getValues() as $value) {
				$key = strtolower($value->getName());

				if (isset($seen[$key])) {
					throw new SemanticException("Fields '{$cursorName}.{$seen[$key]}' and '{$cursorName}.{$value->getName()}' differ only in case; rename one.");
				}

				$seen[$key] = $value->getName();
			}
		}

		/**
		 * Creates a resolver for routine range references.
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
			return TypeMapper::normalizeType($type);
		}

		/**
		 * Resolves a parameter's declared type against entity metadata, for entity-row parameters.
		 * Shared with RoutineLowering, which needs the same resolution to type an entity-row
		 * parameter's fields for RoutineFieldTypes/RoutineTypeChecker.
		 * @param EntityStore $entityStore Entity metadata
		 * @param string $type Type name as written at the parameter
		 * @return string|null Fully qualified entity class, or null when $type isn't a declared entity
		 * @throws EntityResolutionException
		 */
		public static function resolveEntityType(EntityStore $entityStore, string $type): ?string {
			if (!$entityStore->exists($type)) {
				return null;
			}

			return $entityStore->getMetadata($type)->className;
		}

		/**
		 * Collects routine declarations to check for name conflicts.
		 * @param AstRoutineDefinition $routine Parsed routine
		 * @return string[] Every local name the body declares, at any depth, plus every declared range name
		 */
		private function collectDeclaredNames(AstRoutineDefinition $routine): array {
			$collector = new CollectNodes(AstDeclare::class);
			$routine->accept($collector);

			$names = array_map(fn(AstDeclare $node) => $node->getName(), $collector->getCollectedNodes());

			foreach ($routine->getRanges() as $range) {
				$names[] = $range->getName();
			}

			return $names;
		}
	}
