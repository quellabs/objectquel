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
	
	use Quellabs\AnnotationReader\AnnotationReaderLocator;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataBuilder;
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
		
		/** @var Configuration Holds paths, namespaces, etc */
		private Configuration $configuration;
		
		/** @var AnnotationReader Reads the annotations in classes, methods and properties */
		private AnnotationReader $annotationReader;
		
		/** @var ReflectionHandler Reads properties using reflection */
		private ReflectionHandler $reflectionHandler;
		
		/** @var ProxyGenerator Reads and writes entity proxy files for lazy loading */
		private ProxyGenerator $proxyGenerator;
		
		/** @var EntityMetadataBuilder Gives access to entity metadata */
		private EntityMetadataBuilder $metadataBuilder;
		
		/** @var string Namespace to be used for proxies */
		private string $proxyNamespace;
		
		/** @var string Namespace to be added to entities when none given */
		private string $entityNamespace;
		
		// Simple registry of normalized class name => table name
		/** @var array<class-string, string> */
		private array $entityRegistry = [];
		
		// Cache for normalized entity names
		/** @var array<string, string> */
		private array $normalizedNameCache = [];
		
		// Cache for EntityMetadata objects (replaces all the old separate caches)
		/** @var array<string, EntityMetadataRecord> */
		private array $metadataCache = [];
		
		// Dependency graph (calculated once on demand)
		/** @var array<class-string, array<int, class-string>>|null */
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
		 * @throws AnnotationReaderException
		 */
		public function __construct(Configuration $configuration) {
			$this->configuration = $configuration;
			
			// Use the application-wide shared reader if one has been registered (e.g. by Canvas).
			// Sharing one instance means all packages contribute to and benefit from the same
			// in-memory cache, so each class is deserialized from disk at most once per request.
			// Fall back to constructing a local reader when running ObjectQuel standalone.
			$sharedReader = AnnotationReaderLocator::getInstance();
			
			if ($sharedReader !== null) {
				$this->annotationReader = $sharedReader;
			} else {
				$annotationReaderConfiguration = new \Quellabs\AnnotationReader\Configuration();
				$annotationReaderConfiguration->setUseAnnotationCache($configuration->useMetadataCache());
				$annotationReaderConfiguration->setAnnotationCachePath($configuration->getMetadataCachePath());
				$this->annotationReader = new AnnotationReader($annotationReaderConfiguration);
				AnnotationReaderLocator::setInstance($this->annotationReader);
			}
			
			$this->reflectionHandler = new ReflectionHandler();
			$this->proxyNamespace = 'Quellabs\\ObjectQuel\\Proxy\\Runtime';
			$this->entityNamespace = $configuration->getEntityNameSpace();
			
			// Fetch builder
			$this->metadataBuilder = new EntityMetadataBuilder(
				$this,
				$this->annotationReader,
				$this->reflectionHandler,
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
		 * @param string|object $entity Entity object, class name, or ReflectionClass
		 * @return EntityMetadataRecord Immutable metadata object containing all entity information
		 * @throws EntityResolutionException
		 */
		public function getMetadata(string|object $entity): EntityMetadataRecord {
			// Resolve entity name to a
			$className = $this->normalizeEntityClass($entity);
			
			// Return cached metadata if available
			// Otherwise build and cache the metadata
			if (!isset($this->metadataCache[$className])) {
				// Check that the given class actually exists
				if (!class_exists($className)) {
					throw new EntityResolutionException("Invalid entity class: {$className}");
				}
				
				// Add metadata to cache
				$this->metadataCache[$className] = $this->metadataBuilder->build($className);
			}
			
			return $this->metadataCache[$className];
		}
		
		/**
		 * Checks if the entity or its parent exists in the entity registry.
		 * @param string|object $entity The entity to check, either as an object or as a string class name
		 * @return bool True if the entity or its parent class exists in the registry, false otherwise
		 */
		public function exists(string|object $entity): bool {
			try {
				// Determine the class name of the entity
				$normalizedClass = $this->normalizeEntityClass($entity);
				
				// Check that the class exists
				if (!class_exists($normalizedClass)) {
					return false;
				}
				
				// Check if the entity class exists in the entity registry
				if (isset($this->entityRegistry[$normalizedClass])) {
					return true;
				}
				
				// Get the parent class name using the ReflectionHandler
				$parentClass = $this->reflectionHandler->getParent($normalizedClass);
				
				// Check if the parent class exists in the entity registry
				// Return false if neither the entity nor its parent class exists
				return $parentClass !== null && isset($this->entityRegistry[$parentClass]);
			} catch (EntityResolutionException $e) {
				return false;
			}
		}
		
		/**
		 * Normalizes the entity name by resolving proxies and namespaces.
		 * @param string|object $entity Fully qualified class name, short name, object, or ReflectionClass
		 * @return class-string Normalized, fully qualified class name
		 * @throws EntityResolutionException
		 */
		public function normalizeEntityClass(string|object $entity): string {
			// Determine the class name of the entity
			if ($entity instanceof \ReflectionClass) {
				$className = $entity->getName();
			} elseif (is_object($entity)) {
				$className = get_class($entity);
			} else {
				$className = ltrim($entity, "\\");
			}
			
			// Return cached entity name if present
			if (isset($this->normalizedNameCache[$className])) {
				/** @var class-string $cached */
				$cached = $this->normalizedNameCache[$className];
				return $cached;
			}
			
			// Proxy class → resolve to parent
			// If the class name is a proxy, get the parent class name
			if (str_contains($className, $this->proxyNamespace)) {
				if (!class_exists($className)) {
					throw new EntityResolutionException("Invalid entity class: {$className}");
				}
				
				$parent = $this->reflectionHandler->getParent($className);
				
				if ($parent === null || !class_exists($parent)) {
					throw new EntityResolutionException("Cannot resolve parent of proxy class: {$className}");
				}
				
				return $this->normalizedNameCache[$className] = $parent;
			}
			
			// Already fully qualified
			if (str_contains($className, "\\")) {
				if (!class_exists($className)) {
					throw new EntityResolutionException("Invalid entity class: {$className}");
				}
				
				return $this->normalizedNameCache[$className] = $className;
			}
			
			// Try resolving short class name
			$resolved = NamespaceResolver::resolveClassName($className);
			
			if ($resolved === $className) {
				$fullyQualifiedClassName = "{$this->entityNamespace}\\{$className}";
			} else {
				$fullyQualifiedClassName = $resolved;
			}
			
			// Assert existence
			if (!class_exists($fullyQualifiedClassName)) {
				throw new EntityResolutionException("Invalid entity class: {$fullyQualifiedClassName}");
			}
			
			// Add $fullyQualifiedClassName to list
			return $this->normalizedNameCache[$className] = $fullyQualifiedClassName;
		}
		
		/**
		 * Returns all entities that depend on the specified entity.
		 *
		 * This method searches through the dependency graph to find entities that have
		 * a ManyToOne or owning OneToOne relationship to the specified entity.
		 * Useful for determining cascade deletion order and relationship integrity.
		 *
		 * @param string|object $entity The entity for which you want to find dependent entities
		 * @return array<int, class-string> A list of entity class names that depend on the specified entity
		 * @throws EntityResolutionException
		 */
		public function getDependentEntities(string|object $entity): array {
			// Resolve proxy classes to their parent entity class
			$normalizedClass = $this->normalizeEntityClass($entity);
			
			// Filter the dependency graph to entities that list $normalizedClass as a dependency,
			// then return their class names. array_keys on array<class-string, ...> yields array<int, class-string>.
			return array_keys(array_filter(
				$this->getOrderedDependentEntities(),
				fn(array $entityDependencies) => in_array($normalizedClass, $entityDependencies, true)
			));
		}
		
		/**
		 * Resolves the back-reference property name on the target entity for a ManyToOne or OneToOne relation.
		 *
		 * For OneToOne, inversedBy is the primary key property on the target entity that the
		 * foreign key column points to. If not set, the target entity's primary key is used as a fallback.
		 *
		 * For ManyToOne, inversedBy is a direct property name on the target entity. If absent,
		 * the target entity's primary key is used as a fallback.
		 *
		 * Returns null when no property can be determined.
		 *
		 * @param ManyToOne|OneToOne $relation The relation annotation to resolve
		 * @return string|null The back-reference property name on the target entity, or null if unresolvable
		 * @throws EntityResolutionException When target entity metadata cannot be loaded
		 */
		public function resolveTargetProperty(ManyToOne|OneToOne $relation): ?string {
			// Fetch metadata for entity
			$metadata = $this->getMetadata($relation->getTargetEntity());
			
			// OneToOne: return inversedBy, falling back to the primary key
			// ManyToOne: inversedBy is a direct property name on the target entity.
			// If absent, fall back to the target entity's primary key.
			return $relation->getReferencedColumn() ?? $metadata->getPrimaryKey();
		}
		
		// ==================== Private Helper Methods ====================
		
		/**
		 * Initialize entity classes using the EntityLocator.
		 * @return void
		 * @throws AnnotationReaderException
		 */
		private function initializeEntities(): void {
			$entityLocator = new EntityLocator($this->configuration, $this->annotationReader);
			
			foreach ($entityLocator->discoverEntities() as $entityName) {
				// Validate the class exists
				if (!class_exists($entityName)) {
					continue;
				}
				
				// Find all table class annotations
				$table = $this->annotationReader
					->getClassAnnotations($entityName)
					->getFirst(Table::class);
				
				// If none found, skip and continue to the next entity
				if (!$table instanceof Table) {
					continue;
				}
				
				/**
				 * Store in register
				 * @var class-string $entityName
				 */
				$this->entityRegistry[$entityName] = $table->getName();
			}
		}
		
		/**
		 * Build dependency graph for all entities.
		 * @return array<class-string, array<int, class-string>> Entity class name => array of dependent entity class names
		 * @throws EntityResolutionException
		 */
		private function getOrderedDependentEntities(): array {
			// Build the dependency graph only once, then cache it
			if ($this->dependencyGraph === null) {
				$this->dependencyGraph = [];
				
				// Loop through all registered entities
				foreach (array_keys($this->entityRegistry) as $className) {
					// Fetch metadata
					$metadata = $this->getMetadata($className);
					
					// Add ManyToOne dependencies
					// These represent foreign key relationships where this entity depends on another
					$dependencies = [];
					foreach ($metadata->manyToOneRelations as $relation) {
						$dependencies[] = $this->normalizeEntityClass($relation->getTargetEntity());
					}
					
					// Add OneToOne dependencies (owning side only)
					// All stored OneToOne relations are owning-side by definition — the non-owning
					// side is declared with @InverseOf and not stored in oneToOneRelations.
					foreach ($metadata->oneToOneRelations as $relation) {
						$dependencies[] = $this->normalizeEntityClass($relation->getTargetEntity());
					}
					
					// Remove duplicates and store in the dependency graph
					$this->dependencyGraph[$className] = array_unique($dependencies);
				}
			}
			
			return $this->dependencyGraph;
		}
	}