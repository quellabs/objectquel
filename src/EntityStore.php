<?php
	
	/**
	 * ObjectQuel - A Sophisticated Object-Relational Mapping (ORM) System
	 *
	 * ObjectQuel is an ORM that brings a fresh approach to database interaction,
	 * featuring a unique query language, a streamlined architecture, and powerful
	 * entity relationship management. It implements the Data Mapper pattern for
	 * clear separation between domain models and underlying database structures.
	 *
	 * @author      Floris van den Berg
	 * @copyright   Copyright (c) 2025 ObjectQuel
	 * @license     MIT
	 * @version     1.0.0
	 * @package     Quellabs\ObjectQuel
	 */
	
	namespace Quellabs\ObjectQuel;
    
    use Quellabs\AnnotationReader\AnnotationReader;
    use Quellabs\AnnotationReader\Exception\ParserException;
    use Quellabs\ObjectQuel\Annotations\Orm\Column;
    use Quellabs\ObjectQuel\Annotations\Orm\Index;
    use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
    use Quellabs\ObjectQuel\Annotations\Orm\OneToMany;
    use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
    use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
    use Quellabs\ObjectQuel\Annotations\Orm\UniqueIndex;
    use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
    use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
    use Quellabs\ObjectQuel\ProxyGenerator\ProxyGenerator;
    use Quellabs\ObjectQuel\ReflectionManagement\EntityLocator;
    use Quellabs\ObjectQuel\ReflectionManagement\ReflectionHandler;
    
    class EntityStore {
	    protected Configuration $configuration;
	    protected EntityLocator $entity_locator;
		protected AnnotationReader $annotation_reader;
        protected ReflectionHandler $reflection_handler;
		protected ProxyGenerator $proxy_generator;
		protected string $proxyNamespace;
        protected array $entity_properties;
        protected array $entity_table_name;
        protected array $entity_annotations;
        protected array $column_map_cache;
        protected array $identifier_keys_cache;
        protected array $identifier_columns_cache;
        protected string|bool $services_path;
	    protected string $entity_namespace;
        protected array|null $dependencies;
        protected array $dependencies_cache;
        protected array $completed_entity_name_cache;
        protected array $auto_increment_column_cache;
        protected array $index_cache;
	    
	    /**
	     * EntityStore constructor.
	     */
		public function __construct(Configuration $configuration) {
			$annotationReaderConfiguration = new \Quellabs\AnnotationReader\Configuration();
			$annotationReaderConfiguration->setUseAnnotationCache($configuration->useMetadataCache());
			$annotationReaderConfiguration->setAnnotationCachePath($configuration->getMetadataCachePath());
			
			$this->proxyNamespace = 'Quellabs\\ObjectQuel\\Proxy\\Runtime';
			$this->configuration = $configuration;
			$this->annotation_reader = new AnnotationReader($annotationReaderConfiguration);
			$this->reflection_handler = new ReflectionHandler();
			$this->services_path = realpath(__DIR__ . DIRECTORY_SEPARATOR . "..");
			$this->entity_namespace = $configuration->getEntityNameSpace();
			$this->entity_properties = [];
			$this->entity_table_name = [];
			$this->entity_annotations = [];
			$this->column_map_cache = [];
			$this->identifier_keys_cache = [];
			$this->identifier_columns_cache = [];
			$this->dependencies = null;
			$this->dependencies_cache = [];
			$this->completed_entity_name_cache = [];
			$this->auto_increment_column_cache = [];
			$this->index_cache = [];

			// Create the EntityLocator
			$this->entity_locator = new EntityLocator($configuration, $this->annotation_reader);
			
			// Deze functie initialiseert alle entiteiten in de "Entity"-directory.
			$this->initializeEntities();
			
			// Deze functie initialiseert de proxies
			$this->proxy_generator = new ProxyGenerator($this, $configuration);
		}
	    
	    /**
	     * Returns the annotationReader object
	     * @return AnnotationReader
	     */
	    public function getAnnotationReader(): AnnotationReader {
		    return $this->annotation_reader;
	    }
	    
	    /**
	     * Returns the ReflectionHandler object
	     * @return ReflectionHandler
	     */
	    public function getReflectionHandler(): ReflectionHandler {
		    return $this->reflection_handler;
	    }
	    
	    /**
	     * Returns the proxy generator
	     * @return ProxyGenerator
	     */
	    public function getProxyGenerator(): ProxyGenerator {
		    return $this->proxy_generator;
	    }
	    
	    /**
	     * Returns the proxy namespace
	     * @return string
	     */
	    public function getProxyNamespace(): string {
		    return $this->proxyNamespace;
	    }
		
		/**
	     * Normalizes the entity name to return the base entity class if the input is a proxy class.
	     * @param string $class The fully qualified class name to be normalized.
	     * @return string The normalized class name.
	     */
	    public function normalizeEntityName(string $class): string {
		    if (!isset($this->completed_entity_name_cache[$class])) {
			    // Check if the class name contains the proxy namespace, which indicates a proxy class.
			    // If it's a proxy class, get the name of the parent class (the real entity class).
			    if (str_contains($class, $this->getProxyNamespace())) {
				    $this->completed_entity_name_cache[$class] = $this->reflection_handler->getParent($class);
			    } elseif (str_contains($class, "\\")) {
				    $this->completed_entity_name_cache[$class] = $class;
			    } else {
				    $this->completed_entity_name_cache[$class] = "{$this->entity_namespace}\\{$class}";
			    }
		    }
		    
		    // Return the cached class name, which will be the normalized version
		    return $this->completed_entity_name_cache[$class];
	    }
	    
	    /**
	     * Checks if the entity or its parent exists in the entity_table_name array.
	     * @param mixed $entity The entity to check, either as an object or as a string class name.
	     * @return bool True if the entity or its parent class exists in the entity_table_name array, false otherwise.
	     */
	    public function exists(mixed $entity): bool {
		    // Determine the class name of the entity
		    if (!is_object($entity)) {
			    $entityClass = ltrim($entity, "\\");
		    } elseif ($entity instanceof \ReflectionClass) {
			    $entityClass = $entity->getName();
		    } else {
			    $entityClass = get_class($entity);
		    }
		    
		    // If the class name is a proxy, get the parent class name
		    $normalizedClass = $this->normalizeEntityName($entityClass);
		    
		    // Check if the entity class exists in the entity_table_name array
		    if (isset($this->entity_table_name[$normalizedClass])) {
			    return true;
		    }
		    
		    // Get the parent class name using the ReflectionHandler
		    $parentClass = $this->getReflectionHandler()->getParent($normalizedClass);
		    
		    // Check if the parent class exists in the entity_table_name array
		    if ($parentClass !== null && isset($this->entity_table_name[$parentClass])) {
			    return true;
		    }
		    
		    // Return false if neither the entity nor its parent class exists in the entity_table_name array
		    return false;
	    }
	    
	    /**
	     * Returns the table name attached to the entity
	     * @param mixed $entity
	     * @return string|null
	     */
	    public function getOwningTable(mixed $entity): ?string {
		    // Determine the class name of the entity
		    if (!is_object($entity)) {
			    $entityClass = ltrim($entity, "\\");
		    } elseif ($entity instanceof \ReflectionClass) {
			    $entityClass = $entity->getName();
		    } else {
			    $entityClass = get_class($entity);
		    }
		    
		    // If the class name is a proxy, get the parent class name
		    $normalizedClass = $this->normalizeEntityName($entityClass);
		    
		    // Get the table name
		    return $this->entity_table_name[$normalizedClass] ?? null;
	    }
	    
	    /**
	     * Returns an array with key = className and value=tableName
	     * @return array
	     */
		public function getEntityMap(): array {
			return $this->entity_table_name;
		}
	    
	    /**
	     * This function retrieves the primary keys of a given entity.
	     * @param mixed $entity The entity from which the primary keys are retrieved.
	     * @return array An array with the names of the properties that are the primary keys.
	     */
	    public function getIdentifierKeys(mixed $entity): array {
		    // Determine the class name of the entity
		    if (!is_object($entity)) {
			    $entityClass = ltrim($entity, "\\");
		    } elseif ($entity instanceof \ReflectionClass) {
			    $entityClass = $entity->getName();
		    } else {
			    $entityClass = get_class($entity);
		    }
		    
		    // If the class name is a proxy, get the class from the parent
		    $normalizedClass = $this->normalizeEntityName($entityClass);
		    
		    // Use the cached value if it exists
		    if (isset($this->identifier_keys_cache[$normalizedClass])) {
			    return $this->identifier_keys_cache[$normalizedClass];
		    }
		    
		    // Retrieve all annotations for the given entity.
		    $entityAnnotations = $this->getAnnotations($entity);
		    
		    // Find the primary keys and cache the result
		    $result = [];
		    
		    foreach ($entityAnnotations as $property => $annotations) {
			    foreach ($annotations as $annotation) {
				    if ($annotation instanceof Column && $annotation->isPrimaryKey()) {
					    $result[] = $property;
					    break;
				    }
			    }
		    }
		    
		    // Cache the result for future use
		    $this->identifier_keys_cache[$normalizedClass] = $result;
		    
		    // Return the result
		    return $result;
	    }
	    
	    /**
	     * Retrieves the column names that serve as primary keys for a specific entity.
	     * @param mixed $entity The entity for which the primary key columns are retrieved.
	     * @return array An array with the names of the columns that serve as primary keys.
	     */
	    public function getIdentifierColumnNames(mixed $entity): array {
		    // Determine the class name of the entity
		    if (!is_object($entity)) {
			    $entityClass = ltrim($entity, "\\");
		    } elseif ($entity instanceof \ReflectionClass) {
			    $entityClass = $entity->getName();
		    } else {
			    $entityClass = get_class($entity);
		    }
		    
		    // If the class name is a proxy, get the class from the parent
		    $normalizedClass = $this->normalizeEntityName($entityClass);
		    
		    // Use the cached value if it exists
		    if (isset($this->identifier_columns_cache[$normalizedClass])) {
			    return $this->identifier_columns_cache[$normalizedClass];
		    }
		    
		    // Retrieve all annotations for the given entity
		    $annotationList = $this->getAnnotations($normalizedClass);
		    
		    // Initialize an empty array to store the results
		    $result = [];
		    
		    // Loop through all annotations of the entity
		    foreach ($annotationList as $annotations) {
			    foreach ($annotations as $annotation) {
				    if ($annotation instanceof Column && $annotation->isPrimaryKey()) {
					    $result[] = $annotation->getName();
				    }
			    }
		    }
		    
		    // Cache the result for future use
		    $this->identifier_columns_cache[$normalizedClass] = $result;
		    
		    // Return the result
		    return $result;
	    }
	    
	    /**
	     * Obtains the map between properties and column names for a given entity.
	     * This function generates an associative array that links the properties of an entity
	     * to their respective column names in the database. The results are cached
	     * to prevent repeated calculations.
	     * @param mixed $entity The object or class name of the entity.
	     * @return array An associative array with the property as key and the column name as value.
	     */
	    public function getColumnMap(mixed $entity): array {
		    // Determine the class name of the entity
		    if (!is_object($entity)) {
			    $entityClass = ltrim($entity, "\\");
		    } elseif ($entity instanceof \ReflectionClass) {
			    $entityClass = $entity->getName();
		    } else {
			    $entityClass = get_class($entity);
		    }
		    
		    // If the class name is a proxy, get the class from the parent
		    $normalizedClass = $this->normalizeEntityName($entityClass);
		    
		    // Use the cached value if it exists
		    if (isset($this->column_map_cache[$normalizedClass])) {
			    return $this->column_map_cache[$normalizedClass];
		    }
		    
		    // Retrieve all annotations for the entity
		    $annotationList = $this->getAnnotations($normalizedClass);
		    
		    // Loop through all annotations, linked to their respective properties
		    $result = [];
		    
		    foreach ($annotationList as $property => $annotations) {
			    // Get the column name from the annotations
			    foreach ($annotations as $annotation) {
				    if ($annotation instanceof Column) {
					    $result[$property] = $annotation->getName();
					    break;
				    }
			    }
		    }
		    
		    // Cache the result for future use
		    $this->column_map_cache[$normalizedClass] = $result;
		    
		    // Return the result
		    return $result;
	    }
	    
	    /**
	     * Returns the entity's annotations
	     * @param mixed $entity
	     * @return array
	     */
	    public function getAnnotations(mixed $entity): array {
		    // Determine the class name of the entity
		    $entityClass = !is_object($entity) ? ltrim($entity, "\\") : get_class($entity);
		    
		    // If the class name is a proxy, get the class from the parent
		    $normalizedClass = $this->normalizeEntityName($entityClass);
		    
		    // Return the annotation information
		    return $this->entity_annotations[$normalizedClass] ?? [];
	    }
	    
	    /**
	     * Retrieves all OneToOne dependencies for a specific entity.
	     * @param mixed $entity The name of the entity for which you want to get the OneToOne dependencies.
	     * @return OneToOne[] An associative array with the name of the target entity as key and the annotation as value.
	     */
	    public function getOneToOneDependencies(mixed $entity): array {
		    return $this->internalGetDependencies($entity, OneToOne::class);
	    }
	    
	    /**
	     * Retrieve the ManyToOne dependencies for a given entity class.
	     * This function uses annotations to determine which other entities
	     * are related to the given entity class via a ManyToOne relationship.
	     * The names of these related entities are returned as an array.
	     * @param mixed $entity The name of the entity class to inspect.
	     * @return ManyToOne[] An array of entity names with which the given class has a ManyToOne relationship.
	     */
	    public function getManyToOneDependencies(mixed $entity): array {
		    return $this->internalGetDependencies($entity, ManyToOne::class);
	    }
	    
	    /**
	     * Retrieves all OneToMany dependencies for a specific entity.
	     * @param mixed $entity The name of the entity for which you want to get the OneToMany dependencies.
	     * @return OneToMany[] An associative array with the name of the target entity as key and the annotation as value.
	     */
	    public function getOneToManyDependencies(mixed $entity): array {
		    return $this->internalGetDependencies($entity, OneToMany::class);
	    }
	    
	    /**
	     * Internal helper function for retrieving properties with a specific annotation
	     * @param mixed $entity The name of the entity for which you want to get dependencies.
	     * @return array
	     */
	    public function getAllDependencies(mixed $entity): array {
		    // Determine the class name of the entity
		    $entityClass = !is_object($entity) ? ltrim($entity, "\\") : get_class($entity);
		    
		    // If the class name is a proxy, get the class from the parent
		    $normalizedClass = $this->normalizeEntityName($entityClass);
		    
		    // Cache hash
		    $md5OfQuery = hash("sha256", $normalizedClass);
		    
		    // Get dependencies from cache if possible
		    if (isset($this->dependencies_cache[$md5OfQuery])) {
			    return $this->dependencies_cache[$md5OfQuery];
		    }
		    
		    // Get the annotations for the specified class.
		    $annotationList = $this->getAnnotations($normalizedClass);
		    
		    // Loop through each annotation to check for a relationship.
		    $result = [];
		    
		    foreach (array_keys($annotationList) as $property) {
			    foreach ($annotationList[$property] as $annotation) {
				    if ($annotation instanceof OneToMany || $annotation instanceof OneToOne || $annotation instanceof ManyToOne) {
					    $result[$property][] = $annotation;
					    continue 2;
				    }
			    }
		    }
		    
		    $this->dependencies_cache[$md5OfQuery] = $result;
		    return $result;
	    }
	    
	    /**
	     * Returns all entities that depend on the specified entity.
	     * @param mixed $entity The name of the entity for which you want to find dependent entities.
	     * @return array A list of dependent entities.
	     */
	    public function getDependentEntities(mixed $entity): array {
		    // Determine the class name of the entity
		    $entityClass = !is_object($entity) ? ltrim($entity, "\\") : get_class($entity);
		    
		    // If the class name is a proxy, get the parent class
		    $normalizedClass = $this->normalizeEntityName($entityClass);
		    
		    // Get all known entity dependencies
		    $dependencies = $this->getAllEntityDependencies();
		    
		    // Loop through each entity and its dependencies to check for the specified class
		    $result = [];
		    
		    foreach ($dependencies as $entity => $entityDependencies) {
			    // Use array_flip for faster lookups
			    $flippedDependencies = array_flip($entityDependencies);
			    
			    // If the specified class exists in the flipped dependencies list,
			    // add it to the result
			    if (isset($flippedDependencies[$normalizedClass])) {
				    $result[] = $entity;
			    }
		    }
		    
		    // Return the list of dependent entities
		    return $result;
	    }
	    
	    /**
	     * Retrieves the primary key of the main range from an AstRetrieve object.
	     * This function searches through the ranges within the AstRetrieve object and returns the primary key
	     * of the first range that doesn't have a join property. This represents the main entity the query relates to.
	     * @param AstRetrieve $e A reference to the AstRetrieve object representing the query.
	     * @return ?array An array with information about the range and primary key, or null if no suitable range is found.
	     */
	    public function fetchPrimaryKeyOfMainRange(AstRetrieve $e): ?array {
		    foreach ($e->getRanges() as $range) {
			    // Continue if the range contains a join property
			    if ($range->getJoinProperty() !== null) {
				    continue;
			    }
			    
			    // Get the entity name and its associated primary key if the range doesn't have a join property
			    $entityName = $range->getEntity()->getName();
			    $entityNameIdentifierKeys = $this->getIdentifierKeys($entityName);
			    
			    // Return the range name, entity name, and the primary key of the entity
			    return [
				    'range'      => $range,
				    'entityName' => $entityName,
				    'primaryKey' => $entityNameIdentifierKeys[0]
			    ];
		    }
		    
		    // Return null if no range without a join property is found
		    // This should never happen in practice, as such a query cannot be created
		    return null;
	    }
	    
	    /**
	     * Normalizes the primary key into an array.
	     * This function checks if the given primary key is already an array.
	     * If not, it converts the primary key into an array with the proper key
	     * based on the entity type.
	     * @param mixed $primaryKey The primary key to be normalized.
	     * @param string $entityType The type of entity for which the primary key is needed.
	     * @return array A normalized representation of the primary key as an array.
	     */
	    public function formatPrimaryKeyAsArray(mixed $primaryKey, string $entityType): array {
		    // If the primary key is already an array, return it directly.
		    if (is_array($primaryKey)) {
			    return $primaryKey;
		    }
		    
		    // Otherwise, get the identifier keys and create an array with the proper key and value.
		    $identifierKeys = $this->getIdentifierKeys($entityType);
		    return [$identifierKeys[0] => $primaryKey];
	    }
	    
	    /**
	     * Extracts database column definitions from an entity class using reflection and annotations.
	     * @param string $className The fully qualified class name of the entity
	     * @return array An associative array of column definitions indexed by column name
	     */
	    public function extractEntityColumnDefinitions(string $className): array {
		    // Initialize an empty result array to store column definitions
		    $result = [];
		    
		    try {
			    // Initialize type mapper to get default limits for column types
			    $typeMapper = new TypeMapper();
			    
			    // Create a reflection object for the provided class to inspect its properties
			    $reflection = new \ReflectionClass($className);
			    
			    // Iterate through all properties of the class
			    foreach ($reflection->getProperties() as $property) {
				    try {
					    // Retrieve all annotations for the current property
					    $propertyAnnotations = $this->annotation_reader->getPropertyAnnotations($className, $property->getName(), Column::class);
						
					    // If not found, go to the next property
					    if ($propertyAnnotations->isEmpty()) {
						    continue;
					    }
					    
					    // Find the @Orm\Column annotation
					    $columnAnnotation = $propertyAnnotations[Column::class];
					    
					    // Use the column name from the annotation, not the property name
					    $columnName = $columnAnnotation->getName();
					    
					    // Fetch the database column type
					    $columnType = $columnAnnotation->getType();
					    
					    // If no column name found, skip this property
					    if (empty($columnName)) {
						    continue;
					    }
					    
					    // Build a comprehensive array of column metadata
					    $result[$columnName] = [
						    'property_name' => $property->getName(),               // PHP property name
						    'type'          => $columnType,                        // Database column type
						    'php_type'      => $property->getType(),               // PHP type (from reflection)
						    
						    // Get column limit from annotation or use default based on the column type
						    'limit'         => $columnAnnotation->getLimit() ?? $typeMapper->getDefaultLimit($columnType),
						    'nullable'      => $columnAnnotation->isNullable(),    // Whether column allows NULL values
						    'unsigned'      => $columnAnnotation->isUnsigned(),    // Whether numeric column is unsigned
						    'default'       => $columnAnnotation->getDefault(),    // Default value for the column
						    'primary_key'   => $columnAnnotation->isPrimaryKey(),  // Whether column is a primary key
						    'scale'         => $columnAnnotation->getScale(),      // Decimal scale (for numeric types)
						    'precision'     => $columnAnnotation->getPrecision(),  // Decimal precision (for numeric types)
						    
						    // Check if this column is an auto-incrementing identity column
						    'identity'      => $this->isIdentityColumn($propertyAnnotations->toArray()),
					    ];
					} catch (ParserException $e) {
				    }
			    }
		    } catch (\ReflectionException $e) {
			    // Silently handle reflection exceptions
		    }
		    
		    // Return the complete set of column definitions
		    return $result;
	    }
	    
	    /**
	     * This method finds primary key columns that are configured to receive
	     * database-generated values, which are either:
	     * 1. Primary keys with a PrimaryKeyStrategy annotation set to "identity", or
	     * 2. Primary keys with no explicitly defined strategy (defaulting to auto-increment)
	     * @param object $entity The entity to examine
	     * @return string|null The name of the auto-incrementing primary key field, or null if none found
	     */
	    public function findAutoIncrementPrimaryKey(mixed $entity): ?string {
		    // Fetch the owning table of this entity
		    $owningTable = $this->getOwningTable($entity);
		    
		    // Return cached result if available to avoid repeated lookups
		    if (array_key_exists($owningTable, $this->auto_increment_column_cache)) {
			    return $this->auto_increment_column_cache[$owningTable];
		    }
		    
		    // Get all annotations for the entity from the entity store
		    $annotations = $this->getAnnotations($entity);
		    
		    // Process all fields and their annotations
		    foreach ($annotations as $fieldName => $annotationSet) {
			    // Use the isIdentityColumn method to determine if this field
			    // is a primary key with identity strategy or no strategy
			    if ($this->isIdentityColumn($annotationSet->toArray())) {
				    // Found an auto-increment primary key - cache and return immediately
				    return $this->auto_increment_column_cache[$owningTable] = $fieldName;
			    }
		    }
		    
		    // Cache null result to avoid repeated lookups when no matching key exists
		    $this->auto_increment_column_cache[$owningTable] = null;
		    
		    // If we didn't find any matching primary key, return null
		    return null;
	    }
	    
	    /**
	     * Retrieves all index annotations defined for a given entity class
	     * @param mixed $entity The entity class to analyze (can be string classname or object instance)
	     * @return array A collection of Index and UniqueIndex annotation objects
	     */
	    public function getIndexes(mixed $entity): array {
		    // Fetch the owning table of this entity
		    $owningTable = $this->getOwningTable($entity);
		    
		    // Return cached result if available to avoid repeated lookups
		    if (array_key_exists($owningTable, $this->index_cache)) {
			    return $this->index_cache[$owningTable];
		    }
		    
		    try {
			    // Extract all annotation objects attached to the entity class
			    $annotations = $this->annotation_reader->getClassAnnotations($entity);
			    
			    // Filter annotations to only include Index and UniqueIndex types
			    $filteredResults = $annotations->filter(function($annotation) {
				    // Return true only if the annotation is an index type
				    // This keeps both regular indexes and unique indexes
				    return
					    $annotation instanceof Index ||        // Regular index annotation
					    $annotation instanceof UniqueIndex;    // Unique constraint index annotation
			    });
				
				// Cache and return result
			    return $this->index_cache[$owningTable] = $filteredResults->toArray();
		    } catch (ParserException $e) {
			    return [];
		    }
	    }

		/**
	     * Initialize entity classes using the EntityLocator.
	     * This method discovers entity classes, validates them,
	     * and loads their properties and annotations into memory.
	     * @return void
	     */
	    private function initializeEntities(): void {
		    try {
			    // Discover all entities using the EntityLocator
			    $entityClasses = $this->entity_locator->discoverEntities();
			    
			    // Process each discovered entity
			    foreach ($entityClasses as $entityName) {
				    // Initialize data structures for this entity
				    $this->entity_annotations[$entityName] = [];
				    $this->entity_properties[$entityName] = $this->reflection_handler->getProperties($entityName);
				    $this->entity_table_name[$entityName] = $this->annotation_reader->getClassAnnotations($entityName)["Quellabs\\ObjectQuel\\Annotations\\Orm\\Table"]->getName();
				    
				    // Process each property of the entity
				    foreach ($this->entity_properties[$entityName] as $property) {
					    // Get annotations for the current property
					    $annotations = $this->annotation_reader->getPropertyAnnotations($entityName, $property);
					    
					    // Store property annotations in the entity_annotations array
					    $this->entity_annotations[$entityName][$property] = $annotations;
				    }
			    }
		    } catch (\Exception $e) {
			    error_log("Error initializing entities: " . $e->getMessage());
		    }
	    }
		
		/**
		 * Returns a list of entities and their ManyToOne dependencies
		 * @return array
		 */
		private function getAllEntityDependencies(): array {
			if ($this->dependencies === null) {
				$this->dependencies = [];

				foreach (array_keys($this->entity_table_name) as $class) {
					$manyToOneDependencies = $this->getManyToOneDependencies($class);
					$oneToOneDependencies = array_filter($this->getOneToOneDependencies($class), function($e) { return !empty($e->getInversedBy()); });
					
					$this->dependencies[$class] = array_unique(array_merge(
						array_map(function($e) { return $this->normalizeEntityName($e->getTargetEntity()); }, $manyToOneDependencies),
						array_map(function($e) { return $this->normalizeEntityName($e->getTargetEntity()); }, $oneToOneDependencies),
					));
				}
			}
			
			return $this->dependencies;
		}
	    
	    /**
	     * Internal helper functions for retrieving properties with a specific annotation
	     * @param mixed $entity The name of the entity for which you want to get dependencies.
	     * @param string $desiredAnnotationType The type of the dependency
	     * @return array
	     */
	    private function internalGetDependencies(mixed $entity, string $desiredAnnotationType): array {
		    // Determine the class name of the entity
		    if (!is_object($entity)) {
			    $entityClass = ltrim($entity, "\\");
		    } elseif ($entity instanceof \ReflectionClass) {
			    $entityClass = $entity->getName();
		    } else {
			    $entityClass = get_class($entity);
		    }
		    
		    // If the class name is a proxy, get the class from the parent
		    $normalizedClass = $this->normalizeEntityName($entityClass);
		    
		    // Cache hash
		    $md5OfQuery = hash("sha256", $normalizedClass . "##" . $desiredAnnotationType);
		    
		    // Get dependencies from cache if possible
		    if (isset($this->dependencies_cache[$md5OfQuery])) {
			    return $this->dependencies_cache[$md5OfQuery];
		    }
		    
		    // Get the annotations for the specified class
		    $annotationList = $this->getAnnotations($normalizedClass);
		    
		    // Loop through each annotation to check for a relationship
		    $result = [];
		    
		    foreach ($annotationList as $property => $annotations) {
			    foreach ($annotations as $annotation) {
				    if ($annotation instanceof $desiredAnnotationType) {
					    $result[$property] = $annotation;
					    break; // Stop searching if the desired annotation is found
				    }
			    }
		    }
		    
		    $this->dependencies_cache[$md5OfQuery] = $result;
		    return $result;
	    }
	    
	    /**
	     * Determines if a property represents an auto-increment column.
	     *
	     * A column is considered auto-increment if it:
	     * 1. Has a Column annotation marked as primary key, AND
	     * 2. Either:
	     *    - Has a PrimaryKeyStrategy annotation with value 'identity', OR
	     *    - Has no PrimaryKeyStrategy annotation at all (defaulting to auto-increment)
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
    }