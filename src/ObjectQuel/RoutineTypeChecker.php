<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\DDLTypeMapper;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\TypeMapper;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDeclare;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReturn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstVariableAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;

	/**
	 * Checks that return values, assignments and initializers fit the declared type's
	 * category (numeric, string, boolean, datetime, array). Values whose type can't be
	 * inferred, NULL included, are accepted.
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

		private RoutineFieldTypes $fieldTypes;

		/** @var array<string, string> Normalized declared type of each parameter and scalar local */
		private array $variableTypes = [];

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
		 * @throws SemanticException When a value doesn't fit its declared type
		 * @throws EntityResolutionException
		 */
		public function check(AstRoutineDefinition $routine, array $cursorQueries): void {
			foreach ($routine->getParameters() as $parameter) {
				$this->declareVariable($parameter->getName(), $parameter->getType());
			}

			foreach ($routine->getBody() as $statement) {
				if ($statement instanceof AstDeclare && !$statement->isCursor()) {
					$this->declareVariable($statement->getName(), $statement->getType());
				}
			}

			foreach ($cursorQueries as $cursorName => $query) {
				$this->fieldTypes->recordCursor($cursorName, $query);
			}

			$collector = new CollectNodes([AstDeclare::class, AstVariableAssignment::class, AstReturn::class]);
			$routine->accept($collector);

			foreach ($collector->getCollectedNodes() as $node) {
				$this->checkNode($node, $routine);
			}
		}

		/**
		 * @param AstDeclare|AstVariableAssignment|AstReturn $node Statement that stores a value
		 * @param AstRoutineDefinition $routine The routine, for its return type
		 * @return void
		 * @throws SemanticException|EntityResolutionException
		 */
		private function checkNode(AstDeclare|AstVariableAssignment|AstReturn $node, AstRoutineDefinition $routine): void {
			if ($node instanceof AstReturn) {
				$returnType = RoutineAnalyzer::normalizeType($routine->getDeclaredReturnType());
				$mismatch = $this->mismatch($returnType, $node->getValue());

				if ($mismatch !== null) {
					throw new SemanticException("'{$routine->getName()}' returns {$returnType}, but a returned value is {$mismatch}.");
				}

				return;
			}

			if ($node instanceof AstDeclare) {
				$initializer = $node->getInitializer();

				if ($node->isCursor() || $initializer === null) {
					return;
				}

				$mismatch = $this->mismatch($this->variableTypes[$node->getName()], $initializer);

				if ($mismatch !== null) {
					throw new SemanticException("'{$node->getName()}' is {$this->variableTypes[$node->getName()]}, but its initializer is {$mismatch}.");
				}

				return;
			}

			$mismatch = $this->mismatch($this->variableTypes[$node->getName()], $node->getValue());

			if ($mismatch !== null) {
				throw new SemanticException("'{$node->getName()}' is {$this->variableTypes[$node->getName()]}, but the assigned value is {$mismatch}.");
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
			$source = self::CATEGORIES[$this->fieldTypes->inferReturnType($value) ?? ''] ?? null;

			if ($target === null || $source === null || $target === $source) {
				return null;
			}

			return $source;
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
