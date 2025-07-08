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
	
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Collections\EntityCollection;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Persistence\DeletePersister;
	use Quellabs\ObjectQuel\Persistence\InsertPersister;
	use Quellabs\ObjectQuel\Persistence\UpdatePersister;
	use Quellabs\ObjectQuel\PrimaryKeys\PrimaryKeyFactory;
	use Quellabs\ObjectQuel\ProxyGenerator\ProxyInterface;
	use Quellabs\ObjectQuel\ReflectionManagement\PropertyHandler;
	use Quellabs\ObjectQuel\Serialization\Serializers\SQLSerializer;
	use Quellabs\SignalHub\SignalHub;
	use Quellabs\SignalHub\SignalHubLocator;
	
	class UnitOfWork {
		
		protected array $original_entity_data;
		protected array $identity_map;
		protected array $entity_removal_list;
		protected EntityManager $entity_manager;
		protected EntityStore $entity_store;
		protected PropertyHandler $property_handler;
		protected ?SQLSerializer $serializer;
		protected ?DatabaseAdapter $connection;
		protected SignalHub $signal_hub;
		protected EntityLifecycleManager $lifecycleManager;
		
		/**
		 * UnitOfWork constructor.
		 * @param EntityManager $entityManager
		 */
		public function __construct(EntityManager $entityManager) {
			$this->connection = $entityManager->getConnection();
			$this->entity_manager = $entityManager;
			$this->entity_store = $entityManager->getEntityStore();
			$this->property_handler = new PropertyHandler();
			$this->serializer = new SQLSerializer($entityManager->getEntityStore());
			$this->original_entity_data = [];
			$this->entity_removal_list = [];
			$this->identity_map = [];
			$this->signal_hub = SignalHubLocator::getInstance();
			
			// Register the signals
			$this->registerLifecycleSignals();
			
			// Create the EntityLifecycleManager instance
			$this->lifecycleManager = new EntityLifecycleManager($this->signal_hub, $this->entity_store);
		}
		
		/**
		 * Returns the property handler object
		 * @return PropertyHandler
		 */
		public function getPropertyHandler(): PropertyHandler {
			return $this->property_handler;
		}
		
		/**
		 * Returns the entity manager object
		 * @return EntityManager
		 */
		public function getEntityManager(): EntityManager {
			return $this->entity_manager;
		}
		
		/**
		 * Returns the entity store object
		 * @return EntityStore
		 */
		public function getEntityStore(): EntityStore {
			return $this->entity_store;
		}
		
		/**
		 * Returns the serializer
		 * @return SQLSerializer
		 */
		public function getSerializer(): SQLSerializer {
			return $this->serializer;
		}
		
		/**
		 * Returns the database adapter
		 * @return DatabaseAdapter|null
		 */
		public function getConnection(): ?DatabaseAdapter {
			return $this->connection;
		}
		
		/**
		 * Find an entity based on its class and primary keys.
		 * @template T of object
		 * @param class-string<T> $entityType The type of entity being searched for.
		 * @param array $primaryKeys The serialized primary key data of the entity
		 * @return object|null The found entity or null if it is not found.
		 */
		public function findEntity(string $entityType, array $primaryKeys): ?object {
			// Normalize the entity name for dealing with proxies
			$normalizedEntityName = $this->getEntityStore()->normalizeEntityName($entityType);
			
			// Check if the class exists in the identity map and return null if it doesn't
			if (empty($this->identity_map[$normalizedEntityName])) {
				return null;
			}
			
			// Convert the primary keys to a string
			$primaryKeyString = $this->convertPrimaryKeysToString($primaryKeys);
			
			// Check if the entity exists in the identity map
			$hash = $this->identity_map[$normalizedEntityName]['index'][$primaryKeyString] ?? null;
			return $hash !== null ? $this->identity_map[$normalizedEntityName][$hash] : null;
		}

		/**
		 * Gets the original data of an entity. The original data is the data that was
		 * present at the time the entity was reconstituted from the database.
		 * @param mixed $entity
		 * @return array|null
		 */
		public function getOriginalEntityData(mixed $entity): ?array {
			return $this->original_entity_data[spl_object_id($entity)] ?? null;
		}
		
		/**
		 * Adds an existing entity to the entity manager's identity map for tracking and change detection.
		 * This method is used for entities that already exist in the database but need to be managed.
		 * @param mixed $entity The entity object to persist and track.
		 * @return void
		 */
		public function persistExisting(mixed $entity): void {
			// Check if the entity class is registered in the entity store
			// If not, it's not a valid entity and should be ignored
			if (!$this->getEntityStore()->exists($entity)) {
				return;
			}
			
			// Check if the entity is already being tracked in the identity map
			// If so, avoid duplicate tracking and exit early
			if ($this->isInIdentityMap($entity)) {
				return;
			}
			
			// Get the normalized class name of the entity to use as a key in the identity map
			// Normalization ensures consistent formatting regardless of namespace notation
			$class = $this->getEntityStore()->normalizeEntityName(get_class($entity));
			
			// Initialize the index structure for this entity class if it doesn't exist yet
			// The index allows for quick entity lookups by primary key without iterating through all entities
			if (!isset($this->identity_map[$class]['index'])) {
				$this->identity_map[$class]['index'] = [];
			}
			
			// Generate a unique object identifier using PHP's built-in function
			// This hash serves as a consistent reference to this specific object instance
			$hash = spl_object_id($entity);
			
			// Get the primary key values for this entity
			// These are used to uniquely identify the entity in the database
			$primaryKeys = $this->getIdentifiers($entity);
			
			// Convert the primary keys to a string representation for indexing
			// This allows for composite keys to be used as array indexes
			$primaryKeysString = $this->convertPrimaryKeysToString($primaryKeys);
			
			// Store the hash in the index for quick lookup by primary key
			// This mapping enables finding entities by their database identifiers
			$this->identity_map[$class]['index'][$primaryKeysString] = $hash;
			
			// Add the actual entity object to the identity map
			// This creates a two-way reference system: hash→entity and primaryKey→hash
			$this->identity_map[$class][$hash] = $entity;
			
			// Create a snapshot of the entity's current state by serializing it
			// This baseline is used later to detect changes when flush() is called
			$this->original_entity_data[$hash] = $this->getSerializer()->serialize($entity);
		}
		
		/**
		 * Adds a new entity to the entity manager's identity map for tracking before database insertion.
		 * This method is specifically for entities that don't yet exist in the database but will be created.
		 * @param mixed $entity The new entity object to persist.
		 * @return bool True if the entity was successfully added to the tracking system, false otherwise.
		 */
		public function persistNew(mixed $entity): bool {
			// Check if the entity is already being tracked in the identity map
			// Prevents duplicate tracking of the same entity instance
			// Returns false because we can't add it as "new" if it's already managed
			if ($this->isInIdentityMap($entity)) {
				return false;
			}
			
			// Verify the entity's class is registered in the entity store as a valid entity type
			// This ensures we only track proper entity objects with appropriate metadata
			// Returns false for non-entity objects to prevent errors in persistence operations
			if (!$this->getEntityStore()->exists($entity)) {
				return false;
			}
			
			// Get the normalized class name to use as an index in the identity map
			// Normalization ensures consistent formatting regardless of how the class was referenced
			$class = $this->getEntityStore()->normalizeEntityName(get_class($entity));
			
			// Generate a unique object identifier for this entity instance
			// This provides a consistent way to reference this specific object in memory
			$hash = spl_object_id($entity);
			
			// Extract primary key values from the entity
			// For new entities, these might be null or empty until after database insertion
			$primaryKeys = $this->getIdentifiers($entity);
			
			// Convert primary keys to a string format for use as array index
			// For new entities without generated IDs, this might be an empty string
			$primaryKeysString = $this->convertPrimaryKeysToString($primaryKeys);
			
			// Add the entity object to the identity map using its hash as the key
			// This registers the entity for tracking in the current unit of work
			$this->identity_map[$class][$hash] = $entity;
			
			// Only index by primary key if the entity already has primary key values
			// This handles both cases: entities with manually set IDs and those awaiting generated IDs
			if (!empty($primaryKeysString)) {
				// Initialize the index array if it doesn't exist yet (using null coalescing operator)
				$this->identity_map[$class]['index'] ??= [];
				
				// Store a reference to the entity by its primary key for quick lookups
				$this->identity_map[$class]['index'][$primaryKeysString] = $hash;
			}
			
			// Return true to indicate successful registration of the new entity
			return true;
		}
		
		/**
		 * Processes and synchronizes all scheduled entities with the database.
		 * This includes starting a transaction, performing the necessary operations (insert, update, delete)
		 * based on the state of each entity, and committing the transaction. In case of an error,
		 * the transaction is rolled back and the error is forwarded.
		 * @param mixed|null $entity
		 * @return void
		 * @throws OrmException if an error occurs during the database process.
		 */
		public function commit(mixed $entity = null): void {
			try {
				// Process cascading persists first to ensure all related entities are managed
				$this->processCascadingPersists();
				
				// Determine the list of entities to process
				if ($entity === null) {
					$sortedEntities = $this->scheduleEntities();
				} elseif (is_array($entity)) {
					$sortedEntities = $entity;
				} else {
					$sortedEntities = [$entity];
				}
				
				if (!empty($sortedEntities)) {
					// Instantiate helper classes
					$primaryKeyFactory = new PrimaryKeyFactory();
					$insertPersister = new InsertPersister($this, $primaryKeyFactory);
					$updatePersister = new UpdatePersister($this);
					$deletePersister = new DeletePersister($this);
					
					// Start a database transaction.
					$this->connection->beginTrans();
					
					// Determine the state of each entity and perform the corresponding action.
					$changed = [];
					$deleted = [];
					
					foreach ($sortedEntities as $entity) {
						// Copy the primary keys from the parent entity to this entity, if available.
						// This only happens if the relationship is not self-referential.
						foreach($this->fetchParentEntitiesPrimaryKeyData($entity) as $parentEntity) {
							$this->property_handler->set($entity, $parentEntity["property"], $parentEntity["value"]);
						}
						
						// Perform the corresponding database operation based on the state of the entity.
						switch ($this->getEntityState($entity)) {
							case DirtyState::New:
								$changed[] = $entity; // Add entity to the changed list
								
								$this->signal_hub->getSignal('orm.prePersist')->emit($entity);
								$insertPersister->persist($entity); // Insert if the entity is new.
								$this->signal_hub->getSignal('orm.postPersist')->emit($entity);
								break;
							
							case DirtyState::Dirty:
								$changed[] = $entity; // Add entity to the changed list
								
								$this->signal_hub->getSignal('orm.preUpdate')->emit($entity);
								$updatePersister->persist($entity); // Update if the entity has been modified.
								$this->signal_hub->getSignal('orm.postUpdate')->emit($entity);
								break;
							
							case DirtyState::Deleted:
								$deleted[] = $entity; // Add entity to the deleted list
								
								$this->signal_hub->getSignal('orm.preDelete')->emit($entity);
								$deletePersister->persist($entity); // Delete if the entity is marked for deletion.
								$this->signal_hub->getSignal('orm.postDelete')->emit($entity);
								break;
						}
					}
					
					// Commit the transaction after successful processing.
					$this->connection->commitTrans();
					
					// Update the identity map and reset change tracking
					$this->updateIdentityMapAndResetChangeTracking($changed, $deleted);
				}
			} catch (OrmException $e) {
				// Roll back the transaction if an error occurs.
				$this->connection->rollbackTrans();
				
				// Re-throw the exception to allow handling elsewhere.
				throw $e;
			}
		}
		
		/**
		 * Clear the entity map
		 * @return void
		 */
		public function clear(): void {
			$this->identity_map = [];
			$this->original_entity_data = [];
			$this->entity_removal_list = [];
		}
		
		/**
		 * Detach an entity from the EntityManager.
		 * This will remove the entity from the identity map and stop tracking its changes.
		 * Detached entities are no longer managed, so their changes won't be persisted to the database.
		 * @param object $entity The entity object to detach from management.
		 * @return void
		 */
		public function detach(object $entity): void {
			// Generate a unique identifier for the entity instance using PHP's built-in function
			// This hash is used as a key in various tracking collections
			$hash = spl_object_id($entity);
			
			// Get the normalized class name of the entity for consistent identity map access
			// This handles potential differences in namespace notation
			$class = $this->getEntityStore()->normalizeEntityName(get_class($entity));
			
			// Remove the entity from the main identity map using its hash
			// This stops the entity from being included in any future persistence operations
			unset($this->identity_map[$class][$hash]);
			
			// Search for this entity's hash in the primary key index
			// The index maps primary key strings to object hashes for quick lookups
			$index = array_search($hash, $this->identity_map[$class]['index']);
			
			// If found in the index, remove it to prevent the detached entity from being
			// retrieved via its primary key in future operations
			if ($index !== false) {
				unset($this->identity_map[$class]['index'][$index]);
			}
			
			// Remove the entity's original data snapshot used for change detection
			// This effectively stops tracking any changes to the entity's properties
			unset($this->original_entity_data[$hash]);
			
			// If the entity was previously scheduled for deletion, remove it from that list
			// This prevents it from being included in the next DELETE operation
			unset($this->entity_removal_list[$hash]);
		}
		
		/**
		 * Adds an entity to the removal list and handles cascading delete operations
		 * @param object $entity The entity to schedule for deletion
		 * @return void
		 */
		public function scheduleForDelete(object $entity): void {
			$entityId = spl_object_id($entity);
			
			// Skip if already scheduled for deletion to prevent duplicate processing
			if ($this->isEntityScheduledForDeletion($entityId)) {
				return;
			}
			
			// Mark entity for deletion first (prevents infinite recursion with circular references)
			$this->entity_removal_list[$entityId] = true;
			
			// Process dependent entities that should be cascade deleted
			$this->processCascadingDeletions($entity);
		}

		/**
		 * Determines the state of an entity (e.g., new, modified, not managed, etc.).
		 * @param mixed $entity The entity whose state needs to be determined.
		 * @return int The state of the entity, represented as a constant from DirtyState.
		 */
		private function getEntityState(mixed $entity): int {
			// Checks if the entity is not being managed.
			if (!$this->isInIdentityMap($entity)) {
				return DirtyState::NotManaged;
			}
			
			// Class and hash of the entity object for identification.
			$entityHash = spl_object_id($entity);
			
			// Checks if the entity appears in the deleted list, if so, then the state is Deleted
			if ($this->isEntityScheduledForDeletion($entityHash)) {
				return DirtyState::Deleted;
			}
			
			// Checks if the entity is new based on the absence of original data.
			if (!isset($this->original_entity_data[$entityHash])) {
				return DirtyState::New;
			}
			
			// Checks if the entity is new based on the absence of primary keys.
			$primaryKeys = $this->entity_store->getIdentifierKeys($entity);
			
			if ($this->hasNullPrimaryKeys($entity, $primaryKeys)) {
				return DirtyState::New;
			}
			
			// Checks if the entity has been modified compared to the original data.
			$originalData = $this->getOriginalEntityData($entity);
			$serializedEntity = $this->getSerializer()->serialize($entity);
			
			if ($this->isEntityDirty($serializedEntity, $originalData)) {
				return DirtyState::Dirty;
			}
			
			// If none of the above conditions are true, then the entity is not modified.
			return DirtyState::None;
		}
		
		/**
		 * Convert primary keys to a string
		 * @param array $primaryKeys
		 * @return string
		 */
		private function convertPrimaryKeysToString(array $primaryKeys): string {
			// Ensure consistent order
			ksort($primaryKeys);
			
			// Use http_build_query for performance and simplicity
			return str_replace(['&', '='], [';', ':'], http_build_query($primaryKeys));
		}
		
		/**
		 * Returns true if the entity has no populated primary keys, false if it does.
		 * @param object $entity
		 * @param array $primaryKeys
		 * @return bool
		 */
		private function hasNullPrimaryKeys(object $entity, array $primaryKeys): bool {
			foreach ($primaryKeys as $primaryKey) {
				if ($this->property_handler->get($entity, $primaryKey) === null) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Returns true if any of the entity columns changed, false if not.
		 * @param array $extractedEntity
		 * @param array $originalData
		 * @return bool
		 */
		private function isEntityDirty(array $extractedEntity, array $originalData): bool {
			foreach ($extractedEntity as $key => $value) {
				if ($value !== $originalData[$key]) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Checks if an entity is already scheduled for deletion
		 * @param int $entityId The entity's object ID
		 * @return bool
		 */
		private function isEntityScheduledForDeletion(int $entityId): bool {
			return isset($this->entity_removal_list[$entityId]);
		}
		
		/**
		 * Retrieves a property value from an entity object using getter method or property handler.
		 * @param mixed $entity The object to extract the value from
		 * @param string $property The property name to retrieve
		 * @return mixed           The value of the requested property
		 */
		private function getValueFromEntity(mixed $entity, string $property): mixed {
			// Generate the getter method name by capitalizing the first letter of the property
			$getterMethod = 'get' . ucfirst($property);
			
			// Check if the entity has the getter method and call it if exists
			if (method_exists($entity, $getterMethod)) {
				return $entity->$getterMethod();
			}
			
			// Fallback to using the property handler if no getter method exists
			// This likely accesses properties through alternative means (e.g., reflection)
			return $this->property_handler->get($entity, $property);
		}
		
		/**
		 * Flattens the identity map while preserving unique keys.
		 * This method converts the nested identity map structure into a simple hash => entity mapping,
		 * which is useful for operations that need to iterate through all managed entities regardless of class.
		 * @return array An associative array of entity object IDs to entity objects
		 */
		private function getFlattenedIdentityMap(): array {
			// Initialize the result array that will hold all entities
			// The result will be a flat map of hash => entity pairs
			$result = [];
			
			// Loop through each entity class in the identity map
			// The identity_map is structured as [entityClass => [objectId => entity, ...], ...]
			foreach ($this->identity_map as $subArray) {
				// For each class, loop through all the stored entities and meta-entries
				foreach ($subArray as $key => $value) {
					// Skip the special 'index' entry which contains lookup maps for primary keys
					// This is not an actual entity but rather metadata used for efficient lookups
					if ($key === 'index') {
						continue;
					}
					
					// Skip proxy objects that haven't been initialized yet
					// Uninitialized proxies are placeholders without complete entity data
					// Including them could lead to unexpected lazy-loading during operations
					if (($value instanceof ProxyInterface) && !$value->isInitialized()) {
						continue;
					}
					
					// Add the entity to our result using its object ID as the key
					// This preserves the unique mapping while flattening the structure
					// The key is the object's unique hash, and the value is the entity object itself
					$result[$key] = $value;
				}
			}
			
			// Return the flattened map of all actively managed entities
			// This can be used for operations that need to process all entities regardless of type
			return $result;
		}
		
		/**
		 * Sorts an array of entities based on their ManyToOne relationships.
		 * Uses topological sorting to ensure that 'parent' entities come first
		 * and 'child' entities later. This is necessary to maintain the integrity
		 * of database relationships when inserting or updating records.
		 *
		 * For example, if Entity B has a foreign key to Entity A, Entity A must be
		 * persisted first to have a valid ID for Entity B to reference.
		 *
		 * @return array The sorted entities in the correct insertion/update order.
		 * @throws OrmException When a cycle is detected in the entity relations,
		 * indicating an unresolvable dependency between entities (e.g., A depends on B, B depends on A).
		 */
		private function scheduleEntities(): array {
			// Initialize the data structures for topological sorting algorithm.
			$graph = []; // Adjacency list representation of the entity dependency graph.
			$inDegree = []; // Tracks the number of dependencies (incoming edges) for each entity.
			$flattenedIdentityMap = $this->getFlattenedIdentityMap(); // Get all managed entities as a flat array.
			
			// Prepare the graph and inDegree counters for each entity.
			// This initializes every entity with an empty list of dependents and zero dependencies.
			foreach ($flattenedIdentityMap as $hash => $entity) {
				$graph[$hash] = []; // Initialize an empty array of dependent entities (children)
				$inDegree[$hash] = 0; // Initially, assume entity has no dependencies on other entities
			}
			
			// Build the dependency graph by examining each entity's relationships
			foreach ($flattenedIdentityMap as $hash => $entity) {
				// Get all ManyToOne relationships where this entity is the "many" side
				// These are dependencies where this entity depends on a parent entity
				$manyToOneParents = $this->getEntityStore()->getManyToOneDependencies($entity);
				
				// Get all OneToOne relationships where this entity is the owning side
				$oneToOneParents = $this->getEntityStore()->getOneToOneDependencies($entity);
				
				// Filter OneToOne relationships to only include those that are bidirectional
				// This is determined by checking if inversedBy is not empty
				$oneToOneParents = array_filter($oneToOneParents, function ($e) {
					return !empty($e->getInversedBy());
				});
				
				// Process all parent dependencies (both ManyToOne and qualifying OneToOne)
				foreach (array_merge($manyToOneParents, $oneToOneParents) as $property => $annotation) {
					// Get the actual parent entity object from the current entity's property
					$parentEntity = $this->property_handler->get($entity, $property);
					
					// Skip if the relationship is null (no parent entity assigned)
					if ($parentEntity === null) {
						continue;
					}
					
					// Skip if the parent is a proxy (lazy-loaded) that hasn't been initialized
					// Including uninitialized proxies could trigger unwanted database queries
					if (($parentEntity instanceof ProxyInterface) && !$parentEntity->isInitialized()) {
						continue;
					}
					
					// Get a unique identifier for the parent entity
					$parentId = spl_object_id($parentEntity);
					
					// Register the dependency in our graph:
					// 1. Add current entity as a dependent (child) of the parent
					// This means: "When processing parentId, we'll need to process hash afterwards"
					$graph[$parentId][] = $hash;
					
					// 2. Increment the dependency counter for the current entity
					// This means: "This entity depends on one more entity that must be processed first"
					$inDegree[$hash]++;
				}
			}
			
			// Begin topological sorting using Kahn's algorithm
			$queue = []; // Queue for processing entities that have no remaining dependencies
			$result = []; // Output array of sorted entity IDs
			
			// First, find all entities that have no dependencies (inDegree = 0)
			// These are "root" entities that can be safely processed first
			foreach ($inDegree as $id => $degree) {
				if ($degree === 0) {
					$queue[] = $id;
				}
			}
			
			// Process entities in dependency order
			while (!empty($queue)) {
				// Get the next entity that has no unresolved dependencies
				$current = array_shift($queue);
				
				// Add it to our sorted result
				$result[] = $current;
				
				// For each entity that depends on the current one
				foreach ($graph[$current] as $neighbor) {
					// Decrement its dependency counter since we've processed one of its dependencies
					$inDegree[$neighbor]--;
					
					// If all dependencies for this entity are now satisfied, add it to the processing queue
					if ($inDegree[$neighbor] === 0) {
						$queue[] = $neighbor;
					}
				}
			}
			
			// Detect circular dependencies by checking if we processed all entities
			// If the sorted result doesn't include all entities, there must be a cycle
			if (count($result) !== count($flattenedIdentityMap)) {
				throw new OrmException("There is a cycle in the entity relationships.");
			}
			
			// Convert the sorted list of entity IDs back to the actual entity objects
			// This maintains the correct dependency order for database operations
			return array_map(fn($id) => $flattenedIdentityMap[$id], $result);
		}
		
		/**
		 * Checks if a given entity is present in the identity map.
		 * @param mixed $entity The entity to check.
		 * @return bool Returns true if the entity is in the identity map, otherwise false.
		 */
		private function isInIdentityMap(mixed $entity): bool {
			// Get the normalized class name of the entity.
			$normalizedEntityName = $this->getEntityStore()->normalizeEntityName(get_class($entity));
			
			// Check if the class name does not exist in the identity map.
			if (!isset($this->identity_map[$normalizedEntityName])) {
				return false;
			}
			
			// Check if the object itself exists in the identity map using its unique ID.
			return isset($this->identity_map[$normalizedEntityName][spl_object_id($entity)]);
		}
		
		/**
		 * Update the tracking information
		 * @param array $changed List of changed entities
		 * @param array $deleted List of deleted entities
		 * @return void
		 */
		private function updateIdentityMapAndResetChangeTracking(array $changed, array $deleted): void {
			foreach ($changed as $entity) {
				// Get the unique object identifier
				$hash = spl_object_id($entity);
				
				// Get the normalized class name of the entity
				$class = $this->getEntityStore()->normalizeEntityName(get_class($entity));
				
				// Add primary key to index cache for easy lookup
				$primaryKeys = $this->getIdentifiers($entity);
				$primaryKeysString = $this->convertPrimaryKeysToString($primaryKeys);
				$this->identity_map[$class]['index'][$primaryKeysString] = $hash;
				
				// Store the original data of the entity for later comparison
				// This helps track changes in the entity over time
				$this->original_entity_data[$hash] = $this->getSerializer()->serialize($entity);
			}
			
			// Remove deleted entities from tracking
			foreach ($deleted as $entity) {
				$this->detach($entity);
			}
		}
		
		/**
		 * This function retrieves the parent entity and the corresponding ManyToOne annotation
		 * for the given entity. If the parent entity doesn't exist, null is returned.
		 * @param mixed $entity The entity for which to retrieve the parent entity and annotation.
		 * @return array An associative array with 'entity' and 'annotation' as keys, or null if not found.
		 */
		private function fetchParentEntitiesPrimaryKeyData(mixed $entity): array {
			// Initialize an empty array to store the results.
			// This will hold all parent entities and their relationship data
			$result = [];
			
			// Retrieve all annotations associated with the given entity.
			// Annotations contain metadata about the entity's properties and relationships
			$annotationList = $this->getEntityStore()->getAnnotations($entity);
			
			// Loop through each set of annotations for each property of the entity.
			// Each property might have multiple annotations defining its behavior
			foreach ($annotationList as $property => $annotations) {
				// Loop through the annotations for a single property of the entity.
				foreach ($annotations as $annotation) {
					// Check if the current annotation is a ManyToOne annotation or a bidirectional OneToOne.
					// These types indicate a dependency on a parent entity
					if (
						!($annotation instanceof ManyToOne) &&
						(!($annotation instanceof OneToOne) || is_null($annotation->getInversedBy()))
					) {
						continue; // Skip this annotation if it's not a relationship to a parent
					}
					
					// Use the property_handler to retrieve the value of the related parent entity.
					// This is the actual object reference to the parent entity
					$parentEntity = $this->property_handler->get($entity, $property);
					
					// If the parent entity exists, add it to the result with its relationship details.
					if (!empty($parentEntity)) {
						$result[] = [
							'entity'   => $parentEntity, // The parent entity itself
							'property' => $annotation->getRelationColumn(), // The name of the property that defines the relationship
							'value'    => $this->property_handler->get($parentEntity, $annotation->getInversedBy()) // The value of the inverse relationship
						];
					}
					
					// We don't need to read more annotations for this property.
					// Move on to the next property.
					continue 2; // Skip to the next property in the outer loop
				}
			}
			
			// Return the found parent entities as an array.
			// This will be used to ensure proper order of operations during persistence
			return $result;
		}
		
		/**
		 * Retrieves the identifiers (primary keys) of the given entity.
		 * @param mixed $entity The entity from which to retrieve the primary keys.
		 * @return array An associative array where the keys are the primary key names and
		 *               the values are their corresponding values from the entity.
		 */
		private function getIdentifiers(mixed $entity): array {
			// Fetch the primary key names from the entity store
			$primaryKeys = $this->getEntityStore()->getIdentifierKeys($entity);
			
			// Initialize the result array to hold key-value pairs of primary keys
			$result = [];
			
			// Loop through each primary key name
			foreach ($primaryKeys as $key) {
				// Fetch the corresponding value for each primary key from the entity using the property handler
				$result[$key] = $this->property_handler->get($entity, $key);
			}
			
			// Return the array of primary key names and their corresponding values
			return $result;
		}
		
		/**
		 * Process cascading deletions for dependent entities.
		 * When an entity is deleted, this method ensures that all dependent entities
		 * with cascade delete configurations are also properly marked for deletion.
		 * This maintains referential integrity in the database by removing child records
		 * that would otherwise become orphaned.
		 * @param object $entity The parent entity being deleted
		 * @return void
		 */
		private function processCascadingDeletions(object $entity): void {
			// Get the fully qualified class name of the entity
			// This identifies the exact entity type we're dealing with
			$entityClass = get_class($entity);
			
			// Normalize the entity class name to ensure consistent format
			// Normalization handles variations in namespace notation
			$normalizedClass = $this->entity_store->normalizeEntityName($entityClass);
			
			// Retrieve all entity classes that depend on this entity
			// These are entities that have relationships annotated with cascade="remove"
			// or similar configurations that indicate cascading deletes
			$dependentEntityClasses = $this->entity_store->getDependentEntities($entity);
			
			// Process each dependent entity class to find and mark instances for deletion
			// This handles OneToMany and OneToOne relationships where the parent is being deleted
			foreach ($dependentEntityClasses as $dependentEntityClass) {
				// For each dependent class, process all instances that reference this entity
				// This delegates the actual work to a specialized method that handles
				// the complexities of identifying and marking dependent entities
				$this->processDependentEntityClass($dependentEntityClass, $normalizedClass, $entity);
			}
		}
		
		/**
		 * Process a specific dependent entity class for cascading deletion.
		 * This method examines relationships from the dependent entity to the parent,
		 * checks for cascade configurations, and marks appropriate instances for deletion.
		 * @param string $dependentEntityClass The fully qualified class name of the dependent entity type
		 * @param string $normalizedClass The normalized class name of the parent entity being deleted
		 * @param object $entity The parent entity object instance being deleted
		 * @return void
		 */
		private function processDependentEntityClass(string $dependentEntityClass, string $normalizedClass, object $entity): void {
			// Retrieve all ManyToOne relationships defined in the dependent entity class
			// These are relationships where the dependent entity has a foreign key to some parent
			$manyToOneDependencies = $this->entity_store->getManyToOneDependencies($dependentEntityClass);
			
			// Retrieve all OneToOne relationships defined in the dependent entity class
			// These are one-to-one associations between entities
			$oneToOneDependencies = $this->entity_store->getOneToOneDependencies($dependentEntityClass);
			
			// Filter OneToOne relationships to only include bidirectional ones
			// We only want relationships where both sides reference each other
			// This is determined by checking if the inversedBy property is set
			$oneToOneDependencies = array_filter($oneToOneDependencies, function ($e) {
				return !empty($e->getInversedBy());
			});
			
			// Process both ManyToOne and filtered OneToOne relationships together
			foreach (array_merge($manyToOneDependencies, $oneToOneDependencies) as $property => $annotation) {
				// Skip if this relationship doesn't point to our parent entity class
				// This ensures we only process relationships relevant to the deleted entity
				if ($annotation->getTargetEntity() !== $normalizedClass) {
					continue;
				}
				
				// Get cascade configuration information for this relationship
				// This tells us how changes to the parent should propagate to dependents
				$cascadeInfo = $this->getCascadeInfo($dependentEntityClass, $property);
				
				// Skip if no cascade configuration exists or if cascade remove isn't enabled
				// Not all relationships should trigger cascading deletions
				if (!$cascadeInfo || !$this->shouldCascadeRemove($cascadeInfo)) {
					continue;
				}
				
				// If we reach here, this relationship should trigger cascading deletion
				// Find and mark for deletion all dependent objects that reference this parent
				// The relationship column is used to identify which dependent objects reference this parent
				$this->cascadeDeleteDependentObjects(
					$dependentEntityClass,             // The class of dependent objects to search for
					$annotation->getRelationColumn(),  // The column/property that references the parent
					$entity                            // The parent entity being deleted
				);
			}
		}
		
		/**
		 * Get cascade configuration for a property
		 * @param string $entityClass Entity class name
		 * @param string $property Property name
		 * @return object|null The cascade annotation if found, null otherwise
		 */
		private function getCascadeInfo(string $entityClass, string $property): ?object {
			// Retrieve all annotations for the specified entity class from the entity store
			$entityAnnotations = $this->entity_store->getAnnotations($entityClass);
			
			// Check if the specified property exists in the entity annotations
			// If not, return null immediately since no cascade can exist
			if (!isset($entityAnnotations[$property])) {
				return null;
			}
			
			// Filter annotations to find only those that are instances of the Cascade class
			// This separates cascade annotations from other annotation types
			$cascadeAnnotations = array_filter($entityAnnotations[$property]->toArray(), function ($a) {
				return $a instanceof Cascade;
			});
			
			// If no cascade annotations were found for this property, return null
			if (empty($cascadeAnnotations)) {
				return null;
			}
			
			// Return the first cascade annotation found
			// The reset() function returns the first element of an array without affecting the internal pointer
			return reset($cascadeAnnotations);
		}
		
		/**
		 * Determines if cascading removal should be performed
		 * @param object $cascadeAnnotation The cascade annotation object
		 * @return bool True if cascading removal should be performed
		 */
		private function shouldCascadeRemove(object $cascadeAnnotation): bool {
			// Skip if 'remove' operation not present in the cascade operations list
			if (!in_array('remove', $cascadeAnnotation->getOperations())) {
				return false;
			}
			
			// Skip database-level cascades (handled by DB itself)
			if ($cascadeAnnotation->getStrategy() === 'database') {
				return false;
			}
			
			// Yes, cascading removal should be performed
			return true;
		}
		
		/**
		 * Determines if cascading persist should be performed
		 * @param object $cascadeAnnotation The cascade annotation object
		 * @return bool True if cascading removal should be performed
		 */
		private function shouldCascadePersist(object $cascadeAnnotation): bool {
			return in_array('persist', $cascadeAnnotation->getOperations());
		}
		
		/**
		 * Find and schedule deletion of dependent objects
		 * @param string $dependentEntityClass Class name of dependent entity
		 * @param string $property Property name with the relationship
		 * @param object $parentEntity The parent entity object
		 * @return void
		 */
		private function cascadeDeleteDependentObjects(string $dependentEntityClass, string $property, object $parentEntity): void {
			// Get the relationship value from the parent entity
			$propertyValue = $this->getValueFromEntity($parentEntity, $property);
			
			// Find dependent objects using the property value
			$dependentObjects = $this->entity_manager->findBy($dependentEntityClass, [
				$property => $propertyValue
			]);
			
			// Schedule each dependent object for deletion
			foreach ($dependentObjects as $dependentObject) {
				$this->scheduleForDelete($dependentObject);
			}
		}
		
		/**
		 * Process cascading persists for all entities scheduled for insertion
		 * This method implements the persistence-by-reachability pattern, ensuring
		 * that all entities connected via cascade-persist relationships are properly
		 * handled during the persistence operation.
		 * @return void
		 */
		private function processCascadingPersists(): void {
			// Get flattened identity map to process all managed entities
			// The flattened map ensures we have a single-dimensional array of all tracked objects
			$entitiesToProcess = $this->getFlattenedIdentityMap();
			
			// Process each entity for cascade persists
			// We need to examine every managed entity to check for cascade relationships
			foreach ($entitiesToProcess as $entity) {
				// Fetch the object id of this entity
				$entityId = spl_object_id($entity);
				
				// Skip if the entity is scheduled for deletion
				// No need to process cascade persists for entities that will be removed anyway
				if ($this->isEntityScheduledForDeletion($entityId)) {
					continue;
				}
				
				// Process cascade persist operations for this entity's associations
				// This will identify any related entities that should also be persisted
				$this->processCascadingPersistsForEntity($entity);
			}
		}
		
		/**
		 * Process cascading persists for a single entity.
		 * This method handles the automatic persistence of related entities when
		 * they are configured with cascade=persist in their relationship annotations.
		 * @param object $entity The entity whose relationships should be checked for cascade persist
		 * @return void
		 */
		private function processCascadingPersistsForEntity(object $entity): void {
			// Process OneToMany relationships
			$this->processCascadingOneToManyPersists($entity);
			
			// Process OneToOne relationships
			$this->processCascadingOneToOnePersists($entity);
		}
		
		/**
		 * Process cascading persists for OneToMany relationships of an entity.
		 * This handles collections of related entities that should be persisted
		 * when the parent entity is persisted.
		 * @param object $entity The entity whose OneToMany relationships should be processed
		 * @return void
		 */
		private function processCascadingOneToManyPersists(object $entity): void {
			// Get OneToMany dependencies for this entity
			$oneToManyDependencies = $this->getEntityStore()->getOneToManyDependencies($entity);
			
			// Check each OneToMany relationship defined in this entity
			foreach ($oneToManyDependencies as $property => $annotation) {
				// Retrieve cascade configuration from metadata for this property
				$cascadeInfo = $this->getCascadeInfo(get_class($entity), $property);
				
				// Skip this relationship if:
				// 1. No cascade configuration exists for it, or
				// 2. Cascade persist is not enabled in the configuration
				if (!$cascadeInfo || !$this->shouldCascadePersist($cascadeInfo)) {
					continue;
				}
				
				// Get the actual collection of related entities from the entity's property
				$collection = $this->property_handler->get($entity, $property);
				
				// Skip if the collection property is null (no collection initialized)
				if ($collection === null) {
					continue;
				}
				
				// Skip uninitialized collections to prevent lazy loading
				if ($collection instanceof EntityCollection && !$collection->isInitialized()) {
					continue;
				}
				
				// Iterate through each entity in the collection
				foreach ($collection as $relatedEntity) {
					// Check if the related entity is already being tracked in the identity map
					if ($this->isInIdentityMap($relatedEntity)) {
						continue;
					}
					
					// Add the related entity to the identity map for tracking
					$this->persistNew($relatedEntity);
					
					// Recursively process the related entity's own cascading relationships
					$this->processCascadingPersistsForEntity($relatedEntity);
				}
			}
		}
		
		/**
		 * Process cascading persists for OneToOne relationships of an entity.
		 * This handles single related entities that should be persisted
		 * when the parent entity is persisted.
		 * @param object $entity The entity whose OneToOne relationships should be processed
		 * @return void
		 */
		private function processCascadingOneToOnePersists(object $entity): void {
			// Get OneToOne dependencies for this entity
			$oneToOneDependencies = $this->getEntityStore()->getOneToOneDependencies($entity);
			
			// Check each OneToOne relationship defined in this entity
			foreach ($oneToOneDependencies as $property => $annotation) {
				// Skip if this is the inverse (non-owning) side of the relationship
				if (empty($annotation->getMappedBy())) {
					continue;
				}
				
				// Retrieve cascade configuration from metadata for this property
				$cascadeInfo = $this->getCascadeInfo(get_class($entity), $property);
				
				// Skip this relationship if cascade persist is not enabled
				if (!$cascadeInfo || !$this->shouldCascadePersist($cascadeInfo)) {
					continue;
				}
				
				// Get the single related entity from the entity's property
				$relatedEntity = $this->property_handler->get($entity, $property);
				
				// Skip if no related entity exists (the property is null)
				if ($relatedEntity === null) {
					continue;
				}
				
				// Skip uninitialized proxies to prevent lazy loading
				if ($relatedEntity instanceof ProxyInterface && !$relatedEntity->isInitialized()) {
					continue;
				}
				
				// Check if the related entity is already being tracked
				if ($this->isInIdentityMap($relatedEntity)) {
					continue;
				}
				
				// Add the related entity to the identity map for tracking
				$this->persistNew($relatedEntity);
				
				// Recursively process the related entity's own cascading relationships
				$this->processCascadingPersistsForEntity($relatedEntity);
			}
		}
		
		/**
		 * Create the lifetime signals
		 * @return void
		 */
		private function registerLifecycleSignals(): void {
			// Define standard ORM lifecycle signals
			$this->signal_hub->createSignal('orm.prePersist', ['object']);
			$this->signal_hub->createSignal('orm.postPersist', ['object']);
			$this->signal_hub->createSignal('orm.preUpdate', ['object']);
			$this->signal_hub->createSignal('orm.postUpdate', ['object']);
			$this->signal_hub->createSignal('orm.preDelete', ['object']);
			$this->signal_hub->createSignal('orm.postDelete', ['object']);
		}
	}