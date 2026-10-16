<?php
	
	namespace Quellabs\ObjectQuel\Metadata;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\AnnotationReader\Collection\AnnotationCollection;
	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
	use Quellabs\AnnotationReader\Exception\ParserException;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\EntityBridge;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKeyAction;
	use Quellabs\ObjectQuel\Annotations\Orm\FullTextIndex;
	use Quellabs\ObjectQuel\Annotations\Orm\Index;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\InverseOf;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\SoftDelete;
	use Quellabs\ObjectQuel\Annotations\Orm\UniqueIndex;
	use Quellabs\ObjectQuel\Annotations\Orm\Version;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\TypeMapper;
	use Quellabs\ObjectQuel\ReflectionManagement\ReflectionHandler;
	
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
		 * @param EntityStore $entityStore
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
				$tableAnnotation = $classAnnotations->getFirst(Table::class);
				
				if (!$tableAnnotation instanceof Table) {
					throw new \RuntimeException("Missing @Table annotation on {$className}");
				}
				
				$tableName = $tableAnnotation->getName();

				// Marks a pure linking/junction entity; QueryBuilder reads this to decide
				// whether to eager-join one hop further through its own relations.
				$isEntityBridge = $classAnnotations->getFirst(EntityBridge::class) !== null;

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
				$columnData = $this->extractColumnData($className, $annotations);
				
				// Extract the relations and indexes
				$indexes = $this->extractIndexes($className);
				$columnDefinitions = $this->extractColumnDefinitions($className, $annotations, $columnData->autoIncrementColumn);
				$manyToOneRelations = $this->extractRelations($annotations, ManyToOne::class);
				$oneToOneRelations = $this->extractRelations($annotations, OneToOne::class);
				$inverseOfRelations = $this->extractRelations($annotations, InverseOf::class);
				$foreignKeys = $this->extractForeignKeys($className, $annotations, $columnData->columnMap, $manyToOneRelations, $oneToOneRelations);
				$foreignKeyActions = $this->extractForeignKeyActions($className, $annotations, $columnData->columnMap, $manyToOneRelations, $oneToOneRelations, $foreignKeys);

				// Validate that every localColumn declared on a ManyToOne or OneToOne
				// has a corresponding @Orm\Column property on this entity. Catching this
				// at metadata build time gives a clear error instead of a silent hydration failure.
				$this->validateRelationColumns($className, $annotations, $manyToOneRelations, $oneToOneRelations);
				$this->validateSingleRelationPerProperty($className, $manyToOneRelations, $oneToOneRelations, $inverseOfRelations);
				$this->validateInverseOfPropertyTypes($className, $inverseOfRelations);
				$this->validateCascadeRequiresRelation($className, $annotations, $manyToOneRelations, $oneToOneRelations, $inverseOfRelations);

				// Resolve every owning relation's column pairs (one pair for a singular
				// declaration, one pair per array position for a plural/composite one)
				// into the single shape all downstream relation code consumes.
				$relationColumnMappings = $this->buildRelationColumnMappings($className, $annotations, $manyToOneRelations, $oneToOneRelations, $columnData);

				// Return  a new EntityMetadataRecord containing all relation data
				return new EntityMetadataRecord(
					className: $className,
					tableName: $tableName,
					properties: $properties,
					annotations: $annotations,
					columnMap: $columnData->columnMap,
					columnAnnotations: $columnData->columnAnnotations,
					identifierKeys: $columnData->identifierKeys,
					identifierColumns: $columnData->identifierColumns,
					versionColumns: $columnData->versionColumns,
					manyToOneRelations: $manyToOneRelations,
					inverseOfRelations: $inverseOfRelations,
					oneToOneRelations: $oneToOneRelations,
					relationColumnMappings: $relationColumnMappings,
					indexes: $indexes,
					autoIncrementColumn: $columnData->autoIncrementColumn,
					columnDefinitions: $columnDefinitions,
					softDeleteProperty: $columnData->softDeleteProperty,
					softDeleteColumn: $columnData->softDeleteColumn,
					softDeleteColumnType: $columnData->softDeleteColumnType,
					foreignKeys: $foreignKeys,
					foreignKeyActions: $foreignKeyActions,
					isEntityBridge: $isEntityBridge,
				);
			} catch (\RuntimeException $e) {
				throw $e;
			} catch (\Exception $e) {
				throw new \RuntimeException("Failed to build metadata for {$className}: " . $e->getMessage(), 0, $e);
			}
		}
		
		/**
		 * Single pass over property annotations to extract all column-related metadata.
		 * @param class-string $className
		 * @param array<string, AnnotationCollection> $annotations
		 * @return ColumnData
		 * @throws \RuntimeException When a composite primary key declares an explicit 'identity' strategy
		 */
		private function extractColumnData(string $className, array $annotations): ColumnData {
			$columnMap = [];
			$columnAnnotations = [];
			$identifierKeys = [];
			$identifierColumns = [];
			$identifierStrategies = [];
			$versionColumns = [];
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
				$columnAnnotations[$property] = $column;

				if ($column->isPrimaryKey()) {
					$identifierKeys[] = $property;   // PHP property name of the PK
					$identifierColumns[] = $columnName; // Database column name of the PK
					$identifierStrategies[$property] = $strategy;
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

			// Deferred until every PK component is known — a per-property decision would
			// wrongly treat the first component of a yet-undiscovered composite key as an
			// auto-increment column.
			$autoIncrementColumn = $this->resolveAutoIncrementColumn($className, $identifierKeys, $identifierStrategies);

			return new ColumnData(
				columnMap: $columnMap,
				columnAnnotations: $columnAnnotations,
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
		 * Decides the entity's auto-increment column once every primary-key property
		 * is known. A single-column key defaults to identity generation unless an
		 * explicit non-identity strategy is declared. A composite key is never
		 * auto-increment: an omitted strategy on any of its components means assigned
		 * (the application provides the value), and an explicit 'identity' strategy on
		 * any component is rejected outright rather than silently generating only part
		 * of the key.
		 * @param class-string $className
		 * @param list<string> $identifierKeys
		 * @param array<string, PrimaryKeyStrategy|null> $identifierStrategies Property name => its @PrimaryKeyStrategy, or null
		 * @return string|null
		 * @throws \RuntimeException When a composite primary key declares an explicit 'identity' strategy
		 */
		private function resolveAutoIncrementColumn(string $className, array $identifierKeys, array $identifierStrategies): ?string {
			if (count($identifierKeys) !== 1) {
				foreach ($identifierKeys as $property) {
					$strategy = $identifierStrategies[$property];

					if ($strategy !== null && $strategy->getValue() === 'identity') {
						throw new \RuntimeException(
							"Property '{$property}' on '{$className}' is part of a composite primary key and declares " .
							"an explicit '@Orm\\PrimaryKeyStrategy(strategy=\"identity\")'. A composite primary key cannot " .
							"use database identity generation; omit the strategy to leave this component assigned by the " .
							"application, or use an explicit non-identity generator for it."
						);
					}
				}

				return null;
			}

			$property = $identifierKeys[0];
			$strategy = $identifierStrategies[$property];
			$isIdentity = $strategy === null || $strategy->getValue() === 'identity';
			return $isIdentity ? $property : null;
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
		 * Extract ForeignKey annotations, keyed by database column name rather than
		 * property name — MakeMigrationsCommand looks these up by column without
		 * caring whether that column belongs to a scalar property or the local side
		 * of a ManyToOne/OneToOne relation.
		 *
		 * @Orm\ForeignKey belongs on the plain scalar @Orm\Column property that backs
		 * the FK column. A relation's local column is already required to have its
		 * own scalar @Orm\Column property (see validateRelationColumns()), so that's
		 * always the canonical place for the annotation to live, even for a column
		 * that also backs an object relation. When @Orm\ForeignKey is placed on the
		 * ManyToOne/OneToOne property itself instead, it's simply ignored.
		 * @param class-string $className
		 * @param array<string, AnnotationCollection> $annotations
		 * @param array<string, string> $columnMap Property name => column name
		 * @param array<string, ManyToOne> $manyToOneRelations
		 * @param array<string, OneToOne> $oneToOneRelations
		 * @return array<string, ForeignKey> Column name => ForeignKey annotation
		 * @throws \RuntimeException When its column can't be determined
		 * @throws EntityResolutionException
		 */
		private function extractForeignKeys(
			string $className,
			array $annotations,
			array $columnMap,
			array $manyToOneRelations,
			array $oneToOneRelations
		): array {
			$relations = $manyToOneRelations + $oneToOneRelations;
			$foreignKeys = [];

			foreach ($annotations as $property => $annotationCollection) {
				foreach ($annotationCollection as $annotation) {
					// Skip any annotation that is not ForeignKey
					if (!$annotation instanceof ForeignKey) {
						continue;
					}

					// Ignored when declared on the relation property itself — the
					// canonical place is the scalar column backing it.
					if (isset($relations[$property])) {
						continue;
					}

					$columnName = $columnMap[$property] ?? null;

					if ($columnName === null) {
						throw new \RuntimeException(
							"Property '{$property}' on '{$className}' carries an '@Orm\\ForeignKey' annotation " .
							"but is not itself declared with '@Orm\\Column'. '@Orm\\ForeignKey' is only legal " .
							"on a scalar column property — add '@Orm\\Column' to '{$property}', or move " .
							"'@Orm\\ForeignKey' to the property that has one."
						);
					}

					// Normalize the target entity name the same way ManyToOne::targetEntity is,
					// so callers always receive a fully qualified class name.
					$annotation->setTarget($this->entityStore->normalizeEntityClass($annotation->getTarget()));
					$foreignKeys[$columnName] = $annotation;
					break;
				}
			}

			return $foreignKeys;
		}

		/**
		 * Collects @Orm\ForeignKeyAction annotations, keyed by the same database
		 * column name extractForeignKeys() uses, so the two can be looked up
		 * together by column. ForeignKeyAction only configures an existing
		 * ForeignKey's ON DELETE/ON UPDATE behavior — it's meaningless without one,
		 * so a ForeignKeyAction with no matching ForeignKey on the same column is a
		 * build-time error rather than a silently-ignored annotation.
		 * @param class-string $className
		 * @param array<string, AnnotationCollection> $annotations
		 * @param array<string, string> $columnMap Property name => column name
		 * @param array<string, ManyToOne> $manyToOneRelations
		 * @param array<string, OneToOne> $oneToOneRelations
		 * @param array<string, ForeignKey> $foreignKeys Column name => ForeignKey annotation
		 * @return array<string, ForeignKeyAction> Column name => ForeignKeyAction annotation
		 * @throws \RuntimeException When a ForeignKeyAction has no matching ForeignKey
		 */
		private function extractForeignKeyActions(
			string $className,
			array $annotations,
			array $columnMap,
			array $manyToOneRelations,
			array $oneToOneRelations,
			array $foreignKeys
		): array {
			$relations = $manyToOneRelations + $oneToOneRelations;
			$foreignKeyActions = [];

			foreach ($annotations as $property => $annotationCollection) {
				foreach ($annotationCollection as $annotation) {
					if (!$annotation instanceof ForeignKeyAction) {
						continue;
					}

					// Ignored when declared on the relation property itself, same as
					// ForeignKey — there's no ForeignKey on this property to configure.
					if (isset($relations[$property])) {
						continue;
					}

					$columnName = $columnMap[$property] ?? null;

					if ($columnName === null || !isset($foreignKeys[$columnName])) {
						$columnDescription = $columnName ?? $property;

						throw new \RuntimeException(
							"Property '{$property}' on '{$className}' carries an '@Orm\\ForeignKeyAction' " .
							"annotation but no matching '@Orm\\ForeignKey' exists for column " .
							"'{$columnDescription}'. ForeignKeyAction only configures an existing foreign " .
							"key's ON DELETE/ON UPDATE behavior — declare '@Orm\\ForeignKey' for that " .
							"column first."
						);
					}

					$foreignKeyActions[$columnName] = $annotation;
					break;
				}
			}

			return $foreignKeyActions;
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
		 * @param string|null $autoIncrementColumn The entity's sole auto-increment
		 *        column, already resolved (with composite-PK exclusion) by
		 *        {@see extractColumnData} — reused so `identity` isn't
		 *        recomputed per-property from a second source of truth.
		 * @return array<string, ColumnDefinitionRecord> Column definitions keyed by column name.
		 * @throws AnnotationReaderException If annotation reading fails for any property.
		 * @throws \ReflectionException      If the class does not exist or cannot be reflected.
		 */
		private function extractColumnDefinitions(string $className, array $annotations, ?string $autoIncrementColumn): array {
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
				$isIdentity = $property->getName() === $autoIncrementColumn;
				$definitions[$columnName] = $this->buildColumnDefinition($columnAnnotation, $property, $isIdentity);
			}

			return $definitions;
		}

		/**
		 * Builds the definition array for a single mapped column.
		 *
		 * Combines metadata from the {@see Column} annotation and the PHP reflection
		 * of the property. Type defaults and enum cases are resolved via {@see TypeMapper}.
		 *
		 * @param Column $column The column annotation carrying mapping metadata.
		 * @param \ReflectionProperty $property The reflected property this column maps to.
		 * @param bool $isIdentity Whether this property is the entity's resolved auto-increment column.
		 * @return ColumnDefinitionRecord
		 */
		private function buildColumnDefinition(Column $column, \ReflectionProperty $property, bool $isIdentity): array {
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
				'identity'      => $isIdentity,
				'values'        => TypeMapper::getEnumCases($column->getEnumType()),
			];
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
				// A composite (plural localColumns/referencedColumns) relation has no
				// single default convention to fall back to — it's validated together
				// with target primary-key coverage in resolveRelationColumnMapping() instead.
				if ($relation->hasCompositeColumns()) {
					continue;
				}

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
		 * Builds the resolved column-pair mapping for every owning ManyToOne/OneToOne
		 * relation on this entity, keyed by property name.
		 * @param class-string $className
		 * @param array<string, AnnotationCollection> $annotations
		 * @param array<string, ManyToOne> $manyToOneRelations
		 * @param array<string, OneToOne> $oneToOneRelations
		 * @param ColumnData $columnData
		 * @return array<string, RelationColumnMapping>
		 * @throws \RuntimeException
		 */
		private function buildRelationColumnMappings(
			string $className,
			array $annotations,
			array $manyToOneRelations,
			array $oneToOneRelations,
			ColumnData $columnData
		): array {
			$mappings = [];

			foreach ($manyToOneRelations + $oneToOneRelations as $property => $relation) {
				$mappings[$property] = $this->resolveRelationColumnMapping($className, $property, $relation, $annotations, $columnData);
			}

			return $mappings;
		}

		/**
		 * Normalizes one owning relation's singular or plural column declaration into
		 * an ordered RelationColumnMapping. This is the only place that reads
		 * localColumn/referencedColumn or localColumns/referencedColumns off the
		 * annotation directly — everything downstream consumes the resolved result.
		 * @param class-string $className
		 * @param string $property
		 * @param ManyToOne|OneToOne $relation
		 * @param array<string, AnnotationCollection> $annotations
		 * @param ColumnData $columnData
		 * @return RelationColumnMapping
		 * @throws \RuntimeException When the declaration is incomplete, ambiguous, or doesn't match the target
		 */
		private function resolveRelationColumnMapping(
			string $className,
			string $property,
			ManyToOne|OneToOne $relation,
			array $annotations,
			ColumnData $columnData
		): RelationColumnMapping {
			$relationLabel = $relation instanceof ManyToOne ? 'ManyToOne' : 'OneToOne';
			$targetClassName = $relation->getTargetEntity();
			$targetInfo = $this->resolveTargetColumnInfo($targetClassName, $className, $columnData);

			if ($relation->hasCompositeColumns()) {
				$localProps = $relation->getLocalColumns();
				$targetProps = $relation->getReferencedColumns();

				if (count(array_unique($localProps)) !== count($localProps)) {
					throw new \RuntimeException(
						"{$relationLabel} '{$property}' on '{$className}' declares duplicate entries in 'localColumns'."
					);
				}

				if (count(array_unique($targetProps)) !== count($targetProps)) {
					throw new \RuntimeException(
						"{$relationLabel} '{$property}' on '{$className}' declares duplicate entries in 'referencedColumns'."
					);
				}

				foreach ($localProps as $localProp) {
					if (!$this->hasColumnProperty($annotations, $localProp)) {
						throw new \RuntimeException(
							"{$relationLabel} '{$property}' on '{$className}' declares localColumns entry '{$localProp}' " .
							"but no property with '@Orm\\Column' named '{$localProp}' exists on the entity."
						);
					}
				}

				$targetPkSet = $targetProps;
				sort($targetPkSet);
				$expectedPkSet = $targetInfo['identifierKeys'];
				sort($expectedPkSet);

				if ($targetPkSet !== $expectedPkSet) {
					throw new \RuntimeException(
						"{$relationLabel} '{$property}' on '{$className}' declares 'referencedColumns' (" .
						implode(', ', $targetProps) . ") that do not exactly match the complete primary key of " .
						"'{$targetClassName}' (" . implode(', ', $targetInfo['identifierKeys']) . "). The plural " .
						"relation form references the target's full composite primary key only."
					);
				}

				$pairs = [];

				foreach ($localProps as $index => $localProp) {
					$targetProp = $targetProps[$index];
					$pairs[] = new RelationColumnPair(
						localProperty: $localProp,
						localColumn: $columnData->columnMap[$localProp],
						targetProperty: $targetProp,
						targetColumn: $targetInfo['columnMap'][$targetProp],
					);
				}

				if ($pairs === []) {
					// Unreachable: AnnotationColumnArrays::validate() already rejects an empty
					// 'localColumns'/'referencedColumns' pair, so hasCompositeColumns() being
					// true guarantees at least one entry to iterate above.
					throw new \LogicException("{$relationLabel} '{$property}' on '{$className}' resolved to zero column pairs.");
				}

				return new RelationColumnMapping($pairs);
			}

			// Singular form. validateRelationColumns() already checked that the local
			// column exists; here we resolve and validate the target side.
			$localProp = $relation->getLocalColumn() ?? ($property . 'Id');
			$targetProp = $relation->getReferencedColumn();

			if ($targetProp === null) {
				if (count($targetInfo['identifierKeys']) !== 1) {
					throw new \RuntimeException(
						"{$relationLabel} '{$property}' on '{$className}' omits 'referencedColumn' but " .
						"'{$targetClassName}' has a composite primary key (" . implode(', ', $targetInfo['identifierKeys']) .
						"). Declare 'localColumns'/'referencedColumns' to reference a composite primary key explicitly."
					);
				}

				$targetProp = $targetInfo['identifierKeys'][0];
			} elseif (!in_array($targetProp, $targetInfo['columnProperties'], true)) {
				throw new \RuntimeException(
					"{$relationLabel} '{$property}' on '{$className}' declares referencedColumn='{$targetProp}' but no " .
					"property with '@Orm\\Column' named '{$targetProp}' exists on '{$targetClassName}'."
				);
			}

			return new RelationColumnMapping([
				new RelationColumnPair(
					localProperty: $localProp,
					localColumn: $columnData->columnMap[$localProp],
					targetProperty: $targetProp,
					targetColumn: $targetInfo['columnMap'][$targetProp],
				),
			]);
		}

		/**
		 * Resolves the target entity's @Column-backed property names, identifier
		 * property names, and property-to-column map, without building its full
		 * metadata. Building full metadata here would recurse into the target's own
		 * relations and could cycle forever on a self-reference or a mutual pair of
		 * owning relations; a plain annotation scan has no such recursion. A
		 * self-reference reuses the current entity's own already-built ColumnData
		 * instead of re-scanning it.
		 * @param string $targetClassName
		 * @param class-string $currentClassName
		 * @param ColumnData $currentColumnData
		 * @return array{columnProperties: array<int, string>, identifierKeys: array<int, string>, columnMap: array<string, string>}
		 * @throws \RuntimeException When $targetClassName does not resolve to an existing class
		 */
		private function resolveTargetColumnInfo(string $targetClassName, string $currentClassName, ColumnData $currentColumnData): array {
			if ($targetClassName === $currentClassName) {
				return [
					'columnProperties' => array_keys($currentColumnData->columnMap),
					'identifierKeys'   => $currentColumnData->identifierKeys,
					'columnMap'        => $currentColumnData->columnMap,
				];
			}

			if (!class_exists($targetClassName)) {
				throw new \RuntimeException(
					"Relation on '{$currentClassName}' targets '{$targetClassName}', which is not a loadable class."
				);
			}

			$columnProperties = [];
			$identifierKeys = [];
			$columnMap = [];

			foreach ($this->reflectionHandler->getProperties($targetClassName) as $property) {
				$column = $this->annotationReader
					->getPropertyAnnotations($targetClassName, $property, Column::class)
					->getFirst(Column::class);

				if (!$column instanceof Column) {
					continue;
				}

				$columnProperties[] = $property;
				$columnMap[$property] = $column->getName();

				if ($column->isPrimaryKey()) {
					$identifierKeys[] = $property;
				}
			}

			return [
				'columnProperties' => $columnProperties,
				'identifierKeys'   => $identifierKeys,
				'columnMap'        => $columnMap,
			];
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
		 * Validates that every @Orm\Cascade sits on a property that carries a
		 * ManyToOne/OneToOne/InverseOf relation — a plain scalar column has
		 * nothing for it to walk — and that InverseOf only declares "persist",
		 * since cascade-remove never reads Cascade off InverseOf (see
		 * Cascade.php for why). Independent of @Orm\ForeignKey/@Orm\ForeignKeyAction:
		 * a relation can have Cascade with no database constraint, a database
		 * constraint with no Cascade, or both.
		 * @param class-string $className
		 * @param array<string, AnnotationCollection> $annotations
		 * @param array<string, ManyToOne> $manyToOneRelations
		 * @param array<string, OneToOne> $oneToOneRelations
		 * @param array<string, InverseOf> $inverseOfRelations
		 * @throws \RuntimeException When a Cascade annotation has no relation to cascade,
		 *         or declares "remove" on an InverseOf property where it can't do anything
		 */
		private function validateCascadeRequiresRelation(
			string $className,
			array $annotations,
			array $manyToOneRelations,
			array $oneToOneRelations,
			array $inverseOfRelations
		): void {
			$owningRelations = $manyToOneRelations + $oneToOneRelations;

			foreach ($annotations as $property => $annotationCollection) {
				$cascade = $this->findCascadeAnnotation($annotationCollection);

				if ($cascade === null) {
					continue;
				}

				if (isset($inverseOfRelations[$property])) {
					if (in_array('remove', $cascade->getOperations(), true)) {
						throw new \RuntimeException(
							"Property '{$property}' on '{$className}' carries an '@Orm\\Cascade' annotation " .
							"with a 'remove' operation on an '@Orm\\InverseOf' property. Cascade-remove is " .
							"discovered by querying the dependent entity's foreign key column directly, so " .
							"it never reads Cascade off the InverseOf side — only 'persist' is meaningful " .
							"here, for cascading new, not-yet-saved entities in this collection."
						);
					}

					continue;
				}

				if (!isset($owningRelations[$property])) {
					throw new \RuntimeException(
						"Property '{$property}' on '{$className}' carries an '@Orm\\Cascade' annotation " .
						"but no '@Orm\\ManyToOne', '@Orm\\OneToOne', or '@Orm\\InverseOf' annotation. Cascade " .
						"only governs whether the ORM also walks and persists/removes a related object in " .
						"PHP — it has nothing to do on a plain column."
					);
				}
			}
		}

		/**
		 * Returns the first Cascade annotation in a property's annotation collection, if any.
		 * @param AnnotationCollection|null $collection
		 * @return Cascade|null
		 */
		private function findCascadeAnnotation(?AnnotationCollection $collection): ?Cascade {
			if ($collection === null) {
				return null;
			}

			foreach ($collection as $annotation) {
				if ($annotation instanceof Cascade) {
					return $annotation;
				}
			}

			return null;
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