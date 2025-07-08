<?php
	
	namespace Quellabs\ObjectQuel\Collections;
	
	/**
	 * @template T of object
	 */
	interface CollectionInterface extends \ArrayAccess, \Iterator, \Countable {

		/**
		 * Removes all elements from the collection.
		 */
		public function clear(): void;
		
		/**
		 * Checks if the collection contains the specified entity.
		 * @param T $entity
		 * @return bool
		 */
		public function contains($entity): bool;
		
		/**
		 * Checks if the collection is empty.
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
		 * @param T $entity
		 * @return void
		 */
		public function add($entity): void;
		
		/**
		 * Removes an entity from the collection.
		 * @param T $entity
		 * @return bool True if the entity was removed, false if it was not found
		 */
		public function remove($entity): bool;
		
		/**
		 * Returns all entities in the collection.
		 * @return array<T>
		 */
		public function toArray(): array;
		
		/**
		 * @param int $offset
		 * @return T|null
		 */
		#[\ReturnTypeWillChange]
		public function offsetGet($offset);
		
		/**
		 * @param int|null $offset
		 * @param T $value
		 * @return void
		 */
		#[\ReturnTypeWillChange]
		public function offsetSet($offset, $value): void;
		
		/**
		 * @return T|null
		 */
		#[\ReturnTypeWillChange]
		public function current();
	}