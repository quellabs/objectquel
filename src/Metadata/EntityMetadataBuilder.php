<?php
	
	namespace Quellabs\ObjectQuel\Metadata;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\AnnotationReader\Collection\AnnotationCollection;
	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
	use Quellabs\AnnotationReader\Exception\ParserException;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\FullTextIndex;
	use Quellabs\ObjectQuel\Annotations\Orm\Index;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\InverseOf;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\UniqueIndex;
	use Quellabs\ObjectQuel\Annotations\Orm\Version;
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	use Quellabs\ObjectQuel\ReflectionManagement\ReflectionHandler;
	use Quellabs\Support\NamespaceResolver;
	
	/**
	 * Builds EntityMetadata objects from class annotations and reflection.
	 *
	 * Owns the full pipeline: annotation reading, normalization, and assembly.
	 * EntityStore delegates all metadata construction here and only handles
	 * caching and registry concerns.
	 *
	 * @phpstan-type ColumnDefinitionRecord array{
	 *        property_name: string,
	 *        type: string,
	 *        php_type: \ReflectionType|null,
	 *        limit: int|array<int, int>|null,
	 *        nullable: bool,
	 *        unsigned: bool,
	 *        default: mixed,
	 *        primary_key: bool,
	 *        scale: int|null,
	 *        precision: int|null,
	 *        identity: bool,
	 *        values: array<int, string>|null
	 *  }
	 */
	class EntityMetadataBuilder {
		
		private readonly AnnotationReader $annotationReader;
		private readonly ReflectionHandler $reflectionHandler;
		private EntityStore $entityStore;
		
		/**
		 * EntityMetadataBuilder constructor
		 * @param AnnotationReader $annotationReader
		 * @param ReflectionHandler $reflectionHandler
		 */
		public function __construct(
			EntityStore $entityStore,
			AnnotationReader $annotationReader,
			ReflectionHandler $reflectionHandler
		) {
			$this->entityStore = $entityStore;
			$this->reflectionHandler = $reflectionHandler;
			$this->annotationReader = $annotationReader;
		}
		
		/**
		 * Build complete EntityMetadata for a given class.
		 * @param class-string $className Fully qualified, normalized entity class name
		 * @return EntityMetadataRecord
		 * @throws \RuntimeException If metadata extraction fails
		 */
		public function build(string $className): EntityMetadataRecord {
			try {
				// Fetch class-level annotations to extract the @Table name
				$classAnnotations = $this->annotationReader->getClassAnnotations($className);
				
				// Extract table name
				$tableAnnotation = $classAnnotations->getFirst(\Quellabs\ObjectQuel\Annotations\Orm\Table::class);
				
				if (!$tableAnnotation instanceof \Quellabs\ObjectQuel\Annotations\Orm\Table) {
					throw new \RuntimeException("Missing @Table annotation on {$className}");
				}
				
				$tableName = $tableAnnotation->getName();
				
				// Get the list of declared properties via reflection
				$properties = $this->reflectionHandler->getProperties($className);
				
				// Read every property's annotations up front into a single map.
				// All subsequent extraction steps consume this map rather than
				// hitting the annotation reader again, keeping I/O to one pass.
				$annotations = [];
				foreach ($properties as $property) {
					$annotations[$property] = $this->annotationReader->getPropertyAnnotations($className, $property);
				}
				
				// Derive all column-related metadata in a single pass over $annotations.
				// Splitting this into five separate loops would re-iterate the same data
				// for each concern; one pass is both faster and easier to follow.
				[
					$columnMap,
					$identifierKeys,
					$identifierColumns,
					$versionColumns,
					$autoIncrementColumn,
				] = $this->extractColumnData($annotations);
				
				return new EntityMetadataRecord(
					className: $className,
					tableName: $tableName,
					properties: $properties,
					annotations: $annotations,
					columnMap: $columnMap,
					identifierKeys: $identifierKeys,
					identifierColumns: $identifierColumns,
					versionColumns: $versionColumns,
					manyToOneRelations: $this->extractRelations($annotations, ManyToOne::class),
					inverseOfRelations: $this->extractRelations($annotations, InverseOf::class),
					oneToOneRelations: $this->extractRelations($annotations, OneToOne::class),
					indexes: $this->extractIndexes($className),
					autoIncrementColumn: $autoIncrementColumn,
					columnDefinitions: $this->extractColumnDefinitions($className, $annotations),
				);
			} catch (\Exception $e) {
				throw new \RuntimeException("Failed to build metadata for {$className}: " . $e->getMessage(), 0, $e);
			}
		}
		
		// ==================== Private Helpers ====================
		
		/**
		 * Single pass over property annotations to extract all column-related metadata.
		 * Returns [columnMap, identifierKeys, identifierColumns, versionColumns, autoIncrementColumn].
		 * @param array<string, AnnotationCollection> $annotations
		 * @return array{
		 *     0: array<string, string>,
		 *     1: list<string>,
		 *     2: list<string>,
		 *     3: array<string, array{
		 *         name: string,
		 *         column: Column,
		 *         version: Version
		 *     }>,
		 *     4: string|null
		 *  }
		 */
		private function extractColumnData(array $annotations): array {
			$columnMap = [];
			$identifierKeys = [];
			$identifierColumns = [];
			$versionColumns = [];
			$autoIncrementColumn = null;
			
			foreach ($annotations as $property => $annotationCollection) {
				// Collect the three annotation types we care about for this property.
				// Using nulls means we can cheaply test presence below without array_filter.
				$column = null;
				$version = null;
				$strategy = null;
				
				foreach ($annotationCollection as $annotation) {
					if ($annotation instanceof Column) {
						$column = $annotation;
					} elseif ($annotation instanceof Version) {
						$version = $annotation;
					} elseif ($annotation instanceof PrimaryKeyStrategy) {
						$strategy = $annotation;
					}
				}
				
				// Properties without @Column have no database representation — skip them
				if ($column === null) {
					continue;
				}
				
				// Map property name → database column name for query building
				$columnName = $column->getName();
				$columnMap[$property] = $columnName;
				
				if ($column->isPrimaryKey()) {
					$identifierKeys[] = $property;   // PHP property name of the PK
					$identifierColumns[] = $columnName; // Database column name of the PK
					
					// Only record the first PK we find as the auto-increment column.
					// A composite PK can't be auto-increment, so subsequent PKs are skipped.
					if ($autoIncrementColumn === null) {
						// Auto-increment when: strategy is explicitly 'identity', OR
						// no strategy annotation is present at all (implicit default)
						$isIdentity = $strategy === null || $strategy->getValue() === 'identity';
						if ($isIdentity) {
							$autoIncrementColumn = $property;
						}
					}
				}
				
				// Version columns require both @Column and @Version to be present.
				// @Version alone (without a backing column) is invalid and ignored.
				if ($version !== null) {
					$versionColumns[$property] = [
						'name'    => $columnName,
						'column'  => $column,
						'version' => $version,
					];
				}
			}
			
			return [$columnMap, $identifierKeys, $identifierColumns, $versionColumns, $autoIncrementColumn];
		}
		
		/**
		 * Extract relationship annotations of a specific type from property annotations.
		 * @template T of ManyToOne|InverseOf|OneToOne
		 * @param array<string, AnnotationCollection> $annotations
		 * @param class-string<T> $annotationType
		 * @return array<string, T>
		 * @throws EntityResolutionException
		 */
		private function extractRelations(array $annotations, string $annotationType): array {
			$relations = [];
			
			foreach ($annotations as $property => $annotationCollection) {
				foreach ($annotationCollection as $annotation) {
					if ($annotation instanceof $annotationType) {
						/**
						 * Normalize the target entity name so it's always fully qualified.
						 * Annotations may contain short names (e.g. "Order") that need
						 * expanding to the full namespace before they can be used elsewhere.
						 */
						$annotation->setTargetEntity(
							$this->entityStore->normalizeEntityClass($annotation->getTargetEntity())
						);
						
						// One relation annotation per property — the first match wins
						$relations[$property] = $annotation;
						break;
					}
				}
			}
			
			return $relations;
		}
		
		/**
		 * Extract index annotations from class-level annotations.
		 * @param class-string $className The fully qualified class name
		 * @return array<int, Index|UniqueIndex|FullTextIndex> Array of Index, UniqueIndex and FullTextIndex annotation objects
		 * @throws AnnotationReaderException
		 */
		private function extractIndexes(string $className): array {
			try {
				// Extract class annotations
				$classAnnotations = $this->annotationReader->getClassAnnotations($className);
				
				// Index annotations live at the class level, not on individual properties,
				// so we filter the class annotation collection rather than the property map
				$result = $classAnnotations->filter(function ($annotation) {
					return $annotation instanceof Index
						|| $annotation instanceof UniqueIndex
						|| $annotation instanceof FullTextIndex;
				})->toArray();
				
				/** @var array<int, Index|UniqueIndex|FullTextIndex> $result */
				return $result;
			} catch (ParserException $e) {
				// A parse failure here shouldn't block entity registration —
				// return empty rather than propagating, the schema tool will catch it
				return [];
			}
		}
		
		/**
		 * Extracts column definitions for all mapped properties of the given class.
		 *
		 * Iterates over the class's properties via reflection, filters out any that
		 * lack a {@see Column} annotation or have a misconfigured (empty) column name,
		 * and delegates the actual definition shape to {@see buildColumnDefinition}.
		 *
		 * @param class-string $className Fully qualified name of the entity class to inspect.
		 * @param array<string, AnnotationCollection> $annotations Pre-extracted annotations keyed by property name.
		 * @return array<string, ColumnDefinitionRecord> Column definitions keyed by column name.
		 * @throws AnnotationReaderException If annotation reading fails for any property.
		 * @throws \ReflectionException      If the class does not exist or cannot be reflected.
		 */
		private function extractColumnDefinitions(string $className, array $annotations): array {
			$definitions = [];
			$reflection = new \ReflectionClass($className);
			
			foreach ($reflection->getProperties() as $property) {
				$propertyAnnotations = $this->annotationReader->getPropertyAnnotations(
					$className,
					$property->getName(),
					Column::class
				);
				
				$columnAnnotation = $propertyAnnotations->getFirst(Column::class);
				
				if (!$columnAnnotation instanceof Column || empty($columnAnnotation->getName())) {
					continue;
				}
				
				$columnName = $columnAnnotation->getName();
				$definitions[$columnName] = $this->buildColumnDefinition($columnAnnotation, $property, $propertyAnnotations);
			}
			
			return $definitions;
		}
		
		/**
		 * Builds the definition array for a single mapped column.
		 *
		 * Combines metadata from the {@see Column} annotation, the PHP reflection of
		 * the property, and the full annotation collection on that property. Type
		 * defaults and enum cases are resolved via {@see TypeMapper}.
		 *
		 * @param Column $column The column annotation carrying mapping metadata.
		 * @param \ReflectionProperty $property The reflected property this column maps to.
		 * @param AnnotationCollection $annotations All annotations on the property, used to determine identity columns.
		 * @return ColumnDefinitionRecord
		 */
		private function buildColumnDefinition(Column $column, \ReflectionProperty $property, AnnotationCollection $annotations): array {
			$columnType = $column->getType();
			
			return [
				'property_name' => $property->getName(),
				'type'          => $columnType,
				'php_type'      => $property->getType(),
				'limit'         => $column->getLimit() ?? TypeMapper::getDefaultLimit($columnType),
				'nullable'      => $column->isNullable(),
				'unsigned'      => $column->isUnsigned(),
				'default'       => $column->getDefault(),
				'primary_key'   => $column->isPrimaryKey(),
				'scale'         => $column->getScale(),
				'precision'     => $column->getPrecision(),
				'identity'      => $this->isIdentityColumn($annotations->toArray()),
				'values'        => TypeMapper::getEnumCases($column->getEnumType()),
			];
		}
		
		/**
		 * Determines if a property represents an auto-increment column.
		 * True when: primary key AND (strategy = 'identity' OR no strategy defined).
		 * @param array<int|string, object> $propertyAnnotations $propertyAnnotations The annotations attached to the property
		 * @return bool
		 */
		private function isIdentityColumn(array $propertyAnnotations): bool {
			$isPrimaryKey = false;
			$hasStrategy = false;
			$isIdentityStrategy = false;
			
			foreach ($propertyAnnotations as $annotation) {
				if ($annotation instanceof Column && $annotation->isPrimaryKey()) {
					$isPrimaryKey = true;
				}
				
				if ($annotation instanceof PrimaryKeyStrategy) {
					$hasStrategy = true;
					
					// 'identity' maps to AUTO_INCREMENT / SERIAL in the database
					if ($annotation->getValue() === 'identity') {
						$isIdentityStrategy = true;
					}
				}
			}
			
			// Must be a primary key, and either explicitly marked as identity
			// or left without any strategy (which defaults to auto-increment)
			return $isPrimaryKey && ($isIdentityStrategy || !$hasStrategy);
		}
	}