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
	use Quellabs\ObjectQuel\Annotations\Orm\SoftDelete;
	use Quellabs\ObjectQuel\Annotations\Orm\UniqueIndex;
	use Quellabs\ObjectQuel\Annotations\Orm\Version;
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	use Quellabs\ObjectQuel\Metadata\ColumnData;
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
				$columnData = $this->extractColumnData($annotations);
				
				// Extract the relations and indexes
				$indexes = $this->extractIndexes($className);
				$columnDefinitions = $this->extractColumnDefinitions($className, $annotations);
				$manyToOneRelations = $this->extractRelations($annotations, ManyToOne::class);
				$oneToOneRelations = $this->extractRelations($annotations, OneToOne::class);
				$inverseOfRelations = $this->extractRelations($annotations, InverseOf::class);
				
				// Validate that every localColumn declared on a ManyToOne or OneToOne
				// has a corresponding @Orm\Column property on this entity. Catching this
				// at metadata build time gives a clear error instead of a silent hydration failure.
				$this->validateRelationColumns($className, $annotations, $manyToOneRelations, $oneToOneRelations);
				$this->validateSingleRelationPerProperty($className, $manyToOneRelations, $oneToOneRelations, $inverseOfRelations);
				$this->validateInverseOfPropertyTypes($className, $inverseOfRelations);
				
				// Return  a new EntityMetadataRecord containing all relation data
				return new EntityMetadataRecord(
					className: $className,
					tableName: $tableName,
					properties: $properties,
					annotations: $annotations,
					columnMap: $columnData->columnMap,
					identifierKeys: $columnData->identifierKeys,
					identifierColumns: $columnData->identifierColumns,
					versionColumns: $columnData->versionColumns,
					manyToOneRelations: $manyToOneRelations,
					inverseOfRelations: $inverseOfRelations,
					oneToOneRelations: $oneToOneRelations,
					indexes: $indexes,
					autoIncrementColumn: $columnData->autoIncrementColumn,
					columnDefinitions: $columnDefinitions,
					softDeleteProperty: $columnData->softDeleteProperty,
					softDeleteColumn: $columnData->softDeleteColumn,
					softDeleteColumnType: $columnData->softDeleteColumnType,
				);
			} catch (\RuntimeException $e) {
				throw $e;
			} catch (\Exception $e) {
				throw new \RuntimeException("Failed to build metadata for {$className}: " . $e->getMessage(), 0, $e);
			}
		}
		
		/**
		 * Single pass over property annotations to extract all column-related metadata.
		 * @param array<string, AnnotationCollection> $annotations
		 * @return ColumnData
		 */
		private function extractColumnData(array $annotations): ColumnData {
			$columnMap = [];
			$identifierKeys = [];
			$identifierColumns = [];
			$versionColumns = [];
			$autoIncrementColumn = null;
			$softDeleteProperty = null;
			$softDeleteColumn = null;
			$softDeleteColumnType = null;
			
			foreach ($annotations as $property => $annotationCollection) {
				// Collect the four annotation types we care about for this property.
				// Using nulls means we can cheaply test presence below without array_filter.
				$column = null;
				$version = null;
				$strategy = null;
				$softDelete = null;
				
				foreach ($annotationCollection as $annotation) {
					if ($annotation instanceof Column) {
						$column = $annotation;
					} elseif ($annotation instanceof Version) {
						$version = $annotation;
					} elseif ($annotation instanceof PrimaryKeyStrategy) {
						$strategy = $annotation;
					} elseif ($annotation instanceof SoftDelete) {
						$softDelete = $annotation;
					}
				}
				
				// Properties without @Column have no database representation — skip them
				if ($column === null) {
					continue;
				}
				
				// Map property name — database column name for query building
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
				
				// @SoftDelete requires both @Column and @SoftDelete to be present on the
				// same property. Only the first occurrence is recorded; multiple @SoftDelete
				// annotations on a single entity are not supported.
				if ($softDelete !== null && $softDeleteProperty === null) {
					$softDeleteProperty = $property;
					$softDeleteColumn = $columnName;
					$softDeleteColumnType = $column->getType();
				}
			}
			
			return new ColumnData(
				columnMap: $columnMap,
				identifierKeys: $identifierKeys,
				identifierColumns: $identifierColumns,
				versionColumns: $versionColumns,
				autoIncrementColumn: $autoIncrementColumn,
				softDeleteProperty: $softDeleteProperty,
				softDeleteColumn: $softDeleteColumn,
				softDeleteColumnType: $softDeleteColumnType,
			);
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
		
		/**
		 * Validates that every localColumn declared on a ManyToOne or OneToOne relation
		 * has a corresponding property with an @Orm\Column annotation on the entity.
		 * A missing backing property would cause a silent hydration failure at runtime;
		 * catching it here gives a clear error at the first point of use instead.
		 * @param class-string $className
		 * @param array<string, AnnotationCollection> $annotations
		 * @param array<string, ManyToOne> $manyToOneRelations
		 * @param array<string, OneToOne> $oneToOneRelations
		 * @throws \RuntimeException When a declared localColumn has no backing @Orm\Column property
		 */
		private function validateRelationColumns(
			string $className,
			array $annotations,
			array $manyToOneRelations,
			array $oneToOneRelations
		): void {
			$relations = array_merge($manyToOneRelations, $oneToOneRelations);
			
			foreach ($relations as $property => $relation) {
				// Use the explicit localColumn if declared, otherwise fall back to the
				// convention used at runtime in RelationshipLoader ($property . 'Id')
				$localColumn = $relation->getLocalColumn() ?? $property . 'Id';
				
				if (!$this->hasColumnProperty($annotations, $localColumn)) {
					if ($relation->getLocalColumn() !== null) {
						$source = "declares localColumn='{$localColumn}'";
					} else {
						$source = "uses the default localColumn convention '{$localColumn}'";
					}
					
					throw new \RuntimeException(
						"Relation '{$property}' on '{$className}' {$source} " .
						"but no property with '@Orm\\Column' named '{$localColumn}' exists on the entity. " .
						"Add an '@Orm\\Column' property for the foreign key."
					);
				}
			}
		}
		
		/**
		 * Returns true if a property named $propertyName exists in the annotation map
		 * and carries an @Orm\Column annotation.
		 * @param array<string, AnnotationCollection> $annotations
		 * @param string $propertyName
		 * @return bool
		 */
		private function hasColumnProperty(array $annotations, string $propertyName): bool {
			if (!isset($annotations[$propertyName])) {
				return false;
			}
			
			foreach ($annotations[$propertyName] as $annotation) {
				if ($annotation instanceof Column) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Validates that no property carries more than one relationship annotation.
		 *
		 * A property is either an owning side (ManyToOne/OneToOne) or an inverse hydration
		 * target (InverseOf), never both. Catching overlaps here prevents the loader from
		 * silently keeping the first relation annotation and dropping the rest.
		 * @param class-string $className
		 * @param array<string, ManyToOne> $manyToOneRelations
		 * @param array<string, OneToOne> $oneToOneRelations
		 * @param array<string, InverseOf> $inverseOfRelations
		 * @throws \RuntimeException When a property declares more than one relationship annotation
		 */
		private function validateSingleRelationPerProperty(
			string $className,
			array $manyToOneRelations,
			array $oneToOneRelations,
			array $inverseOfRelations
		): void {
			// Collect, per property, every relationship annotation type declared on it.
			$typesByProperty = [];
			
			$relationsByType = [
				'ManyToOne' => $manyToOneRelations,
				'OneToOne'  => $oneToOneRelations,
				'InverseOf' => $inverseOfRelations,
			];
			
			foreach ($relationsByType as $type => $relations) {
				foreach (array_keys($relations) as $property) {
					$typesByProperty[$property][] = $type;
				}
			}
			
			// A property mapped by more than one type carries conflicting relationship annotations.
			foreach ($typesByProperty as $property => $types) {
				if (count($types) > 1) {
					throw new \RuntimeException(
						"Property '{$property}' on '{$className}' declares multiple relationship annotations (" .
						implode(', ', $types) . "). A property may carry only one of ManyToOne, OneToOne, or InverseOf."
					);
				}
			}
		}
		
		/**
		 * Validates that InverseOf collection properties are typed as a CollectionInterface.
		 *
		 * A plain 'array' cannot hold a managed collection — the hydrator only populates
		 * CollectionInterface instances — so reject it at build time with an actionable error
		 * instead of letting the relation silently fail to populate during hydration.
		 * @param class-string $className
		 * @param array<string, InverseOf> $inverseOfRelations
		 * @throws \RuntimeException When an InverseOf property is typed as a plain array
		 */
		private function validateInverseOfPropertyTypes(string $className, array $inverseOfRelations): void {
			foreach (array_keys($inverseOfRelations) as $property) {
				$type = $this->reflectionHandler->getPropertyType($className, $property);
				
				// Strip a leading nullable marker before comparing the declared type name.
				if ($type !== null && ltrim($type, '?') === 'array') {
					throw new \RuntimeException(
						"InverseOf property '{$property}' on '{$className}' is typed as 'array'. " .
						"Inverse collections must be typed as " . \Quellabs\ObjectQuel\Collections\CollectionInterface::class .
						" and initialized to a collection instance in the entity constructor."
					);
				}
			}
		}
	}