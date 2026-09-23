<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\DDLTypeMapper;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\TypeMapper;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Execution\Helpers\ResolveType;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;

	/**
	 * SQL types of routine variables and cursor fields, for engines that fetch cursor
	 * rows into typed variables. A plain column keeps its declared column type; other
	 * values get a type inferred from the expression.
	 * @phpstan-type TypeDefinition array{type: string, limit: int|array<int, int>|null, unsigned: bool, precision: int|null, scale: int|null, values: string[]|null}
	 */
	class RoutineFieldTypes extends ResolveType {

		/** Inferred PHP-level types and the routine type a variable of that kind gets */
		private const array INFERRED_TYPES = [
			'int'     => 'integer',
			'integer' => 'integer',
			'float'   => 'float',
			'string'  => 'text',
			'bool'    => 'boolean',
			'boolean' => 'boolean',
		];

		private EntityStore $store;
		private DDLTypeMapper $typeMapper;

		/** @var array<string, TypeDefinition> Declared type of each local and parameter */
		private array $variables = [];

		/** @var array<string, array<string, TypeDefinition>> Type of each field, by cursor */
		private array $cursorFields = [];

		/**
		 * @param EntityStore $entityStore Entity metadata
		 * @param DDLTypeMapper $typeMapper Maps type definitions to the target engine's SQL types
		 */
		public function __construct(EntityStore $entityStore, DDLTypeMapper $typeMapper) {
			parent::__construct($entityStore);
			$this->store = $entityStore;
			$this->typeMapper = $typeMapper;
		}

		/**
		 * @param string $name Local or parameter name
		 * @param string $routineType Declared routine type, e.g. `integer` or `int`
		 * @return void
		 */
		public function declareVariable(string $name, string $routineType): void {
			$this->variables[$name] = self::definition(RoutineAnalyzer::normalizeType($routineType));
		}

		/**
		 * Types every value of a prepared cursor query; later cursors can read these fields.
		 * @param string $cursorName Cursor name
		 * @param AstRetrieve $prepared Prepared cursor query
		 * @return array<string, string> SQL type of each field, by field name, in select-list order
		 * @throws SemanticException When a value's type can't be determined
		 * @throws EntityResolutionException
		 */
		public function declareCursor(string $cursorName, AstRetrieve $prepared): array {
			$this->cursorFields[$cursorName] = [];

			foreach ($prepared->getValues() as $value) {
				$this->cursorFields[$cursorName][$value->getName()] = $this->definitionOf($value->getExpression(), $cursorName, $value->getName());
			}

			return array_map(fn(array $definition) => $this->typeMapper->getTempTableColumnType($definition), $this->cursorFields[$cursorName]);
		}

		/**
		 * @param string $routineType Routine type name
		 * @return string SQL type on the target engine
		 */
		public function sqlType(string $routineType): string {
			return $this->typeMapper->getTempTableColumnType(self::definition(RoutineAnalyzer::normalizeType($routineType)));
		}

		/**
		 * Adds routine variables, cursor fields and `range.column` reads to the parent's inference.
		 * @param AstInterface $ast Expression node
		 * @return string|null PHP-level type, or null when unknown
		 * @throws EntityResolutionException
		 */
		public function inferReturnType(AstInterface $ast): ?string {
			if ($ast instanceof AstIdentifier) {
				$definition = $ast->getType()->isRoutineReference() ? $this->routineReferenceDefinition($ast) : $this->columnDefinition($ast);

				if ($definition !== null) {
					return TypeMapper::phinxTypeToPhpType($definition['type']);
				}
			}

			return parent::inferReturnType($ast);
		}

		/**
		 * @param AstInterface $expression A target-list expression after the query pipeline
		 * @param string $cursorName Cursor name, for the error message
		 * @param string $field Field name, for the error message
		 * @return TypeDefinition
		 * @throws SemanticException|EntityResolutionException
		 */
		private function definitionOf(AstInterface $expression, string $cursorName, string $field): array {
			if ($expression instanceof AstIdentifier) {
				$definition = $expression->getType()->isRoutineReference()
					? $this->routineReferenceDefinition($expression)
					: $this->columnDefinition($expression);

				if ($definition !== null) {
					return $definition;
				}
			}

			$inferred = $this->inferReturnType($expression);

			if ($inferred === null || !isset(self::INFERRED_TYPES[$inferred])) {
				throw new SemanticException("The type of '{$cursorName}.{$field}' can't be determined, so it can't be fetched into a variable. Select a column, or cast the value, e.g. (int)x.");
			}

			return self::definition(self::INFERRED_TYPES[$inferred]);
		}

		/**
		 * @param AstIdentifier $identifier `range.property`, a direct column read
		 * @return TypeDefinition|null The column's declared type, or null when it isn't a direct column read
		 * @throws EntityResolutionException
		 */
		private function columnDefinition(AstIdentifier $identifier): ?array {
			$entityName = $identifier->getEntityName();
			$property = $identifier->getNext();

			if ($entityName === null || $property === null || $property->hasNext()) {
				return null;
			}

			$metadata = $this->store->getMetadata($entityName);
			$columnName = $metadata->getColumnName($property->getName());
			$column = $columnName === null ? null : ($metadata->columnDefinitions[$columnName] ?? null);

			if ($column === null) {
				return null;
			}

			return [
				'type'      => $column['type'],
				'limit'     => $column['limit'],
				'unsigned'  => $column['unsigned'],
				'precision' => $column['precision'],
				'scale'     => $column['scale'],
				'values'    => $column['values'],
			];
		}

		/**
		 * @param AstIdentifier $identifier Identifier typed RoutineVariable or CursorRoot
		 * @return TypeDefinition|null
		 */
		private function routineReferenceDefinition(AstIdentifier $identifier): ?array {
			$field = $identifier->getNext();

			if ($identifier->getType() === IdentifierType::CursorRoot && $field !== null) {
				return $this->cursorFields[$identifier->getName()][$field->getName()] ?? null;
			}

			return $this->variables[$identifier->getName()] ?? null;
		}

		/**
		 * @param string $type Normalized routine or column type
		 * @return TypeDefinition
		 */
		private static function definition(string $type): array {
			return ['type' => $type, 'limit' => null, 'unsigned' => false, 'precision' => null, 'scale' => null, 'values' => null];
		}
	}
