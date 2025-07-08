<?php
	
	namespace Quellabs\ObjectQuel\Collections;
	
	use Countable;
	use ArrayAccess;
	use Iterator;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	use Quellabs\ObjectQuel\ReflectionManagement\PropertyHandler;

	/**
	 * Collection class for managing entities with lazy-loading capabilities.
	 * @template T of object
	 * @implements CollectionInterface<T>
	 * @implements ArrayAccess<int|string, T>
	 * @implements Iterator<int|string, T>
	 * @implements Countable
	 */
	class EntityCollection implements CollectionInterface {
		
		protected EntityManager $entity_manager;
		protected EntityStore $entity_store;
		protected PropertyHandler $property_handler;
		protected Collection $collection;
		protected mixed $target_entity;
		protected mixed $property_name;
		protected mixed $id;
		protected bool $initialized;
		protected mixed $iterator;
		
		/**
		 * EntityCollection constructor.
		 * @param EntityManager $entityManager The entity manager handling database operations
		 * @param string $targetEntity The fully qualified class name of the target entity
		 * @param string $propertyName The property name in the target entity that maps to the parent id
		 * @param mixed $id The id value of the parent entity
		 * @param string $sortOrder Optional sort order for the collection
		 */
		public function __construct(EntityManager $entityManager, string $targetEntity, string $propertyName, mixed $id, string $sortOrder = '') {
			$this->entity_manager = $entityManager;
			$this->entity_store = $entityManager->getUnitOfWork()->getEntityStore();
			$this->property_handler = $entityManager->getUnitOfWork()->getPropertyHandler();
			$this->collection = new Collection($sortOrder);
			$this->target_entity = $targetEntity;
			$this->property_name = $propertyName;
			$this->id = $id;
			$this->initialized = false;
			$this->iterator = false;
		}
		
		/**
		 * Initializes the collection with entities.
		 * This is a lazy-loading mechanism where entities are only loaded
		 * when needed, which improves performance with large datasets.
		 * @return void
		 * @throws QuelException When there's an error loading the entities
		 */
		private function doInitialize(): void {
			// Check if initialization has already been performed to prevent duplicate initialization
			if ($this->initialized) {
				return;
			}
			
			// Mark as initialized to prevent repeated initialization
			// This prevents querying the database multiple times for the same data
			$this->initialized = true;
			
			// Retrieve entities from the database that match the specified criteria
			// $this->target_entity is the entity class we want to retrieve
			// $this->mapped_id is the name of the field in the entity that references $this->id
			try {
				$entities = $this->entity_manager->findBy($this->target_entity, [$this->property_name => $this->id]);
				
				// Add each found entity to the collection
				// But first check if the entity is already present in the collection to avoid duplicates
				foreach ($entities as $entity) {
					if (!$this->contains($entity)) {
						$this->collection[] = $entity;
					}
				}
			} catch (QuelException $e) {
				// Log the exception or handle it appropriately
				// Re-throw with more context to aid debugging
				throw new QuelException("Failed to initialize entity collection: " . $e->getMessage(), 0, $e);
			}
		}
		
		/**
		 * Removes all entities from the list
		 * @return void
		 * @throws QuelException
		 */
		public function clear(): void {
			$this->doInitialize();
			$this->collection->clear();
		}
		
		/**
		 * Returns true if the entity is in the collection, false if not.
		 * @param object $entity The entity to check for
		 * @return bool True if the entity exists in the collection
		 * @throws QuelException
		 */
		public function contains($entity): bool {
			// For performance, we can check if the entity exists without initializing
			// the entire collection in some cases
			$objectId = spl_object_id($entity);
			
			// If already initialized or the entity is in the collection, return the result
			if ($this->initialized) {
				return $this->collection->containsKey($objectId);
			}
			
			// Otherwise initialize and check
			$this->doInitialize();
			return $this->collection->containsKey($objectId);
		}
		
		/**
		 * Checks whether the collection is empty (contains no elements).
		 * @return bool
		 * @throws QuelException
		 */
		public function isEmpty(): bool {
			$this->doInitialize();
			return $this->collection->isEmpty();
		}
		
		/**
		 * Returns the entity currently pointed to by the internal iterator
		 * @return mixed|null
		 * @throws QuelException
		 */
		public function current(): mixed {
			$this->doInitialize();
			return $this->collection->current();
		}
		
		/**
		 * Returns number of entities
		 * @return int
		 * @throws QuelException
		 */
		public function getCount(): int {
			$this->doInitialize();
			return $this->collection->getCount();
		}
		
		/**
		 * Advances the internal iterator to the next entity and returns that entity.
		 * If no items left, this function returns null
		 * @return void
		 * @throws QuelException
		 */
		public function next(): void {
			$this->doInitialize();
			$this->collection->next();
		}
		
		/**
		 * Checks if the specified offset exists in the collection.
		 * @param int|string $offset The offset to check for
		 * @return bool True if the offset exists
		 * @throws QuelException
		 */
		public function offsetExists(mixed $offset): bool {
			$this->doInitialize();
			return $this->collection->offsetExists($offset);
		}
		
		/**
		 * Gets the entity at the specified offset.
		 * @param int|string $offset The offset to retrieve
		 * @return T|null The entity at the specified offset
		 * @throws QuelException
		 */
		public function offsetGet(mixed $offset): mixed {
			$this->doInitialize();
			return $this->collection->offsetGet($offset);
		}
		
		/**
		 * Sets an entity at the specified offset.
		 * If no offset is provided, uses the entity's object ID.
		 * @param int|string|null $offset The offset to set
		 * @param T $value The entity to store
		 * @return void
		 * @throws QuelException
		 */
		public function offsetSet(mixed $offset, mixed $value): void {
			$this->doInitialize();
			
			if (is_null($offset)) {
				$this->collection->offsetSet(spl_object_id($value), $value);
			} else {
				$this->collection->offsetSet($offset, $value);
			}
		}
		
		/**
		 * Removes an entity at the specified offset.
		 * @param int|string $offset The offset to remove
		 * @return void
		 * @throws QuelException
		 */
		public function offsetUnset(mixed $offset): void {
			$this->doInitialize();
			$this->collection->offsetUnset($offset);
		}
		
		/**
		 * Returns the key of the current element in the collection.
		 * @return mixed The current key
		 * @throws QuelException
		 */
		public function key(): mixed {
			$this->doInitialize();
			return $this->collection->key();
		}
		
		/**
		 * Returns true if the current position of the iterator is valid.
		 * @return bool True if the current position is valid
		 * @throws QuelException
		 */
		public function valid(): bool {
			$this->doInitialize();
			return $this->collection->valid();
		}
		
		/**
		 * Rewinds the iterator to the first element in the collection.
		 * @return void
		 * @throws QuelException
		 */
		public function rewind(): void {
			$this->doInitialize();
			$this->collection->rewind();
		}
		
		/**
		 * Returns the number of elements in the collection.
		 * Alias for getCount() to implement Countable.
		 * @return int The number of entities
		 * @throws QuelException
		 */
		public function count(): int {
			$this->doInitialize();
			return $this->collection->count();
		}
		
		/**
		 * Returns an array of keys from the collection.
		 * @return array<int|string> The array of keys
		 * @throws QuelException
		 */
		public function getKeys(): array {
			$this->doInitialize();
			return $this->collection->getKeys();
		}
		
		/**
		 * Returns true if the object is initialized, false if not.
		 * @return bool True if initialized
		 */
		public function isInitialized(): bool {
			return $this->initialized;
		}
		
		/**
		 * Adds an entity to the collection if it doesn't already exist.
		 * @param T $entity The entity to add
		 * @return void
		 * @throws QuelException
		 */
		public function add($entity): void {
			$this->doInitialize();
			
			if (!$this->contains($entity)) {
				$this->collection->offsetSet(spl_object_id($entity), $entity);
			}
		}
		
		/**
		 * Removes an entity from the collection.
		 * @param T $entity The entity to remove
		 * @return bool True if the entity was removed, false if it wasn't in the collection
		 * @throws QuelException
		 */
		public function remove($entity): bool {
			$this->doInitialize();
			$objectId = spl_object_id($entity);
			
			if ($this->collection->offsetExists($objectId)) {
				$this->collection->offsetUnset($objectId);
				return true;
			}
			
			return false;
		}
		
		/**
		 * Returns all entities as an array.
		 * @return array<T> Array of entities
		 * @throws QuelException
		 */
		public function toArray(): array {
			$this->doInitialize();
			return $this->collection->toArray();
		}
	}