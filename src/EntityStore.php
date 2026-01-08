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
	
	namespace Quellabs\ObjectQuel;
	
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Collection\AnnotationCollection;
	use Quellabs\AnnotationReader\Exception\ParserException;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\Immutable;
	use Quellabs\ObjectQuel\Annotations\Orm\Index;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToMany;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\UniqueIndex;
	use Quellabs\ObjectQuel\Annotations\Orm\Version;
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ProxyGenerator\ProxyGenerator;
	use Quellabs\ObjectQuel\ReflectionManagement\EntityLocator;
	use Quellabs\ObjectQuel\ReflectionManagement\ReflectionHandler;
	use Quellabs\Support\NamespaceResolver;
	
	/**
	 * Entity metadata registry and access point.
	 *
	 * Core responsibilities:
	 * - Discover and register entities
	 * - Normalize entity names (resolve proxies, namespaces)
	 * - Build and cache entity metadata
	 * - Manage proxy generation
	 */
	class EntityStore {
		private Configuration $configuration;
		private EntityLocator $entityLocator;
		private AnnotationReader $annotationReader;
		private ReflectionHandler $reflectionHandler;
		private ProxyGenerator $proxyGenerator;
		
		private string $proxyNamespace;
		private string $entityNamespace;
		
		// Simple registry of normalized class name => table name
		private array $entityRegistry = [];
		
		// Cache for normalized entity names
		private array $normalizedNameCache = [];
		
		// Cache for EntityMetadata objects (replaces all the old separate caches)
		private array $metadataCache = [];
		
		// Dependency graph (calculated once on demand)
		private ?array $dependencyGraph = null;
		
		/**
		 * EntityStore constructor.
		 *
		 * Initializes the entity metadata system by:
		 * 1. Setting up the annotation reader with cache configuration
		 * 2. Creating the reflection handler for class introspection
		 * 3. Discovering all entity classes in the configured namespace
		 * 4. Registering entities and their table mappings
		 * 5. Initializing the proxy generator for lazy loading
		 *
		 * @param Configuration $configuration The ObjectQuel configuration object
		 */
		public function __construct(Configuration $configuration) {
			$annotationReaderConfiguration = new \Quellabs\AnnotationReader\Configuration();
			$annotationReaderConfiguration->setUseAnnotationCache($configuration->useMetadataCache());
			$annotationReaderConfiguration->setAnnotationCachePath($configuration->getMetadataCachePath());
			
			$this->configuration = $configuration;
			$this->annotationReader = new AnnotationReader($annotationReaderConfiguration);
			$this->reflectionHandler = new ReflectionHandler();
			$this->proxyNamespace = 'Quellabs\\ObjectQuel\\Proxy\\Runtime';
			$this->entityNamespace = $configuration->getEntityNameSpace();
			
			// Discover and register all entities
			$this->entityLocator = new EntityLocator($configuration, $this->annotationReader);
			$this->initializeEntities();
			
			// Initialize proxy generator
			$this->proxyGenerator = new ProxyGenerator($this, $configuration);
		}
		
		// ==================== System Access Methods ====================
		
		public function getAnnotationReader(): AnnotationReader {
			return $this->annotationReader;
		}
		
		public function getReflectionHandler(): ReflectionHandler {
			return $this->reflectionHandler;
		}
		
		public function getProxyGenerator(): ProxyGenerator {
			return $this->proxyGenerator;
		}
		
		public function getProxyNamespace(): string {
			return $this->proxyNamespace;
		}
		
		/**
		 * Get all registered entities as className => tableName map
		 * @return array
		 */
		public function getEntityMap(): array {
			return $this->entityRegistry;
		}
		
		// ==================== Meta data ====================

		/**
		 * Get complete metadata for an entity.
		 * This is the main access point - all other methods delegate to this.
		 * @param mixed $entity Entity object, class name, or ReflectionClass
		 * @return EntityMetadata Immutable metadata object containing all entity information
		 */
		public function getMetadata(mixed $entity): EntityMetadata {
			$className = $this->normalizeEntityName($entity);
			
			// Return cached metadata if available
			// Otherwise build and cache the metadata
			if (!isset($this->metadataCache[$className])) {
				$this->metadataCache[$className] = $this->buildMetadata($className);
			}
			
			return $this->metadataCache[$className];
		}

		/**
		 * Checks if the entity or its parent exists in the entity registry.
		 * @param mixed $entity The entity to check, either as an object or as a string class name
		 * @return bool True if the entity or its parent class exists in the registry, false otherwise
		 */
		public function exists(mixed $entity): bool {
			// Determine the class name of the entity
			$entityClass = $this->extractClassName($entity);
			$normalizedClass = $this->normalizeEntityName($entityClass);
			
			// Check if the entity class exists in the entity registry
			if (isset($this->entityRegistry[$normalizedClass])) {
				return true;
			}
			
			// Get the parent class name using the ReflectionHandler
			$parentClass = $this->reflectionHandler->getParent($normalizedClass);
			
			// Check if the parent class exists in the entity registry
			// Return false if neither the entity nor its parent class exists
			return $parentClass !== null && isset($this->entityRegistry[$parentClass]);
		}
		
		/**
		 * Returns the table name attached to the entity.
		 * @param mixed $entity The entity object, class name, or ReflectionClass
		 * @return string|null The database table name, or null if entity is not registered
		 */
		public function getOwningTable(mixed $entity): ?string {
			return $this->getMetadata($entity)->tableName;
		}
		
		/**
		 * Normalizes the entity name by resolving proxies and namespaces.
		 * @param mixed $entity Fully qualified class name, short name, object, or ReflectionClass
		 * @return string Normalized, fully qualified class name
		 */
		public function normalizeEntityName(mixed $entity): string {
			// Determine the class name of the entity
			$className = $this->extractClassName($entity);
			
			// Return cached entity name if present
			if (isset($this->normalizedNameCache[$className])) {
				return $this->normalizedNameCache[$className];
			}
			
			// Proxy class → resolve to parent
			// If the class name is a proxy, get the parent class name
			if (str_contains($className, $this->proxyNamespace)) {
				return $this->normalizedNameCache[$className] = $this->reflectionHandler->getParent($className);
			}
			
			// Already fully qualified
			if (str_contains($className, "\\")) {
				return $this->normalizedNameCache[$className] = $className;
			}
			
			// Try resolving short class name
			$resolved = NamespaceResolver::resolveClassName($className);

			if ($resolved === $className) {
				$fullyQualifiedClassName = "{$this->entityNamespace}\\{$className}";
			} else {
				$fullyQualifiedClassName = $resolved;
			}
			
			return $this->normalizedNameCache[$className] = $fullyQualifiedClassName;
		}
		
		/**
		 * Returns all entities that depend on the specified entity.
		 *
		 * This method searches through the dependency graph to find entities that have
		 * a ManyToOne or owning OneToOne relationship to the specified entity.
		 * Useful for determining cascade deletion order and relationship integrity.
		 *
		 * @param mixed $entity The entity for which you want to find dependent entities
		 * @return array A list of entity class names that depend on the specified entity
		 */
		public function getDependentEntities(mixed $entity): array {
			// Determine the class name of the entity
			// If the class name is a proxy, get the parent class
			$normalizedClass = $this->normalizeEntityName($entity);
			
			// Get all known entity dependencies
			$dependencies = $this->getAllEntityDependencies();
			
			// Loop through each entity and its dependencies to check for the specified class
			$result = [];
			foreach ($dependencies as $entityClass => $entityDependencies) {
				// If the specified class exists in the dependencies list, add it to the result
				if (in_array($normalizedClass, $entityDependencies, true)) {
					$result[] = $entityClass;
				}
			}
			
			// Return the list of dependent entities
			return $result;
		}
		
		/**
		 * Retrieves the primary key of the main range from an AstRetrieve object.
		 * @param AstRetrieve $astRetrieve A reference to the AstRetrieve object representing the query
		 * @return array|null An array with information about the range and primary key, or null if no suitable range is found
		 */
		public function fetchPrimaryKeyOfMainRange(AstRetrieve $astRetrieve): ?array {
			foreach ($astRetrieve->getRanges() as $range) {
				// Continue if the range contains a join property
				if ($range->getJoinProperty() !== null) {
					continue;
				}
				
				// Get the entity name and its associated primary key if the range doesn't have a join property
				$entityName = $range->getEntityName();
				$metadata = $this->getMetadata($entityName);
				
				// Return the range name, entity name, and the primary key of the entity
				return [
					'range'      => $range,
					'entityName' => $entityName,
					'primaryKey' => $metadata->getPrimaryKey(),
				];
			}
			
			// Return null if no range without a join property is found
			// This should never happen in practice, as such a query cannot be created
			return null;
		}
		
		// ==================== Legacy Compatibility Methods ====================
		//
		// These methods maintain backward compatibility with existing code that uses
		// the old EntityStore API. All of these methods internally delegate to getMetadata()
		// and extract the relevant field from the EntityMetadata object.
		//
		// Consider refactoring callers to use getMetadata() directly for better performance
		// when multiple metadata fields are needed, as this avoids repeated method calls.
		//
		// Example refactoring:
		//   OLD: $keys = $store->getIdentifierKeys($entity);
		//        $cols = $store->getColumnMap($entity);
		//   NEW: $metadata = $store->getMetadata($entity);
		//        $keys = $metadata->identifierKeys;
		//        $cols = $metadata->columnMap;
		
		/**
		 * This function retrieves the primary keys of a given entity.
		 * @param mixed $entity The entity from which the primary keys are retrieved
		 * @return array An array with the names of the properties that are the primary keys
		 */
		public function getIdentifierKeys(mixed $entity): array {
			return $this->getMetadata($entity)->identifierKeys;
		}
		
		/**
		 * Retrieves the column names that serve as primary keys for a specific entity.
		 * @param mixed $entity The entity for which the primary key columns are retrieved
		 * @return array An array with the names of the columns that serve as primary keys
		 */
		public function getIdentifierColumnNames(mixed $entity): array {
			return $this->getMetadata($entity)->identifierColumns;
		}
		
		/**
		 * Retrieves the column names that serve as version columns for a specific entity.
		 * Version columns are used for optimistic locking.
		 * @param mixed $entity The entity for which the version columns are retrieved
		 * @return array An array with the names of the columns that serve as version columns
		 */
		public function getVersionColumnNames(mixed $entity): array {
			return $this->getMetadata($entity)->versionColumns;
		}
		
		/**
		 * Obtains the map between properties and column names for a given entity.
		 * This function generates an associative array that links the properties of an entity
		 * to their respective column names in the database. The results are cached
		 * to prevent repeated calculations.
		 * @param mixed $entity The object or class name of the entity
		 * @return array An associative array with the property as key and the column name as value
		 */
		public function getColumnMap(mixed $entity): array {
			return $this->getMetadata($entity)->columnMap;
		}
		
		/**
		 * Returns the entity's annotations.
		 * @param mixed $entity The entity object or class name string to get annotations for
		 * @param string|null $annotationType Optional class name to filter annotations by specific type
		 * @return array<string, AnnotationCollection> Array of annotation objects, optionally filtered by type
		 */
		public function getAnnotations(mixed $entity, ?string $annotationType = null): array {
			$annotations = $this->getMetadata($entity)->annotations;
			
			// Check if we need to filter by a specific annotation type
			if ($annotationType !== null) {
				$filteredList = [];
				
				foreach ($annotations as $property => $annotationCollection) {
					foreach ($annotationCollection as $annotation) {
						// Check if the current annotation is an instance of the requested type
						// is_a() compares the annotation object against the class name string
						if (is_a($annotation, $annotationType)) {
							$filteredList[$property] = $annotation;
						}
					}
				}
				
				return $filteredList;
			}
			
			// No specific type requested, return all annotations for this entity
			return $annotations;
		}
		
		/**
		 * Returns all properties of an entity.
		 * @param mixed $entity The entity object or class name string
		 * @return array An array of property names
		 */
		public function getProperties(mixed $entity): array {
			return $this->getMetadata($entity)->properties;
		}
		
		/**
		 * Retrieve the ManyToOne dependencies for a given entity class.
		 * This function uses annotations to determine which other entities
		 * are related to the given entity class via a ManyToOne relationship.
		 * The names of these related entities are returned as an array.
		 * @param mixed $entity The name of the entity class to inspect
		 * @return ManyToOne[] An array of entity names with which the given class has a ManyToOne relationship
		 */
		public function getManyToOneDependencies(mixed $entity): array {
			return $this->getMetadata($entity)->manyToOneRelations;
		}
		
		/**
		 * Retrieves all OneToMany dependencies for a specific entity.
		 * @param mixed $entity The name of the entity for which you want to get the OneToMany dependencies
		 * @return OneToMany[] An associative array with the name of the target entity as key and the annotation as value
		 */
		public function getOneToManyDependencies(mixed $entity): array {
			return $this->getMetadata($entity)->oneToManyRelations;
		}
		
		/**
		 * Retrieves all OneToOne dependencies for a specific entity.
		 * @param mixed $entity The name of the entity for which you want to get the OneToOne dependencies
		 * @return OneToOne[] An associative array with the name of the target entity as key and the annotation as value
		 */
		public function getOneToOneDependencies(mixed $entity): array {
			return $this->getMetadata($entity)->oneToOneRelations;
		}
		
		/**
		 * Return true if the entity is immutable (readonly), false if not.
		 * An immutable entity is marked with the @Immutable annotation.
		 *
		 * @param mixed $entity The entity to check
		 * @return bool True if the entity is immutable, false otherwise
		 */
		public function isImmutable(mixed $entity): bool {
			$annotationList = $this->getAnnotations($entity, Immutable::class);
			return !empty($annotationList);
		}
		
		/**
		 * Internal helper function for retrieving properties with a specific annotation.
		 * Returns all relationship annotations (ManyToOne, OneToMany, OneToOne) for the entity.
		 * @param mixed $entity The name of the entity for which you want to get dependencies
		 * @return array Property name => array of relationship annotations
		 */
		public function getAllDependencies(mixed $entity): array {
			$metadata = $this->getMetadata($entity);
			
			// Combine all relationship types into a single result array
			$result = [];
			
			// Get all annotations for the entity
			$annotationList = $metadata->annotations;
			
			// Loop through each annotation to check for a relationship
			foreach (array_keys($annotationList) as $property) {
				foreach ($annotationList[$property] as $annotation) {
					if ($annotation instanceof OneToMany || $annotation instanceof OneToOne || $annotation instanceof ManyToOne) {
						$result[$property][] = $annotation;
						continue 2;
					}
				}
			}
			
			return $result;
		}
		
		/**
		 * Retrieves all index annotations defined for a given entity class.
		 * @param mixed $entity The entity class to analyze (can be string classname or object instance)
		 * @return array A collection of Index and UniqueIndex annotation objects
		 */
		public function getIndexes(mixed $entity): array {
			return $this->getMetadata($entity)->indexes;
		}
		
		/**
		 * Retrieves the primary key field name for a given entity.
		 * @param mixed $entity The entity object or class to inspect
		 * @return string|null The primary key property name, or null if none exists
		 */
		public function getPrimaryKey(mixed $entity): ?string {
			return $this->getMetadata($entity)->getPrimaryKey();
		}
		
		/**
		 * This method finds primary key columns that are configured to receive
		 * database-generated values, which are either:
		 * 1. Primary keys with a PrimaryKeyStrategy annotation set to "identity", or
		 * 2. Primary keys with no explicitly defined strategy (defaulting to auto-increment)
		 * @param mixed $entity The entity to examine
		 * @return string|null The name of the auto-incrementing primary key field, or null if none found
		 */
		public function findAutoIncrementPrimaryKey(mixed $entity): ?string {
			return $this->getMetadata($entity)->autoIncrementColumn;
		}
		
		/**
		 * Extracts database column definitions from an entity class using reflection and annotations.
		 * @param string $className The fully qualified class name of the entity
		 * @return array An associative array of column definitions indexed by column name
		 */
		public function extractEntityColumnDefinitions(string $className): array {
			return $this->getMetadata($className)->columnDefinitions;
		}
		
		/**
		 * Normalizes the primary key into an array.
		 * This function checks if the given primary key is already an array.
		 * If not, it converts the primary key into an array with the proper key
		 * based on the entity type.
		 * @param mixed $primaryKey The primary key to be normalized
		 * @param string $entityType The type of entity for which the primary key is needed
		 * @return array A normalized representation of the primary key as an array
		 */
		public function formatPrimaryKeyAsArray(mixed $primaryKey, string $entityType): array {
			return $this->getMetadata($entityType)->formatPrimaryKeyAsArray($primaryKey);
		}
		
		// ==================== Private Helper Methods ====================
		
		/**
		 * Build complete EntityMetadata for a given class.
		 *
		 * This method consolidates all the metadata extraction logic that was previously
		 * scattered across multiple methods in the old EntityStore. It extracts information
		 * from entity annotations and reflection to build a complete, immutable metadata object.
		 *
		 * The metadata includes:
		 * - Table name from @Table annotation
		 * - All entity properties and their annotations
		 * - Column mappings (property names to database column names)
		 * - Primary key information (both property and column names)
		 * - Version tracking columns (for optimistic locking)
		 * - Relationship annotations (ManyToOne, OneToMany, OneToOne)
		 * - Index definitions
		 * - Auto-increment primary key detection
		 * - Full column definitions for schema generation
		 *
		 * @param string $className Fully qualified, normalized entity class name
		 * @return EntityMetadata Immutable metadata object containing all entity information
		 * @throws \RuntimeException If metadata extraction fails
		 */
		private function buildMetadata(string $className): EntityMetadata {
			try {
				// Get table name from @Table annotation
				$classAnnotations = $this->annotationReader->getClassAnnotations($className);
				$tableName = $classAnnotations["Quellabs\\ObjectQuel\\Annotations\\Orm\\Table"]->getName();
				
				// Get all entity properties using reflection
				$properties = $this->reflectionHandler->getProperties($className);
				
				// Get all property annotations
				// This builds a map of property name => AnnotationCollection
				$annotations = [];
				foreach ($properties as $property) {
					$annotations[$property] = $this->annotationReader->getPropertyAnnotations($className, $property);
				}
				
				// Build column map (property name => column name)
				// Loop through all annotations, linked to their respective properties
				$columnMap = [];
				foreach ($annotations as $property => $annotationCollection) {
					// Get the column name from the annotations
					foreach ($annotationCollection as $annotation) {
						if ($annotation instanceof Column) {
							$columnMap[$property] = $annotation->getName();
							break;
						}
					}
				}
				
				// Extract identifier keys (property names that are primary keys)
				$identifierKeys = [];
				foreach ($annotations as $property => $annotationCollection) {
					foreach ($annotationCollection as $annotation) {
						if ($annotation instanceof Column && $annotation->isPrimaryKey()) {
							$identifierKeys[] = $property;
							break;
						}
					}
				}
				
				// Extract identifier columns (column names that are primary keys)
				$identifierColumns = [];
				foreach ($annotations as $annotationCollection) {
					foreach ($annotationCollection as $annotation) {
						if ($annotation instanceof Column && $annotation->isPrimaryKey()) {
							$identifierColumns[] = $annotation->getName();
						}
					}
				}
				
				// Extract version columns (for optimistic locking)
				// Version columns must have both @Column and @Version annotations
				$versionColumns = [];
				foreach ($annotations as $property => $annotationCollection) {
					$column = null;
					$version = null;
					
					foreach ($annotationCollection as $annotation) {
						if ($annotation instanceof Column) {
							$column = $annotation;
						} elseif ($annotation instanceof Version) {
							$version = $annotation;
						}
					}
					
					// Only include if both annotations are present
					if ($column !== null && $version !== null) {
						$versionColumns[$property] = [
							'name'    => $column->getName(),
							'column'  => $column,
							'version' => $version,
						];
					}
				}
				
				// Extract relationships (ManyToOne, OneToMany, OneToOne)
				$manyToOneRelations = $this->extractRelations($annotations, ManyToOne::class);
				$oneToManyRelations = $this->extractRelations($annotations, OneToMany::class);
				$oneToOneRelations = $this->extractRelations($annotations, OneToOne::class);
				
				// Extract indexes (both regular and unique indexes from class-level annotations)
				$indexes = $this->extractIndexes($className);
				
				// Find auto-increment column (primary key with identity strategy or no strategy)
				$autoIncrementColumn = null;
				foreach ($annotations as $property => $annotationCollection) {
					if ($this->isIdentityColumn($annotationCollection->toArray())) {
						$autoIncrementColumn = $property;
						break;
					}
				}
				
				// Extract column definitions (for schema generation and migrations)
				$columnDefinitions = $this->extractColumnDefinitions($className, $annotations);
				
				return new EntityMetadata(
					className: $className,
					tableName: $tableName,
					properties: $properties,
					annotations: $annotations,
					columnMap: $columnMap,
					identifierKeys: $identifierKeys,
					identifierColumns: $identifierColumns,
					versionColumns: $versionColumns,
					manyToOneRelations: $manyToOneRelations,
					oneToManyRelations: $oneToManyRelations,
					oneToOneRelations: $oneToOneRelations,
					indexes: $indexes,
					autoIncrementColumn: $autoIncrementColumn,
					columnDefinitions: $columnDefinitions,
				);
			} catch (\Exception $e) {
				throw new \RuntimeException("Failed to build metadata for {$className}: " . $e->getMessage(), 0, $e);
			}
		}
		
		/**
		 * Extract class name from various entity representations.
		 *
		 * Handles multiple input types:
		 * - ReflectionClass objects
		 * - Entity object instances
		 * - String class names (with or without leading backslash)
		 *
		 * @param mixed $entity The entity in any supported format
		 * @return string The extracted class name
		 */
		private function extractClassName(mixed $entity): string {
			if ($entity instanceof \ReflectionClass) {
				return $entity->getName();
			}
			
			if (is_object($entity)) {
				return get_class($entity);
			}
			
			return ltrim($entity, "\\");
		}
		
		/**
		 * Initialize entity classes using the EntityLocator.
		 *
		 * This method discovers entity classes, validates them,
		 * and loads their table mappings into memory. It is called once
		 * during EntityStore construction to build the entity registry.
		 *
		 * The registry maps fully qualified class names to their database table names,
		 * which is used by various methods to quickly look up table information
		 * without needing to parse annotations repeatedly.
		 *
		 * @return void
		 */
		private function initializeEntities(): void {
			try {
				$entityClasses = $this->entityLocator->discoverEntities();
				
				foreach ($entityClasses as $entityName) {
					$classAnnotations = $this->annotationReader->getClassAnnotations($entityName);
					$tableName = $classAnnotations["Quellabs\\ObjectQuel\\Annotations\\Orm\\Table"]->getName();
					
					$this->entityRegistry[$entityName] = $tableName;
				}
			} catch (\Exception $e) {
				error_log("Error initializing entities: " . $e->getMessage());
			}
		}
		
		/**
		 * Extract relationship annotations of a specific type from property annotations.
		 *
		 * Scans through all property annotations and filters out relationships matching
		 * the specified annotation type (ManyToOne, OneToMany, or OneToOne).
		 *
		 * @param array $annotations Property name => AnnotationCollection mapping
		 * @param string $annotationType The relationship annotation class to extract
		 * @return array Property name => relationship annotation mapping
		 */
		private function extractRelations(array $annotations, string $annotationType): array {
			$relations = [];
			
			foreach ($annotations as $property => $annotationCollection) {
				foreach ($annotationCollection as $annotation) {
					if ($annotation instanceof $annotationType) {
						$relations[$property] = $annotation;
						break;
					}
				}
			}
			
			return $relations;
		}
		
		/**
		 * Extract index annotations from class level.
		 *
		 * Retrieves both regular @Index and @UniqueIndex annotations defined
		 * on the entity class itself (not on properties).
		 *
		 * @param string $className The fully qualified class name
		 * @return array Array of Index and UniqueIndex annotation objects
		 */
		private function extractIndexes(string $className): array {
			try {
				$classAnnotations = $this->annotationReader->getClassAnnotations($className);
				
				$indexes = $classAnnotations->filter(function ($annotation) {
					return $annotation instanceof Index || $annotation instanceof UniqueIndex;
				});
				
				return $indexes->toArray();
			} catch (ParserException $e) {
				return [];
			}
		}
		
		/**
		 * Determines if a property represents an auto-increment column.
		 *
		 * A column is considered auto-increment if it:
		 * 1. Has a Column annotation marked as primary key, AND
		 * 2. Either:
		 *    - Has a PrimaryKeyStrategy annotation with value 'identity', OR
		 *    - Has no PrimaryKeyStrategy annotation at all (defaulting to auto-increment)
		 *
		 * @param array $propertyAnnotations The annotations attached to the property
		 * @return bool Returns true if the property is an auto-increment column, false otherwise
		 */
		private function isIdentityColumn(array $propertyAnnotations): bool {
			$isPrimaryKey = false;
			$hasStrategy = false;
			$isIdentityStrategy = false;
			
			// Check all annotations on this property
			foreach ($propertyAnnotations as $annotation) {
				// Check if this is a primary key column
				if ($annotation instanceof Column && $annotation->isPrimaryKey()) {
					$isPrimaryKey = true;
				}
				
				// Check if this has any strategy annotation
				if ($annotation instanceof PrimaryKeyStrategy) {
					$hasStrategy = true;
					
					if ($annotation->getValue() === 'identity') {
						$isIdentityStrategy = true;
					}
				}
			}
			
			// Return true if:
			// 1. It's a primary key, AND
			// 2. EITHER it has an identity strategy OR it has no strategy at all
			return $isPrimaryKey && ($isIdentityStrategy || !$hasStrategy);
		}
		
		/**
		 * Extract full column definitions for schema generation.
		 *
		 * This method builds comprehensive metadata for each database column,
		 * including type information, constraints, defaults, and other properties
		 * needed for creating or migrating database schemas.
		 *
		 * The extracted information includes:
		 * - Database column type and PHP property type
		 * - Length limits and precision/scale for numeric types
		 * - Nullable, unsigned, and default value settings
		 * - Primary key and auto-increment detection
		 * - Enum value lists for enum columns
		 *
		 * @param string $className The fully qualified class name
		 * @param array $annotations Pre-extracted annotations (for performance)
		 * @return array Column name => column definition mapping
		 */
		private function extractColumnDefinitions(string $className, array $annotations): array {
			$definitions = [];
			
			try {
				// Create a reflection object for the provided class to inspect its properties
				$reflection = new \ReflectionClass($className);
				
				// Iterate through all properties of the class
				foreach ($reflection->getProperties() as $property) {
					try {
						// Retrieve all annotations for the current property
						$propertyAnnotations = $this->annotationReader->getPropertyAnnotations(
							$className,
							$property->getName(),
							Column::class
						);
						
						// If not found, go to the next property
						if ($propertyAnnotations->isEmpty()) {
							continue;
						}
						
						// Find the @Orm\Column annotation
						$columnAnnotation = $propertyAnnotations[Column::class];
						
						// Use the column name from the annotation, not the property name
						$columnName = $columnAnnotation->getName();
						
						// If no column name found, skip this property
						if (empty($columnName)) {
							continue;
						}
						
						// Fetch the database column type
						$columnType = $columnAnnotation->getType();
						
						// Build a comprehensive array of column metadata
						$definitions[$columnName] = [
							'property_name' => $property->getName(),                    // PHP property name
							'type'          => $columnType,                             // Database column type
							'php_type'      => $property->getType(),                    // PHP type (from reflection)
							
							// Get column limit from annotation or use default based on the column type
							'limit'         => $columnAnnotation->getLimit() ?? TypeMapper::getDefaultLimit($columnType),
							'nullable'      => $columnAnnotation->isNullable(),         // Whether column allows NULL values
							'unsigned'      => $columnAnnotation->isUnsigned(),         // Whether numeric column is unsigned
							'default'       => $columnAnnotation->getDefault(),         // Default value for the column
							'primary_key'   => $columnAnnotation->isPrimaryKey(),       // Whether column is a primary key
							'scale'         => $columnAnnotation->getScale(),           // Decimal scale (for numeric types)
							'precision'     => $columnAnnotation->getPrecision(),       // Decimal precision (for numeric types)
							
							// Check if this column is an auto-incrementing identity column
							'identity'      => $this->isIdentityColumn($propertyAnnotations->toArray()),
							
							// Read enum values
							'values'        => TypeMapper::getEnumCases($columnAnnotation->getEnumType())
						];
					} catch (ParserException $e) {
						// Silently skip properties with annotation errors
					}
				}
			} catch (\ReflectionException $e) {
				// Silently handle reflection exceptions - return empty array
			}
			
			// Return the complete set of column definitions
			return $definitions;
		}
		
		/**
		 * Build dependency graph for all entities.
		 *
		 * Returns a list of entities and their ManyToOne/OneToOne dependencies.
		 * This is used to determine the correct order for operations like cascade
		 * deletion and foreign key constraint management.
		 *
		 * The dependency graph is built once on first access and cached for subsequent calls.
		 *
		 * @return array Entity class name => array of dependent entity class names
		 */
		private function getAllEntityDependencies(): array {
			// Build the dependency graph only once, then cache it
			if ($this->dependencyGraph === null) {
				$this->dependencyGraph = [];
				
				// Loop through all registered entities
				foreach (array_keys($this->entityRegistry) as $className) {
					$metadata = $this->getMetadata($className);
					
					$dependencies = [];
					
					// Add ManyToOne dependencies
					// These represent foreign key relationships where this entity depends on another
					foreach ($metadata->manyToOneRelations as $relation) {
						$dependencies[] = $this->normalizeEntityName($relation->getTargetEntity());
					}
					
					// Add OneToOne dependencies (owning side only)
					// Only include OneToOne relations where this entity owns the relationship
					// (indicated by the inversedBy property being set)
					foreach ($metadata->oneToOneRelations as $relation) {
						if (!empty($relation->getInversedBy())) {
							$dependencies[] = $this->normalizeEntityName($relation->getTargetEntity());
						}
					}
					
					// Remove duplicates and store in the dependency graph
					$this->dependencyGraph[$className] = array_unique($dependencies);
				}
			}
			
			return $this->dependencyGraph;
		}
	}