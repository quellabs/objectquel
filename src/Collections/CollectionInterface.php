<?php
	
	namespace Quellabs\ObjectQuel\Collections;
	
	/**
	 * @template T of object
	 * @extends \ArrayAccess<int, T>
	 * @extends \Iterator<int, T>
	 */
	interface CollectionInterface extends \ArrayAccess, \Iterator, \Countable {
		
		/**
		 * Returns true if the collection has been initialized (i.e. its data is loaded and ready),
		 * false if initialization is still pending.
		 * @return bool
		 */
		public function isInitialized(): bool;
		
		/**
		 * Removes all elements from the collection.
		 * @return void
		 */
		public function clear(): void;
		
		/**
		 * Returns true if the collection contains the specified entity, false if not.
		 * @param T $entity The entity to search for
		 * @return bool
		 */
		public function contains(object $entity): bool;
		
		/**
		 * Returns true if the collection contains no elements, false if not.
		 * @return bool
		 */
		public function isEmpty(): bool;
		
		/**
		 * Returns the number of elements in the collection.
		 * @return int
		 */
		public function getCount(): int;
		
		/**
		 * Adds an entity to the collection.
		 * @param T $entity The entity to add
		 * @return void
		 */
		public function add(object $entity): void;
		
		/**
		 * Removes an entity from the collection.
		 * @param T $entity The entity to remove
		 * @return bool True if the entity was removed, false if it was not found
		 */
		public function remove(object $entity): bool;
		
		/**
		 * Returns all elements in the collection as an array.
		 * @return array<T>
		 */
		public function toArray(): array;
		
		/**
		 * Returns the element at the specified offset, or null if the offset does not exist.
		 * @param mixed $offset The offset to retrieve
		 * @return T|null
		 */
		public function offsetGet(mixed $offset): mixed;
		
		/**
		 * Sets an element at the specified offset.
		 * @param mixed $offset The offset to assign the value to, or null to append
		 * @param T $value The entity to store
		 * @return void
		 */
		public function offsetSet(mixed $offset, mixed $value): void;
		
		/**
		 * Returns the element at the current iterator position, or null if the position is invalid.
		 * @return T|null
		 */
		public function current(): mixed;
	}