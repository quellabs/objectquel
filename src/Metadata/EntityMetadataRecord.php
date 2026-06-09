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
	use Quellabs\ObjectQuel\Annotations\Orm\FullTextIndex;
	use Quellabs\ObjectQuel\Annotations\Orm\Immutable;
	use Quellabs\ObjectQuel\Annotations\Orm\Index;
	use Quellabs\ObjectQuel\Annotations\Orm\InverseOf;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\UniqueIndex;
	use Quellabs\ObjectQuel\Annotations\Orm\Version;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
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
	 * @phpstan-import-type ColumnDefinition from DatabaseAdapter
	 * @phpstan-import-type ColumnDefinitionRecord from EntityMetadataBuilder
	 */
	readonly class EntityMetadataRecord {
		
		/**
		 * Constructor for EntityMetadataRecord.
		 * @param string $className Fully qualified, normalized class name
		 * @param string $tableName Database table name from @Table annotation
		 * @param array<int, string> $properties Property names
		 * @param array<string, AnnotationCollection> $annotations Property name => annotation collection mapping
		 * @param array<string, string> $columnMap Property name => column name mapping
		 * @param array<string> $identifierKeys Property names that serve as primary keys
		 * @param array<string> $identifierColumns Column names that serve as primary keys
		 * @param array<string, array{name: string, column: Column, version: Version}> $versionColumns Properties with version tracking
		 * @param array<string, ManyToOne> $manyToOneRelations Property => ManyToOne annotation mapping
		 * @param array<string, InverseOf> $inverseOfRelations Property => InverseOf annotation mapping
		 * @param array<string, OneToOne> $oneToOneRelations Property => OneToOne annotation mapping
		 * @param array<Index|UniqueIndex|FullTextIndex> $indexes Index annotations from class level
		 * @param string|null $autoIncrementColumn Property name of auto-increment primary key (if any)
		 * @param array<string, ColumnDefinitionRecord> $columnDefinitions
		 * @param string|null $softDeleteProperty Property name carrying the @SoftDelete annotation, or null
		 * @param string|null $softDeleteColumn Database column name of the soft-delete field, or null
		 * @param string|null $softDeleteColumnType Column type of the soft-delete field ('datetime', 'boolean', etc.), or null
		 */
		public function __construct(
			public string $className,
			public string $tableName,
			public array $properties,
			public array $annotations,
			public array $columnMap,
			public array $identifierKeys,
			public array $identifierColumns,
			public array $versionColumns,
			public array $manyToOneRelations,
			public array $inverseOfRelations,
			public array $oneToOneRelations,
			public array $indexes,
			public ?string $autoIncrementColumn,
			public array $columnDefinitions,
			public ?string $softDeleteProperty = null,
			public ?string $softDeleteColumn = null,
			public ?string $softDeleteColumnType = null,
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
		 * Check if this entity has an auto-increment primary key.
		 * An auto-increment key is one that is automatically generated by the database.
		 * @return bool True if the entity has an auto-increment primary key, false otherwise
		 */
		public function hasAutoIncrementPrimaryKey(): bool {
			return $this->autoIncrementColumn !== null;
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
		 * Obtains the entity property name for a given database column.
		 * @param string $columnName The database column name
		 * @return string|null The corresponding property name, or null if column doesn't map to a property
		 */
		public function getPropertyName(string $columnName): ?string {
			$flipped = array_flip($this->columnMap);
			return $flipped[$columnName] ?? null;
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
				$result[$columnName] = [
					'type'        => $def['type'],
					'php_type'    => $def['php_type'] instanceof \ReflectionNamedType ? $def['php_type']->getName() : 'mixed',
					'limit'       => is_int($def['limit']) ? $def['limit'] : null,
					'default'     => $def['default'],
					'nullable'    => $def['nullable'],
					'precision'   => is_int($def['precision']) ? $def['precision'] : null,
					'scale'       => is_int($def['scale']) ? $def['scale'] : null,
					'unsigned'    => $def['unsigned'],
					'generated'   => null,
					'identity'    => $def['identity'],
					'primary_key' => $def['primary_key'],
					'values'      => is_array($def['values']) ? $def['values'] : null,
				];
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
	}