<?php
	
	namespace Quellabs\ObjectQuel\Collections;
	
	/**
	 * @template T
	 * @extends \ArrayAccess<string|int, T>
	 * @extends \Iterator<string|int, T>
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
		 * Returns true if the collection contains the specified value, false if not.
		 * @param T $value The value to search for
		 * @return bool
		 */
		public function contains(mixed $value): bool;
		
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
		 * Adds a value to the collection.
		 * @param T $value The value to add
		 * @return void
		 */
		public function add(mixed $value): void;
		
		/**
		 * Removes a value from the collection.
		 * @param T $value The value to remove
		 * @return bool True if the value was removed, false if it was not found
		 */
		public function remove(mixed $value): bool;
		
		/**
		 * Returns all elements in the collection as an array.
		 * @return array<T>
		 */
		public function toArray(): array;
		
		/**
		 * Returns the element at the specified offset, or null if the offset does not exist.
		 * @param string|int $offset The offset to retrieve
		 * @return T|null
		 */
		public function offsetGet(mixed $offset): mixed;
		
		/**
		 * Sets an element at the specified offset.
		 * @param string|int|null $offset The offset to assign the value to, or null to append
		 * @param T $value The value to store
		 * @return void
		 */
		public function offsetSet(mixed $offset, mixed $value): void;
		
		/**
		 * Returns the element at the current iterator position, or null if the position is invalid.
		 * @return T|null
		 */
		public function current(): mixed;
	}