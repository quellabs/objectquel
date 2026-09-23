<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\DDLTypeMapper;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\TypeMapper;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeclare;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIf;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIn;
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
	 * Checks value types by category (numeric, string, boolean, datetime, array): return
	 * values, assignments, initializers and `if`/`while` conditions against their declared
	 * types, and comparisons and column writes that involve a routine variable or cursor
	 * field. Values whose type can't be inferred, NULL included, are accepted.
	 */
	class RoutineTypeChecker {

		/** Category of each PHP-level type that ResolveType and TypeMapper produce */
		private const array CATEGORIES = [
			'int'       => 'numeric',
			'integer'   => 'numeric',
			'float'     => 'numeric',
			'string'    => 'string',
			'bool'      => 'boolean',
			'boolean'   => 'boolean',
			'\DateTime' => 'datetime',
			'datetime'  => 'datetime',
			'array'     => 'array',
		];

		/** Categories a datetime column may be compared with or written from; queries convert them to Unix timestamps */
		private const array DATETIME_COMPATIBLE = ['numeric', 'string'];

		private RoutineFieldTypes $fieldTypes;

		/** @var array<string, string> Normalized declared type of each parameter and scalar local */
		private array $variableTypes = [];

		/** @var array<string, AstRetrieve> Prepared query of each cursor */
		private array $cursorQueries = [];

		/**
		 * @param EntityStore $entityStore Entity metadata
		 * @param DDLTypeMapper $typeMapper Type mapper of the target engine
		 */
		public function __construct(EntityStore $entityStore, DDLTypeMapper $typeMapper) {
			$this->fieldTypes = new RoutineFieldTypes($entityStore, $typeMapper);
		}

		/**
		 * @param AstRoutineDefinition $routine Routine that passed RoutineAnalyzer
		 * @param array<string, AstRetrieve> $cursorQueries Prepared query of each cursor
		 * @return void
		 * @throws SemanticException When a value doesn't fit its declared type or the value it meets
		 * @throws EntityResolutionException
		 */
		public function check(AstRoutineDefinition $routine, array $cursorQueries): void {
			$this->cursorQueries = $cursorQueries;
			$ranges = [];

			foreach ($routine->getParameters() as $parameter) {
				$this->declareVariable($parameter->getName(), $parameter->getType());
			}

			foreach ($routine->getBody() as $statement) {
				if ($statement instanceof AstDeclare && !$statement->isCursor()) {
					$this->declareVariable($statement->getName(), $statement->getType());
				}

				if ($statement instanceof AstRangeDeclaration) {
					$ranges[] = $statement->getRange();
				}
			}

			$this->fieldTypes->setDeclaredRanges($ranges);

			foreach ($cursorQueries as $cursorName => $query) {
				$this->fieldTypes->recordCursor($cursorName, $query);
			}

			$collector = new CollectNodes([
				AstDeclare::class, AstVariableAssignment::class, AstReturn::class, AstIf::class, AstWhile::class,
				AstExpression::class, AstIn::class, AstReplace::class, AstAppend::class, AstReplaceCurrent::class,
			]);

			$routine->accept($collector);

			foreach ($collector->getCollectedNodes() as $node) {
				$this->checkNode($node, $routine);
			}
		}

		/**
		 * @param AstInterface $node Collected node
		 * @param AstRoutineDefinition $routine The routine, for its return type
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function checkNode(AstInterface $node, AstRoutineDefinition $routine): void {
			match (true) {
				$node instanceof AstReturn => $this->checkReturn($node, $routine),
				$node instanceof AstDeclare => $this->checkInitializer($node),
				$node instanceof AstVariableAssignment => $this->checkAssignment($node),
				$node instanceof AstIf => $this->checkCondition('if', $node->getCondition()),
				$node instanceof AstWhile => $this->checkCondition('while', $node->getCondition()),
				$node instanceof AstExpression => $this->checkComparison('A comparison', $node->getLeft(), [$node->getRight()]),
				$node instanceof AstIn => $this->checkComparison("An 'in' list", $node->getIdentifier(), $node->getParameters()),
				$node instanceof AstReplace => $this->checkColumnWrites($node->getRange()->getEntityName(), $node->getAssignments(), false),
				$node instanceof AstAppend => $this->checkAppend($node),
				$node instanceof AstReplaceCurrent => $this->checkColumnWrites($this->cursorEntity($node->getCursorName()), $node->getAssignments(), true),
				default => null,
			};
		}

		/**
		 * @param AstReturn $return The return
		 * @param AstRoutineDefinition $routine The routine
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function checkReturn(AstReturn $return, AstRoutineDefinition $routine): void {
			$returnType = RoutineAnalyzer::normalizeType($routine->getDeclaredReturnType());
			$mismatch = $this->mismatch($returnType, $return->getValue());

			if ($mismatch !== null) {
				throw new SemanticException("'{$routine->getName()}' returns {$returnType}, but a returned value is {$mismatch}.");
			}
		}

		/**
		 * @param AstDeclare $declaration The declaration
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function checkInitializer(AstDeclare $declaration): void {
			$initializer = $declaration->getInitializer();

			if ($declaration->isCursor() || $initializer === null) {
				return;
			}

			$type = $this->variableTypes[$declaration->getName()];
			$mismatch = $this->mismatch($type, $initializer);

			if ($mismatch !== null) {
				throw new SemanticException("'{$declaration->getName()}' is {$type}, but its initializer is {$mismatch}.");
			}
		}

		/**
		 * @param AstVariableAssignment $assignment The assignment
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function checkAssignment(AstVariableAssignment $assignment): void {
			$type = $this->variableTypes[$assignment->getName()];
			$mismatch = $this->mismatch($type, $assignment->getValue());

			if ($mismatch !== null) {
				throw new SemanticException("'{$assignment->getName()}' is {$type}, but the assigned value is {$mismatch}.");
			}
		}

		/**
		 * @param string $statement 'if' or 'while'
		 * @param AstInterface $condition The condition
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function checkCondition(string $statement, AstInterface $condition): void {
			$category = $this->category($condition);

			if ($category !== null && $category !== 'boolean') {
				throw new SemanticException("The condition of '{$statement}' must be boolean, but it is {$category}. Compare it explicitly, e.g. x != 0.");
			}
		}

		/**
		 * Checks the operands of a comparison or `in` list when one of them reads a routine variable or cursor field.
		 * @param string $what 'A comparison' or "An 'in' list", for the message
		 * @param AstInterface $left Left operand
		 * @param AstInterface[] $right Right operands
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function checkComparison(string $what, AstInterface $left, array $right): void {
			$reference = $this->routineReference([$left, ...$right]);

			if ($reference === null) {
				return;
			}

			$leftCategory = $this->category($left);

			foreach ($right as $operand) {
				$rightCategory = $this->category($operand);

				if (!self::compatibleInQuery($leftCategory, $rightCategory)) {
					throw new SemanticException("{$what} involving '{$reference}' mixes {$leftCategory} and {$rightCategory} values.");
				}
			}
		}

		/**
		 * @param AstAppend $append The append
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function checkAppend(AstAppend $append): void {
			if ($append->isInsertFromSelect()) {
				return;
			}

			foreach ($append->getRowsOrFail() as $row) {
				$this->checkColumnWrites($append->getEntityName(), $row, false);
			}
		}

		/**
		 * Checks `column = value` writes; outside current-row replaces only values that read a routine variable or cursor field.
		 * @param string|null $entityName Target entity, or null when it isn't an entity
		 * @param AstAssignment[] $assignments The writes
		 * @param bool $always True to check every value, not only those reading routine names
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function checkColumnWrites(?string $entityName, array $assignments, bool $always): void {
			if ($entityName === null) {
				return;
			}

			foreach ($assignments as $assignment) {
				if (!$always && $this->routineReference([$assignment->getValue()]) === null) {
					continue;
				}

				$column = $this->fieldTypes->columnType($entityName, $assignment->getProperty());
				$columnCategory = $column === null ? null : (self::CATEGORIES[TypeMapper::phinxTypeToPhpType($column['type'])] ?? null);
				$valueCategory = $this->category($assignment->getValue());

				if (!self::compatibleInQuery($columnCategory, $valueCategory)) {
					throw new SemanticException("Column '{$assignment->getProperty()}' of {$entityName} is {$column['type']}, but the value written to it is {$valueCategory}.");
				}
			}
		}

		/**
		 * @param string $declaredType Normalized declared type
		 * @param AstInterface $value Value stored into it
		 * @return string|null The value's category when it differs from the declared type's, otherwise null
		 * @throws EntityResolutionException
		 */
		private function mismatch(string $declaredType, AstInterface $value): ?string {
			$target = self::CATEGORIES[TypeMapper::phinxTypeToPhpType($declaredType)] ?? null;
			$source = $this->category($value);

			if ($target === null || $source === null || $target === $source) {
				return null;
			}

			return $source;
		}

		/**
		 * @param AstInterface $value Expression
		 * @return string|null The value's category, or null when its type can't be inferred
		 * @throws EntityResolutionException
		 */
		private function category(AstInterface $value): ?string {
			return self::CATEGORIES[$this->fieldTypes->inferReturnType($value) ?? ''] ?? null;
		}

		/**
		 * Like an exact category match, but datetime also meets numeric and string, as queries allow.
		 * @param string|null $a Category, or null when unknown
		 * @param string|null $b Category, or null when unknown
		 * @return bool
		 * @phpstan-assert-if-false !null $a
		 * @phpstan-assert-if-false !null $b
		 */
		private static function compatibleInQuery(?string $a, ?string $b): bool {
			if ($a === null || $b === null || $a === $b) {
				return true;
			}

			return ($a === 'datetime' && in_array($b, self::DATETIME_COMPATIBLE, true))
				|| ($b === 'datetime' && in_array($a, self::DATETIME_COMPATIBLE, true));
		}

		/**
		 * @param AstInterface[] $nodes Expressions to search
		 * @return string|null Name of the first routine variable or cursor field they read, or null when none
		 */
		private function routineReference(array $nodes): ?string {
			foreach ($nodes as $node) {
				$collector = new CollectNodes(AstIdentifier::class);
				$node->accept($collector);

				foreach ($collector->getCollectedNodes() as $identifier) {
					if ($identifier->getType()->isRoutineReference() && !$identifier->getParent() instanceof AstIdentifier) {
						return $identifier->getCompleteName();
					}
				}
			}

			return null;
		}

		/**
		 * @param string $cursorName Cursor written through
		 * @return string|null Entity of the cursor's single table, or null when it isn't one
		 */
		private function cursorEntity(string $cursorName): ?string {
			$ranges = ($this->cursorQueries[$cursorName] ?? null)?->getRanges() ?? [];
			return count($ranges) === 1 && $ranges[0] instanceof AstRangeDatabase ? $ranges[0]->getEntityName() : null;
		}

		/**
		 * @param string $name Parameter or local name
		 * @param string $type Declared type as written
		 * @return void
		 */
		private function declareVariable(string $name, string $type): void {
			$this->variableTypes[$name] = RoutineAnalyzer::normalizeType($type);
			$this->fieldTypes->declareVariable($name, $type);
		}
	}
