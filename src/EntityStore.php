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
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Collection\AnnotationCollection;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\Immutable;
	use Quellabs\ObjectQuel\Annotations\Orm\FullTextIndex;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToMany;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\Version;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataBuilder;
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
		private AnnotationReader $annotationReader;
		private ReflectionHandler $reflectionHandler;
		private ProxyGenerator $proxyGenerator;
		private EntityMetadataBuilder $metadataBuilder;
		
		private string $proxyNamespace;
		private string $entityNamespace;
		
		// Simple registry of normalized class name => table name
		/** @var array<string, string> */
		private array $entityRegistry = [];
		
		// Cache for normalized entity names
		/** @var array<string, string> */
		private array $normalizedNameCache = [];
		
		// Cache for EntityMetadata objects (replaces all the old separate caches)
		/** @var array<string, EntityMetadataRecord> */
		private array $metadataCache = [];
		
		// Dependency graph (calculated once on demand)
		/** @var array<string, array<int, string>>|null */
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
			
			// Fetch builder
			$this->metadataBuilder = new EntityMetadataBuilder(
				$this->annotationReader,
				$this->reflectionHandler,
				$this->proxyNamespace,
				$this->entityNamespace
			);
			
			// Discover and register all entities
			$this->initializeEntities();
			
			// Initialize proxy generator
			$this->proxyGenerator = new ProxyGenerator($this, $configuration);
		}
		
		// ==================== System Access Methods ====================
		
		/**
		 * Returns the annotationReader object
		 * @return AnnotationReader
		 */
		public function getAnnotationReader(): AnnotationReader {
			return $this->annotationReader;
		}
		
		/**
		 * Returns the ReflectionHandler object
		 * @return ReflectionHandler
		 */
		public function getReflectionHandler(): ReflectionHandler {
			return $this->reflectionHandler;
		}
		
		/**
		 * Returns the proxy generator
		 * @return ProxyGenerator
		 */
		public function getProxyGenerator(): ProxyGenerator {
			return $this->proxyGenerator;
		}
		
		/**
		 * Returns the proxy namespace
		 * @return string
		 */
		public function getProxyNamespace(): string {
			return $this->proxyNamespace;
		}
		
		/**
		 * Get all registered entities as className => tableName map
		 * @return array<string, string>
		 */
		public function getEntityMap(): array {
			return $this->entityRegistry;
		}
		
		// ==================== Meta data ====================
		
		/**
		 * Get complete metadata for an entity.
		 * This is the main access point - all other methods delegate to this.
		 * @param mixed $entity Entity object, class name, or ReflectionClass
		 * @return EntityMetadataRecord Immutable metadata object containing all entity information
		 */
		public function getMetadata(mixed $entity): EntityMetadataRecord {
			$className = $this->normalizeEntityName($entity);
			
			// Return cached metadata if available
			// Otherwise build and cache the metadata
			if (!isset($this->metadataCache[$className])) {
				$this->metadataCache[$className] = $this->metadataBuilder->build($className);
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
			$normalizedClass = $this->normalizeEntityName($entity);
			
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
		 * @return array<int, string> A list of entity class names that depend on the specified entity
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
		 * @return array{
		 *      range: mixed,
		 *      entityName: string,
		 *      primaryKey: string|null
		 *  }|null An array with information about the range and primary key, or null if no suitable range is found
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
		 * @return array<int, string> An array with the names of the properties that are the primary keys
		 */
		public function getIdentifierKeys(mixed $entity): array {
			return $this->getMetadata($entity)->identifierKeys;
		}
		
		/**
		 * Retrieves the column names that serve as primary keys for a specific entity.
		 * @param mixed $entity The entity for which the primary key columns are retrieved
		 * @return array<int, string> An array with the names of the columns that serve as primary keys
		 */
		public function getIdentifierColumnNames(mixed $entity): array {
			return $this->getMetadata($entity)->identifierColumns;
		}
		
		/**
		 * Retrieves the columns that serve as version columns for a specific entity.
		 * Version columns are used for optimistic locking.
		 * @param mixed $entity The entity for which the version columns are retrieved
		 * @return array<string, array{name: string, column: Column, version: Version}> An array with the names of the columns that serve as version columns
		 */
		public function getVersionColumns(mixed $entity): array {
			return $this->getMetadata($entity)->versionColumns;
		}
		
		/**
		 * Obtains the map between properties and column names for a given entity.
		 * This function generates an associative array that links the properties of an entity
		 * to their respective column names in the database. The results are cached
		 * to prevent repeated calculations.
		 * @param mixed $entity The object or class name of the entity
		 * @return array<string, string> An associative array with the property as key and the column name as value
		 */
		public function getColumnMap(mixed $entity): array {
			return $this->getMetadata($entity)->columnMap;
		}
		
		/**
		 * Returns all annotations grouped by property.
		 * @param mixed $entity
		 * @return array<string, array<int, AnnotationInterface>>
		 */
		public function getAnnotations(mixed $entity): array {
			$result = [];
			
			foreach ($this->getMetadata($entity)->annotations as $property => $annotationCollection) {
				foreach ($annotationCollection as $annotation) {
					$result[$property][] = $annotation;
				}
			}
			
			return $result;
		}
		
		/**
		 * Returns annotations filtered by a specific type.
		 * @template T of AnnotationInterface
		 * @param mixed $entity
		 * @param class-string<T> $annotationType
		 * @return array<string, array<int, T>>
		 */
		public function getAnnotationsOfType(mixed $entity, string $annotationType): array {
			$result = [];
			
			foreach ($this->getMetadata($entity)->annotations as $property => $annotationCollection) {
				foreach ($annotationCollection as $annotation) {
					if (is_a($annotation, $annotationType)) {
						$result[$property][] = $annotation;
					}
				}
			}
			
			return $result;
		}
		
		/**
		 * Returns all properties of an entity.
		 * @param mixed $entity The entity object or class name string
		 * @return array<int, string> An array of property names
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
		 * @return array<string, ManyToOne> An array of entity names with which the given class has a ManyToOne relationship
		 */
		public function getManyToOneDependencies(mixed $entity): array {
			return $this->getMetadata($entity)->getManyToOneDependencies();
		}
		
		/**
		 * Retrieves all OneToMany dependencies for a specific entity.
		 * @param mixed $entity The name of the entity for which you want to get the OneToMany dependencies
		 * @return array<string, OneToMany> An associative array with the name of the target entity as key and the annotation as value
		 */
		public function getOneToManyDependencies(mixed $entity): array {
			return $this->getMetadata($entity)->getOneToManyDependencies();
		}
		
		/**
		 * Retrieves all OneToOne dependencies for a specific entity.
		 * @param mixed $entity The name of the entity for which you want to get the OneToOne dependencies
		 * @return array<string, OneToOne> An associative array with the name of the target entity as key and the annotation as value
		 */
		public function getOneToOneDependencies(mixed $entity): array {
			return $this->getMetadata($entity)->getOneToOneDependencies();
		}
		
		/**
		 * Return true if the entity is immutable (readonly), false if not.
		 * An immutable entity is marked with the @Immutable annotation.
		 * @param mixed $entity The entity to check
		 * @return bool True if the entity is immutable, false otherwise
		 */
		public function isImmutable(mixed $entity): bool {
			$annotationList = $this->getAnnotationsOfType($entity, Immutable::class);
			return !empty($annotationList);
		}
		
		/**
		 * Internal helper function for retrieving properties with a specific annotation.
		 * Returns all relationship annotations (ManyToOne, OneToMany, OneToOne) for the entity.
		 * @param mixed $entity The name of the entity for which you want to get dependencies
		 * @return array<string, array<int, ManyToOne|OneToOne|OneToMany>> Property name => array of relationship annotations
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
		 * @return array<int, object> A collection of Index, UniqueIndex and FullTextIndex annotation objects
		 */
		public function getIndexes(mixed $entity): array {
			return $this->getMetadata($entity)->indexes;
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
		 * @param mixed $entity The entity to inspect
		 * @param array<int, string> $propertyNames $propertyNames The property names passed to search() or search_score()
		 * @return FullTextIndex|null The matching index, or null if none covers all columns
		 */
		public function getFullTextIndexForColumns(mixed $entity, array $propertyNames): ?FullTextIndex {
			$indexes = $this->getMetadata($entity)->indexes;
			
			foreach ($indexes as $index) {
				if (!$index instanceof FullTextIndex) {
					continue;
				}
				
				// All requested property names must be present in this index's column list
				$indexColumns = $index->getColumns();
				$missingColumns = array_diff($propertyNames, $indexColumns);
				
				if (empty($missingColumns)) {
					return $index;
				}
			}
			
			return null;
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
		 * @return array<string, mixed> An associative array of column definitions indexed by column name
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
		 * @return array<string, mixed> A normalized representation of the primary key as an array
		 */
		public function formatPrimaryKeyAsArray(mixed $primaryKey, string $entityType): array {
			return $this->getMetadata($entityType)->formatPrimaryKeyAsArray($primaryKey);
		}
		
		// ==================== Private Helper Methods ====================
		
		/**
		 * Extract class name from various entity representations.
		 * @param mixed $entity The entity in any supported format
		 * @return string The extracted class name
		 */
		private function extractClassName(mixed $entity): string {
			if ($entity instanceof \ReflectionClass) {
				return $entity->getName();
			} elseif (is_object($entity)) {
				return get_class($entity);
			} else {
				return ltrim($entity, "\\");
			}
		}
		
		/**
		 * Initialize entity classes using the EntityLocator.
		 * @return void
		 */
		private function initializeEntities(): void {
			$entityLocator = new EntityLocator($this->configuration, $this->annotationReader);
			
			foreach ($entityLocator->discoverEntities() as $entityName) {
				$classAnnotations = $this->annotationReader->getClassAnnotations($entityName);
				$tableName = $classAnnotations["Quellabs\\ObjectQuel\\Annotations\\Orm\\Table"]->getName();
				$this->entityRegistry[$entityName] = $tableName;
			}
		}
		
		/**
		 * Build dependency graph for all entities.
		 * @return array<string, array<int, string>> Entity class name => array of dependent entity class names
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