<?php
	
	/*
	 * ╔═══════════════════════════════════════════════════════════════════════════════════════╗
	 * ║                                                                                       ║
	 * ║   ██████╗ ██████╗      ██╗███████╗ ██████╗████████╗ ██████╗ ██╗   ██╗███████╗██╗      ║
	 * ║  ██╔═══██╗██╔══██╗     ██║██╔════╝██╔════╝╚══██╔══╝██╔═══██╗██║   ██║██╔════╝██║      ║
	 * ║  ██║   ██║██████╔╝     ██║█████╗  ██║        ██║   ██║   ██║██║   ██║█████╗  ██║      ║
	 * ║  ██║   ██║██╔══██╗██   ██║██╔══╝  ██║        ██║   ██║▄▄ ██║██║   ██║██╔══╝  ██║      ║
	 * ║  ╚██████╔╝██████╔╝╚█████╔╝███████╗╚██████╗   ██║   ╚██████╔╝╚██████╔╝███████╗███████╗ ║
	 * ║   ╚═════╝ ╚═════╝  ╚════╝ ╚══════╝ ╚═════╝   ╚═╝    ╚══▀▀═╝  ╚═════╝ ╚══════╝╚══════╝ ║
	 * ║                                                                                       ║
	 * ║  ObjectQuel - Powerful Object-Relational Mapping built on the Data Mapper pattern     ║
	 * ║                                                                                       ║
	 * ║  Clean separation between entities and persistence logic with an intuitive,           ║
	 * ║  object-oriented query language. Powered by CakePHP's robust database foundation.     ║
	 * ║                                                                                       ║
	 * ╚═══════════════════════════════════════════════════════════════════════════════════════╝
	 */
	
	namespace Quellabs\ObjectQuel\Metadata;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Collection\AnnotationCollection;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKeyAction;
	use Quellabs\ObjectQuel\Annotations\Orm\FullTextIndex;
	use Quellabs\ObjectQuel\Annotations\Orm\Immutable;
	use Quellabs\ObjectQuel\Annotations\Orm\Index;
	use Quellabs\ObjectQuel\Annotations\Orm\InverseOf;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\UniqueIndex;
	use Quellabs\ObjectQuel\Annotations\Orm\Version;
	use Quellabs\ObjectQuel\DatabaseAdapter\ColumnDefinition;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\TypeMapper;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	
	/**
	 * Immutable value object containing all metadata for a single entity.
	 *
	 * This class consolidates all entity metadata that was previously scattered across
	 * multiple cache arrays in EntityStore. It is built once per entity and cached
	 * by EntityStore, eliminating the need for multiple cache lookups and providing
	 * a single, type-safe interface for accessing entity information.
	 *
	 * All properties are readonly to ensure immutability and prevent accidental
	 * modification of cached metadata.
	 *
	 * @phpstan-import-type ColumnDefinitionRecord from EntityMetadataBuilder
	 */
	readonly class EntityMetadataRecord {
		
		/**
		 * Constructor for EntityMetadataRecord.
		 * @param class-string $className Fully qualified, normalized class name
		 * @param string $tableName Database table name from @Table annotation
		 * @param array<int, string> $properties Property names
		 * @param array<string, AnnotationCollection> $annotations Property name => annotation collection mapping
		 * @param array<string, string> $columnMap Property name => column name mapping
		 * @param array<string, Column> $columnAnnotations Property name => its @Orm\Column
		 *        annotation, for every property that carries one — see
		 *        getColumnAnnotation(), the accessor this backs.
		 * @param array<string> $identifierKeys Property names that serve as primary keys
		 * @param array<string> $identifierColumns Column names that serve as primary keys
		 * @param array<string, array{name: string, column: Column, version: Version}> $versionColumns Properties with version tracking
		 * @param array<string, ManyToOne> $manyToOneRelations Property => ManyToOne annotation mapping
		 * @param array<string, InverseOf> $inverseOfRelations Property => InverseOf annotation mapping
		 * @param array<string, OneToOne> $oneToOneRelations Property => OneToOne annotation mapping
		 * @param array<string, RelationColumnMapping> $relationColumnMappings Property => resolved
		 *        column pairs for every owning ManyToOne/OneToOne relation on this entity,
		 *        normalizing both the singular and plural annotation forms into one shape
		 * @param array<Index|UniqueIndex|FullTextIndex> $indexes Index annotations from class level
		 * @param string|null $autoIncrementColumn Property name of auto-increment primary key (if any)
		 * @param array<string, ColumnDefinitionRecord> $columnDefinitions
		 * @param string|null $softDeleteProperty Property name carrying the @SoftDelete annotation, or null
		 * @param string|null $softDeleteColumn Database column name of the soft-delete field, or null
		 * @param string|null $softDeleteColumnType Column type of the soft-delete field ('datetime', 'boolean', etc.), or null
		 * @param array<string, ForeignKey> $foreignKeys Database column name => ForeignKey annotation
		 * @param array<string, ForeignKeyAction> $foreignKeyActions Database column name => ForeignKeyAction annotation
		 * @param bool $isEntityBridge True when the class carries the class-level @Orm\EntityBridge marker
		 */
		public function __construct(
			public string $className,
			public string $tableName,
			public array $properties,
			public array $annotations,
			public array $columnMap,
			public array $columnAnnotations,
			public array $identifierKeys,
			public array $identifierColumns,
			public array $versionColumns,
			public array $manyToOneRelations,
			public array $inverseOfRelations,
			public array $oneToOneRelations,
			public array $relationColumnMappings,
			public array $indexes,
			public ?string $autoIncrementColumn,
			public array $columnDefinitions,
			public ?string $softDeleteProperty = null,
			public ?string $softDeleteColumn = null,
			public ?string $softDeleteColumnType = null,
			public array $foreignKeys = [],
			public array $foreignKeyActions = [],
			public bool $isEntityBridge = false,
		) {
		}
		
		/**
		 * Retrieves the primary key of the entity.
		 * For composite primary keys, returns the first key.
		 * @return string|null The primary key property name, or null if no primary key exists
		 */
		public function getPrimaryKey(): ?string {
			return $this->identifierKeys[0] ?? null;
		}
		
		/**
		 * Validates a primary-key lookup value and returns it as an associative map,
		 * keyed by property name in identifierKeys order. A scalar is accepted only
		 * when the entity has exactly one identifier property. An array must name
		 * every identifier property exactly once — no unknown names, no missing
		 * components, no nulls, and no non-scalar values (array order in the input
		 * does not matter; the result is always re-ordered to identifierKeys order).
		 * @param int|string|array<string, mixed> $key
		 * @return array<string, int|string> Property name => value, in identifierKeys order
		 * @throws \InvalidArgumentException When the key is incomplete, unknown, or malformed
		 */
		public function normalizeIdentifier(int|string|array $key): array {
			if ($this->identifierKeys === []) {
				throw new \InvalidArgumentException("Entity '{$this->className}' has no primary key defined.");
			}

			if (!is_array($key)) {
				if (count($this->identifierKeys) !== 1) {
					throw new \InvalidArgumentException(
						"Entity '{$this->className}' has a composite primary key (" . implode(', ', $this->identifierKeys) .
						"); a scalar identifier is not valid. Pass an associative array keyed by property name."
					);
				}

				$key = [$this->identifierKeys[0] => $key];
			}

			$unknown = array_diff(array_keys($key), $this->identifierKeys);

			if ($unknown !== []) {
				throw new \InvalidArgumentException(
					"Identifier for '{$this->className}' contains unknown propert" . (count($unknown) === 1 ? 'y' : 'ies') .
					" (" . implode(', ', $unknown) . "). Expected: " . implode(', ', $this->identifierKeys) . "."
				);
			}

			$missing = array_diff($this->identifierKeys, array_keys($key));

			if ($missing !== []) {
				throw new \InvalidArgumentException(
					"Identifier for '{$this->className}' is missing propert" . (count($missing) === 1 ? 'y' : 'ies') .
					" (" . implode(', ', $missing) . "). A primary-key lookup must name every identifier property exactly once."
				);
			}

			$result = [];

			foreach ($this->identifierKeys as $property) {
				$value = $key[$property];

				if (!is_int($value) && !is_string($value)) {
					throw new \InvalidArgumentException(
						"Identifier property '{$property}' on '{$this->className}' must be an int or string, got " .
						get_debug_type($value) . "."
					);
				}

				$result[$property] = $value;
			}

			return $result;
		}

		/**
		 * Casts each value of an already-normalized identifier map to the PHP type
		 * implied by its column's mapped type, so values denoting the same identity
		 * in different PHP representations — e.g. int(0) and "0" for an integer
		 * column — collapse to one canonical value before being used as (or encoded
		 * into) an identity-map key. A column type outside int/string (the public
		 * lookup contract) is passed through unchanged.
		 * @param array<string, int|string> $normalizedKey Result of normalizeIdentifier()
		 * @return array<string, int|string>
		 * @throws \InvalidArgumentException When an integer-column value isn't a valid integer representation
		 */
		public function canonicalizeIdentifier(array $normalizedKey): array {
			$result = [];

			foreach ($this->identifierKeys as $property) {
				$value = $normalizedKey[$property];
				$column = $this->getColumnAnnotation($property);
				$phpType = $column !== null ? TypeMapper::phinxTypeToPhpType(TypeMapper::normalizeType($column->getType())) : null;

				$result[$property] = match ($phpType) {
					'int' => $this->canonicalizeIntegerIdentifier($property, $value),
					'string' => (string) $value,
					default => $value,
				};
			}

			return $result;
		}

		/**
		 * @param string $property
		 * @param int|string $value
		 * @return int
		 * @throws \InvalidArgumentException
		 */
		private function canonicalizeIntegerIdentifier(string $property, int|string $value): int {
			if (is_int($value)) {
				return $value;
			}

			if (!preg_match('/^-?\d+$/', $value)) {
				throw new \InvalidArgumentException(
					"Identifier property '{$property}' on '{$this->className}' is an integer column; " .
					"'{$value}' is not a valid integer representation."
				);
			}

			return (int) $value;
		}

		/**
		 * Encodes an already-normalized, type-canonicalized identifier map into one
		 * collision-safe string, for use as a Unit of Work / identity-map index key.
		 * Each component is written as a length-prefixed property name, a one-letter
		 * type tag ('i' for int, 's' for string), and a length-prefixed value — a
		 * netstring-style format where every segment announces its own length, so
		 * concatenating several can never be parsed back ambiguously regardless of
		 * what characters the names or values themselves contain. This replaces
		 * delimiter-based joining (e.g. http_build_query()), which can collide when a
		 * value itself contains the delimiter or coerces '0'/''/numeric strings.
		 * @param array<string, int|string> $canonicalKey Result of canonicalizeIdentifier()
		 * @return string
		 */
		public function encodeIdentity(array $canonicalKey): string {
			$segments = [];

			foreach ($this->identifierKeys as $property) {
				$value = $canonicalKey[$property];
				$typeTag = is_int($value) ? 'i' : 's';
				$valueStr = (string) $value;
				$segments[] = strlen($property) . ':' . $property . $typeTag . strlen($valueStr) . ':' . $valueStr;
			}

			return implode('|', $segments);
		}

		/**
		 * Validates, canonicalizes, and encodes a primary-key lookup value in one
		 * call — the combination every caller needing a Unit of Work / identity-map
		 * key actually wants.
		 * @param int|string|array<string, mixed> $key
		 * @return string
		 * @throws \InvalidArgumentException When the key is incomplete, unknown, or malformed
		 */
		public function resolveIdentityKey(int|string|array $key): string {
			return $this->encodeIdentity($this->canonicalizeIdentifier($this->normalizeIdentifier($key)));
		}

		/**
		 * Check if this entity has an auto-increment primary key.
		 * An auto-increment key is one that is automatically generated by the database.
		 * @return bool True if the entity has an auto-increment primary key, false otherwise
		 */
		public function hasAutoIncrementPrimaryKey(): bool {
			return $this->autoIncrementColumn !== null;
		}

		/**
		 * Returns the @PrimaryKeyStrategy value of a primary key property; 'identity' when none is declared.
		 * @param string $primaryKey Primary key property name
		 * @return string
		 */
		public function getPrimaryKeyStrategy(string $primaryKey): string {
			foreach ($this->getAnnotations()[$primaryKey] ?? [] as $annotation) {
				if ($annotation instanceof PrimaryKeyStrategy) {
					return $annotation->getValue();
				}
			}

			return 'identity';
		}
		
		/**
		 * Retrieve the ManyToOne dependencies for this entity.
		 * These represent entities that this entity has a foreign key reference to.
		 * @return array<string, ManyToOne> Array of ManyToOne annotations
		 */
		public function getManyToOneDependencies(): array {
			return $this->manyToOneRelations;
		}
		
		/**
		 * Retrieve the InverseOf declarations for this entity.
		 * These are hydration targets — properties that should receive collections of
		 * dependent entity objects when this entity is loaded. They do not define
		 * relationships; the relationship is always owned by the dependent entity
		 * via its ManyToOne or OneToOne annotation.
		 * @return array<string, InverseOf> Property name => InverseOf annotation mapping
		 */
		public function getInverseOfDependencies(): array {
			return $this->inverseOfRelations;
		}
		
		/**
		 * Retrieve the resolved column-pair mapping for an owning relation property,
		 * normalizing both the singular (localColumn/referencedColumn) and plural
		 * (localColumns/referencedColumns) annotation forms into the same ordered
		 * pair list. This is the only column-mapping input relation code should
		 * consume for $property.
		 * @param string $property The ManyToOne/OneToOne relation property name
		 * @return RelationColumnMapping|null Null when $property carries no owning relation
		 */
		public function getRelationColumnMapping(string $property): ?RelationColumnMapping {
			return $this->relationColumnMappings[$property] ?? null;
		}

		/**
		 * Retrieve the OneToOne dependencies for this entity.
		 * All stored OneToOne relations are owning-side by definition — non-owning
		 * OneToOne declarations are represented as InverseOf annotations instead.
		 * @return array<string, OneToOne> Array of OneToOne annotations
		 */
		public function getOneToOneDependencies(): array {
			return $this->oneToOneRelations;
		}
		
		/**
		 * Obtains the database column name for a given property.
		 * @param string $property The entity property name
		 * @return string|null The corresponding column name, or null if property doesn't have a column mapping
		 */
		public function getColumnName(string $property): ?string {
			return $this->columnMap[$property] ?? null;
		}

		/**
		 * Same as getColumnName(), for call sites that already validated
		 * (typically via AssignmentValidator::assertPropertiesExist() or
		 * ConflictTargetResolver) that $property exists on this entity, where
		 * a null result would mean that validation was skipped — a genuine
		 * bug, not a case to handle.
		 * @param string $property The entity property name
		 * @return string The corresponding column name
		 */
		public function getColumnNameOrFail(string $property): string {
			$columnName = $this->getColumnName($property);

			if ($columnName === null) {
				throw new \LogicException("Property '{$property}' does not exist in entity '{$this->className}'");
			}

			return $columnName;
		}

		/**
		 * Obtains the entity property name for a given database column.
		 * @param string $columnName The database column name
		 * @return string|null The corresponding property name, or null if column doesn't map to a property
		 */
		public function getPropertyName(string $columnName): ?string {
			$flipped = array_flip($this->columnMap);
			return $flipped[$columnName] ?? null;
		}

		/**
		 * Returns the @Orm\Column annotation for a property, or null when the
		 * property carries none (a non-persisted property, or a
		 * relation-only property like @ManyToOne). Plain array lookup against
		 * $columnAnnotations, computed once by EntityMetadataBuilder's single
		 * annotation pass — this record is a value object, not a place to
		 * re-scan AnnotationCollection at call time.
		 *
		 * Serializer::normalizeValue()/denormalizeValue() both take a Column
		 * annotation directly, so every caller resolving a value for a
		 * specific property (EntityHydrator, VersionValueHandler,
		 * WriteVerbParameterNormalizer) needs to get one from somewhere —
		 * this is the single place they all get it from, instead of each
		 * re-deriving it.
		 * @param string $property The entity property name
		 * @return Column|null
		 */
		public function getColumnAnnotation(string $property): ?Column {
			return $this->columnAnnotations[$property] ?? null;
		}

		/**
		 * Checks if a property is part of the entity's primary key.
		 * @param string $property The property name to check
		 * @return bool True if the property is a primary key, false otherwise
		 */
		public function isIdentifierKey(string $property): bool {
			return in_array($property, $this->identifierKeys, true);
		}
		
		/**
		 * Checks if a property has version tracking enabled.
		 * Version tracking is used for optimistic locking.
		 * @param string $property The property name to check
		 * @return bool True if the property has version tracking, false otherwise
		 */
		public function isVersioned(string $property): bool {
			return isset($this->versionColumns[$property]);
		}
		
		/**
		 * Returns column definitions in the shape expected by the Sculpt subsystem (ColumnDefinition).
		 * Converts ORM-internal metadata to the database-comparable format used by SchemaComparator.
		 * @return array<string, ColumnDefinition>
		 */
		public function getColumnDefinitionsForSchema(): array {
			$result = [];

			/** @noinspection PhpLoopCanBeConvertedToArrayMapInspection */
			foreach ($this->columnDefinitions as $columnName => $def) {
				$result[$columnName] = new ColumnDefinition(
					type: $def['type'],
					php_type: $def['php_type'] instanceof \ReflectionNamedType ? $def['php_type']->getName() : 'mixed',
					limit: is_int($def['limit']) ? $def['limit'] : null,
					default: $def['default'],
					nullable: $def['nullable'],
					precision: is_int($def['precision']) ? $def['precision'] : null,
					scale: is_int($def['scale']) ? $def['scale'] : null,
					unsigned: $def['unsigned'],
					generated: null,
					identity: $def['identity'],
					primary_key: $def['primary_key'],
					values: is_array($def['values']) ? $def['values'] : null,
				);
			}

			return $result;
		}
		
		/**
		 * Finds a FullTextIndex annotation that covers all the given property names.
		 *
		 * Used by the SQL generator to decide whether search() and search_score() can
		 * use MATCH...AGAINST instead of LIKE chains. A full-text index matches when its
		 * column list is identical to (or a superset of) the requested property names.
		 *
		 * Note: the columns defined on FullTextIndex annotations are property names,
		 * not database column names. This method compares at the property level.
		 *
		 * @param array<int, string> $propertyNames The property names passed to search() or search_score()
		 * @return FullTextIndex|null The matching index, or null if none covers all columns
		 */
		public function getFullTextIndexForColumns(array $propertyNames): ?FullTextIndex {
			foreach ($this->indexes as $index) {
				if (!$index instanceof FullTextIndex) {
					continue;
				}
				
				// All requested property names must be present in this index's column list
				$missingColumns = array_diff($propertyNames, $index->getColumns());
				
				if (empty($missingColumns)) {
					return $index;
				}
			}
			
			return null;
		}
		
		
		/**
		 * Returns all annotations grouped by property.
		 * @return array<string, array<int, AnnotationInterface>>
		 */
		public function getAnnotations(): array {
			$result = [];
			
			foreach ($this->annotations as $property => $collection) {
				foreach ($collection->ofType(AnnotationInterface::class) as $annotation) {
					$result[$property][] = $annotation;
				}
			}
			
			return $result;
		}
		
		/**
		 * Returns annotations filtered by a specific type.
		 * @template T of AnnotationInterface
		 * @param class-string<T> $annotationType
		 * @return array<string, array<int, T>>
		 */
		public function getAnnotationsOfType(string $annotationType): array {
			$result = [];
			
			foreach ($this->annotations as $property => $annotationCollection) {
				foreach ($annotationCollection->ofType($annotationType) as $annotation) {
					$result[$property][] = $annotation;
				}
			}
			
			return $result;
		}
		
		/**
		 * Return true if the entity is immutable (readonly), false if not.
		 * An immutable entity is marked with the @Immutable annotation.
		 * @return bool True if the entity is immutable, false otherwise
		 */
		public function isImmutable(): bool {
			$annotationList = $this->getAnnotationsOfType(Immutable::class);
			return !empty($annotationList);
		}
		
		/**
		 * Returns true if this entity has a soft-delete column.
		 * @return bool
		 */
		public function hasSoftDelete(): bool {
			return $this->softDeleteProperty !== null;
		}

		/**
		 * Retrieve the ForeignKey annotation declared for a database column, if any.
		 * @param string $columnName Database column name
		 * @return ForeignKey|null
		 */
		public function getForeignKeyForColumn(string $columnName): ?ForeignKey {
			return $this->foreignKeys[$columnName] ?? null;
		}

		/**
		 * Retrieve the ForeignKeyAction annotation declared for a database column,
		 * if any. Absence means the safe defaults (RESTRICT / NO ACTION) apply.
		 * @param string $columnName Database column name
		 * @return ForeignKeyAction|null
		 */
		public function getForeignKeyActionForColumn(string $columnName): ?ForeignKeyAction {
			return $this->foreignKeyActions[$columnName] ?? null;
		}

		/**
		 * Returns true if this entity carries the class-level @Orm\EntityBridge marker.
		 * @return bool
		 */
		public function isEntityBridge(): bool {
			return $this->isEntityBridge;
		}
	}