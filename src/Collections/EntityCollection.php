<?php
	
	namespace Quellabs\ObjectQuel\Collections;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	
	/**
	 * Collection class for managing entities with lazy-loading capabilities.
	 * @template T of object
	 * @implements CollectionInterface<T>
	 */
	class EntityCollection implements CollectionInterface {
		
		/** @var EntityManager */
		protected EntityManager $entity_manager;
		
		/** @var Collection<T> */
		protected Collection $collection;
		
		/** @var class-string<T> */
		protected string $target_entity;
		
		/** @var string Name of the property */
		protected string $property_name;
		
		/** @var mixed */
		protected mixed $id;
		
		/** @var bool */
		protected bool $initialized;
		
		/**
		 * EntityCollection constructor.
		 * @param EntityManager $entityManager The entity manager handling database operations
		 * @param class-string<T> $targetEntity The fully qualified class name of the target entity
		 * @param string $propertyName The property name in the target entity that maps to the parent id
		 * @param mixed $id The id value of the parent entity
		 * @param string $sortOrder Optional sort order for the collection
		 */
		public function __construct(
			EntityManager $entityManager,
			string $targetEntity,
			string $propertyName,
			mixed $id,
			string $sortOrder = ''
		) {
			$this->entity_manager = $entityManager;
			$this->collection = new Collection($sortOrder);
			$this->target_entity = $targetEntity;
			$this->property_name = $propertyName;
			$this->id = $id;
			$this->initialized = false;
		}

		/**
		 * Returns true if the collection has been initialized (i.e. its data has been loaded
		 * from the database), false if the load is still pending.
		 * @return bool
		 */
		public function isInitialized(): bool {
			return $this->initialized;
		}
		
		/**
		 * Initializes the collection with entities.
		 * This is a lazy-loading mechanism where entities are only loaded
		 * when needed, which improves performance with large datasets.
		 * @return void
		 * @throws QuelException|EntityResolutionException When there's an error loading the entities
		 */
		private function doInitialize(): void {
			// Check if initialization has already been performed to prevent duplicate initialization
			if ($this->initialized) {
				return;
			}

			// Guard against re-entrant initialization: findBy() may load the parent entity and
			// touch this collection again. Reset on failure so the collection remains retryable.
			$this->initialized = true;
			
			try {
				// Load the entities
				$entities = $this->entity_manager->findBy($this->target_entity, [$this->property_name => $this->id]);
				
				// Add each found entity to the collection
				// But first check if the entity is already present in the collection to avoid duplicates
				foreach ($entities as $entity) {
					$this->collection->offsetSet(spl_object_hash($entity), $entity);
				}
			} catch (QuelException $e) {
				// Reset the flag so a subsequent access can attempt initialization again
				$this->initialized = false;
				
				// Re-throw with more context to aid debugging
				throw new QuelException("Failed to initialize entity collection: " . $e->getMessage(), 'initialization_error', 0, $e);
			}
		}
		
		/**
		 * Removes all entities from the list
		 * @return void
		 * @throws QuelException
		 * @throws EntityResolutionException
		 */
		public function clear(): void {
			$this->doInitialize();
			$this->collection->clear();
		}
		
		/**
		 * Returns true if the entity is in the collection, false if not.
		 * @param T $entity The entity to check for
		 * @return bool True if the entity exists in the collection
		 * @throws QuelException|EntityResolutionException
		 */
		public function contains(mixed $entity): bool {
			if (!is_object($entity)) {
				return false;
			}
			
			$this->doInitialize();
			return $this->collection->containsKey(spl_object_hash($entity));
		}
		
		/**
		 * Checks whether the collection is empty (contains no elements).
		 * @return bool
		 * @throws QuelException|EntityResolutionException
		 */
		public function isEmpty(): bool {
			$this->doInitialize();
			return $this->collection->isEmpty();
		}
		
		/**
		 * Returns the element at the current iterator position, or null if the position is invalid.
		 * @return T|null
		 * @throws QuelException|EntityResolutionException
		 */
		public function current(): mixed {
			$this->doInitialize();
			return $this->collection->current();
		}
		
		/**
		 * Returns number of entities
		 * @return int
		 * @throws QuelException|EntityResolutionException
		 */
		public function getCount(): int {
			$this->doInitialize();
			return $this->collection->getCount();
		}
		
		/**
		 * Advances the internal iterator to the next entity and returns that entity.
		 * If no items left, this function returns null
		 * @return void
		 * @throws QuelException|EntityResolutionException
		 */
		public function next(): void {
			$this->doInitialize();
			$this->collection->next();
		}
		
		/**
		 * Checks if the specified offset exists in the collection.
		 * @param int|string $offset The offset to check for
		 * @return bool True if the offset exists
		 * @throws QuelException|EntityResolutionException
		 */
		public function offsetExists(mixed $offset): bool {
			$this->doInitialize();
			return $this->collection->offsetExists($offset);
		}
		
		/**
		 * Returns the element at the specified offset, or null if the offset does not exist.
		 * @param string|int $offset The offset to retrieve
		 * @return T|null
		 * @throws QuelException|EntityResolutionException
		 */
		public function offsetGet(mixed $offset): mixed {
			$this->doInitialize();
			return $this->collection->offsetGet($offset);
		}
		
		/**
		 * Sets an element at the specified offset.
		 * If no offset is provided, uses the entity's object hash as key.
		 * @param string|int|null $offset The offset to assign the value to, or null to append
		 * @param T $value The entity to store
		 * @return void
		 * @throws QuelException|EntityResolutionException
		 */
		public function offsetSet(mixed $offset, mixed $value): void {
			// Run lazy load query if not already done
			$this->doInitialize();
			
			// Do nothing if the value is not an object
			if (!is_object($value)) {
				return;
			}
			
			// No offset. Add value to collection
			if ($offset === null) {
				$this->collection->offsetSet(spl_object_hash($value), $value);
			} else {
				$this->collection->offsetSet($offset, $value);
			}
		}
		
		/**
		 * Removes an entity at the specified offset.
		 * @param int|string $offset The offset to remove
		 * @return void
		 * @throws QuelException|EntityResolutionException
		 */
		public function offsetUnset(mixed $offset): void {
			$this->doInitialize();
			$this->collection->offsetUnset($offset);
		}
		
		/**
		 * Returns the key of the current element in the collection.
		 * @return mixed The current key
		 * @throws QuelException|EntityResolutionException
		 */
		public function key(): mixed {
			$this->doInitialize();
			return $this->collection->key();
		}
		
		/**
		 * Returns true if the current position of the iterator is valid.
		 * @return bool True if the current position is valid
		 * @throws QuelException|EntityResolutionException
		 */
		public function valid(): bool {
			$this->doInitialize();
			return $this->collection->valid();
		}
		
		/**
		 * Rewinds the iterator to the first element in the collection.
		 * @return void
		 * @throws QuelException|EntityResolutionException
		 */
		public function rewind(): void {
			$this->doInitialize();
			$this->collection->rewind();
		}
		
		/**
		 * Returns the number of elements in the collection.
		 * Alias for getCount() to implement Countable.
		 * @return int The number of entities
		 * @throws QuelException|EntityResolutionException
		 */
		public function count(): int {
			$this->doInitialize();
			return $this->collection->count();
		}
		
		/**
		 * Returns an array of keys from the collection.
		 * @return array<int|string> The array of keys
		 * @throws QuelException|EntityResolutionException
		 */
		public function getKeys(): array {
			$this->doInitialize();
			return $this->collection->getKeys();
		}
		
		/**
		 * Adds an entity to the collection if it doesn't already exist.
		 * @param T $entity The entity to add
		 * @return void
		 * @throws QuelException|EntityResolutionException
		 */
		public function add(mixed $entity): void {
			if (!is_object($entity)) {
				return;
			}
			
			$this->doInitialize();
			
			if (!$this->contains($entity)) {
				$this->collection->offsetSet(spl_object_hash($entity), $entity);
			}
		}
		
		/**
		 * Removes an entity from the collection.
		 * @param T $entity The entity to remove
		 * @return bool True if the entity was removed, false if it wasn't in the collection
		 * @throws QuelException|EntityResolutionException
		 */
		public function remove(mixed $entity): bool {
			if (!is_object($entity)) {
				return false;
			}
			
			$this->doInitialize();
			$objectId = spl_object_hash($entity);
			
			if ($this->collection->offsetExists($objectId)) {
				$this->collection->offsetUnset($objectId);
				return true;
			}
			
			return false;
		}
		
		/**
		 * Returns all entities as an array.
		 * @return array<T> Array of entities
		 * @throws QuelException|EntityResolutionException
		 */
		public function toArray(): array {
			$this->doInitialize();
			return $this->collection->toArray();
		}
	}