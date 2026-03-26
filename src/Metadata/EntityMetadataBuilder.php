<?php
	
	namespace Quellabs\ObjectQuel\Metadata;
	
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Exception\ParserException;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\FullTextIndex;
	use Quellabs\ObjectQuel\Annotations\Orm\Index;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToMany;
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
	 */
	class EntityMetadataBuilder {
		
		private array $normalizedNameCache = [];
		
		public function __construct(
			private readonly AnnotationReader  $annotationReader,
			private readonly ReflectionHandler $reflectionHandler,
			private readonly string            $proxyNamespace,
			private readonly string            $entityNamespace,
		) {}
		
		// ==================== Public API ====================
		
		/**
		 * Build complete EntityMetadata for a given class.
		 * @param string $className Fully qualified, normalized entity class name
		 * @return EntityMetadata
		 * @throws \RuntimeException If metadata extraction fails
		 */
		public function build(string $className): EntityMetadata {
			try {
				// Fetch class-level annotations to extract the @Table name
				$classAnnotations = $this->annotationReader->getClassAnnotations($className);
				$tableName        = $classAnnotations["Quellabs\\ObjectQuel\\Annotations\\Orm\\Table"]->getName();
				
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
				
				return new EntityMetadata(
					className:           $className,
					tableName:           $tableName,
					properties:          $properties,
					annotations:         $annotations,
					columnMap:           $columnMap,
					identifierKeys:      $identifierKeys,
					identifierColumns:   $identifierColumns,
					versionColumns:      $versionColumns,
					manyToOneRelations:  $this->extractRelations($annotations, ManyToOne::class),
					oneToManyRelations:  $this->extractRelations($annotations, OneToMany::class),
					oneToOneRelations:   $this->extractRelations($annotations, OneToOne::class),
					indexes:             $this->extractIndexes($className),        // class-level annotations only
					autoIncrementColumn: $autoIncrementColumn,
					columnDefinitions:   $this->extractColumnDefinitions($className, $annotations),
				);
			} catch (\Exception $e) {
				throw new \RuntimeException("Failed to build metadata for {$className}: " . $e->getMessage(), 0, $e);
			}
		}
		
		/**
		 * Normalizes the entity name by resolving proxies and namespaces.
		 * Exposed publicly so EntityStore can delegate its own normalizeEntityName() here.
		 * @param mixed $entity Fully qualified class name, short name, object, or ReflectionClass
		 * @return string Normalized, fully qualified class name
		 */
		public function normalizeEntityName(mixed $entity): string {
			$className = $this->extractClassName($entity);
			
			// Return early if we've resolved this name before
			if (isset($this->normalizedNameCache[$className])) {
				return $this->normalizedNameCache[$className];
			}
			
			// Proxy classes are anonymous subclasses generated at runtime.
			// Their real identity is the parent class, so unwrap one level.
			if (str_contains($className, $this->proxyNamespace)) {
				return $this->normalizedNameCache[$className] = $this->reflectionHandler->getParent($className);
			}
			
			// A backslash means the caller already passed a fully qualified name
			if (str_contains($className, "\\")) {
				return $this->normalizedNameCache[$className] = $className;
			}
			
			// Short name (e.g. "User") — try the namespace resolver first,
			// then fall back to prepending the configured entity namespace
			$resolved = NamespaceResolver::resolveClassName($className);
			
			$fullyQualifiedClassName = ($resolved === $className)
				? "{$this->entityNamespace}\\{$className}"
				: $resolved;
			
			return $this->normalizedNameCache[$className] = $fullyQualifiedClassName;
		}
		
		// ==================== Private Helpers ====================
		
		/**
		 * Extract class name from various entity representations.
		 */
		private function extractClassName(mixed $entity): string {
			if ($entity instanceof \ReflectionClass) {
				// ReflectionClass already knows its own name
				return $entity->getName();
			} elseif (is_object($entity)) {
				// Entity instance — get the class name at runtime
				return get_class($entity);
			} else {
				// String class name — strip any leading backslash so all paths
				// produce a consistent format before hitting the cache
				return ltrim($entity, "\\");
			}
		}
		
		/**
		 * Single pass over property annotations to extract all column-related metadata.
		 * Returns [columnMap, identifierKeys, identifierColumns, versionColumns, autoIncrementColumn].
		 */
		private function extractColumnData(array $annotations): array {
			$columnMap           = [];
			$identifierKeys      = [];
			$identifierColumns   = [];
			$versionColumns      = [];
			$autoIncrementColumn = null;
			
			foreach ($annotations as $property => $annotationCollection) {
				// Collect the three annotation types we care about for this property.
				// Using nulls means we can cheaply test presence below without array_filter.
				$column   = null;
				$version  = null;
				$strategy = null;
				
				foreach ($annotationCollection as $annotation) {
					if ($annotation instanceof Column)                 $column   = $annotation;
					elseif ($annotation instanceof Version)            $version  = $annotation;
					elseif ($annotation instanceof PrimaryKeyStrategy) $strategy = $annotation;
				}
				
				// Properties without @Column have no database representation — skip them
				if ($column === null) {
					continue;
				}
				
				// Map property name → database column name for query building
				$columnName           = $column->getName();
				$columnMap[$property] = $columnName;
				
				if ($column->isPrimaryKey()) {
					$identifierKeys[]    = $property;   // PHP property name of the PK
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
		 * @param array $annotations Property name => AnnotationCollection mapping
		 * @param string $annotationType The relationship annotation class to extract
		 * @return array Property name => relationship annotation mapping
		 */
		private function extractRelations(array $annotations, string $annotationType): array {
			$relations = [];
			
			foreach ($annotations as $property => $annotationCollection) {
				foreach ($annotationCollection as $annotation) {
					if ($annotation instanceof $annotationType) {
						// Normalize the target entity name so it's always fully qualified.
						// Annotations may contain short names (e.g. "Order") that need
						// expanding to the full namespace before they can be used elsewhere.
						$annotation->setTargetEntity($this->normalizeEntityName($annotation->getTargetEntity()));
						
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
		 * @param string $className The fully qualified class name
		 * @return array Array of Index, UniqueIndex and FullTextIndex annotation objects
		 */
		private function extractIndexes(string $className): array {
			try {
				$classAnnotations = $this->annotationReader->getClassAnnotations($className);
				
				// Index annotations live at the class level, not on individual properties,
				// so we filter the class annotation collection rather than the property map
				return $classAnnotations->filter(function ($annotation) {
					return $annotation instanceof Index
						|| $annotation instanceof UniqueIndex
						|| $annotation instanceof FullTextIndex;
				})->toArray();
			} catch (ParserException $e) {
				// A parse failure here shouldn't block entity registration —
				// return empty rather than propagating, the schema tool will catch it
				return [];
			}
		}
		
		/**
		 * Extract full column definitions for schema generation.
		 * @param string $className The fully qualified class name
		 * @param array $annotations Pre-extracted annotations (for performance)
		 * @return array Column name => column definition mapping
		 */
		private function extractColumnDefinitions(string $className, array $annotations): array {
			$definitions = [];
			
			try {
				$reflection = new \ReflectionClass($className);
				
				foreach ($reflection->getProperties() as $property) {
					try {
						// Fetch only @Column annotations for this property — passing Column::class
						// as a filter avoids loading annotations we won't use here
						$propertyAnnotations = $this->annotationReader->getPropertyAnnotations(
							$className,
							$property->getName(),
							Column::class
						);
						
						// No @Column means this property has no database column — skip it
						if ($propertyAnnotations->isEmpty()) {
							continue;
						}
						
						$columnAnnotation = $propertyAnnotations[Column::class];
						$columnName       = $columnAnnotation->getName();
						
						// A @Column without a name is misconfigured — skip rather than store a blank key
						if (empty($columnName)) {
							continue;
						}
						
						$columnType              = $columnAnnotation->getType();
						$definitions[$columnName] = [
							'property_name' => $property->getName(),
							'type'          => $columnType,
							'php_type'      => $property->getType(),                                          // PHP declared type (from reflection)
							'limit'         => $columnAnnotation->getLimit() ?? TypeMapper::getDefaultLimit($columnType), // Fall back to type default if unset
							'nullable'      => $columnAnnotation->isNullable(),
							'unsigned'      => $columnAnnotation->isUnsigned(),
							'default'       => $columnAnnotation->getDefault(),
							'primary_key'   => $columnAnnotation->isPrimaryKey(),
							'scale'         => $columnAnnotation->getScale(),                                 // Decimal scale (numeric types only)
							'precision'     => $columnAnnotation->getPrecision(),                             // Decimal precision (numeric types only)
							'identity'      => $this->isIdentityColumn($propertyAnnotations->toArray()),      // True if DB should generate this value
							'values'        => TypeMapper::getEnumCases($columnAnnotation->getEnumType()),    // Allowed values for ENUM columns
						];
					} catch (ParserException $e) {
						// Malformed annotation on one property shouldn't abort the whole class
					}
				}
			} catch (\ReflectionException $e) {
				// Class doesn't exist or can't be reflected — return whatever we collected so far
			}
			
			return $definitions;
		}
		
		/**
		 * Determines if a property represents an auto-increment column.
		 *
		 * True when: primary key AND (strategy = 'identity' OR no strategy defined).
		 * @param array $propertyAnnotations The annotations attached to the property
		 * @return bool
		 */
		private function isIdentityColumn(array $propertyAnnotations): bool {
			$isPrimaryKey       = false;
			$hasStrategy        = false;
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