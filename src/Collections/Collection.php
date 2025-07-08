<?php
	
	namespace Quellabs\ObjectQuel\Collections;
	
	/**
	 * A generic collection class
	 * @template T of object
	 * @implements CollectionInterface<T>
	 */
	class Collection implements CollectionInterface {
		
		/**
		 * The collection of objects, where the key can be a string or integer.
		 * @var array<string|int, T>
		 */
		protected array $collection;
		
		/**
		 * An array of sorted keys, if present.
		 * @var array<string|int>|null
		 */
		protected ?array $sortedKeys = null;
		
		/**
		 * Current position in the iteration of the collection.
		 * @var int|null
		 */
		protected ?int $position;
		
		/**
		 * Indicates the sort order as a string.
		 * @var string
		 */
		protected string $sortOrder;
		
		/**
		 * Flag indicating whether the collection has been modified and needs to be resorted.
		 * @var bool
		 */
		protected bool $isDirty = false;
		
		/**
		 * Collection constructor
		 * @param string $sortOrder The sort order for the collection, default is an empty string.
		 */
		public function __construct(string $sortOrder = '') {
			$this->collection = []; // Initialization of the collection array
			$this->sortOrder = $sortOrder; // Initialization of the sort order
			$this->position = null; // Initialization of the position
			$this->isDirty = false; // The collection is not yet marked as modified
		}
		
		/**
		 * Sort callback based on the sortOrder string
		 * This function is used to compare two elements of the collection
		 * @param mixed $a The first element to compare
		 * @param mixed $b The second element to compare
		 * @return int An integer indicating whether $a is less than, equal to, or greater than $b
		 */
		protected function sortCallback(mixed $a, mixed $b): int {
			try {
				$fields = array_map('trim', explode(',', $this->sortOrder));
				
				foreach ($fields as $field) {
					// Split each field into property and direction
					// For example, "name ASC" becomes ["name", "ASC"]
					$parts = array_map('trim', explode(' ', $field));
					$property = $parts[0];
					
					// Determine the sort direction: -1 for DESC, 1 for ASC (default)
					$direction = isset($parts[1]) && strtolower($parts[1]) === 'desc' ? -1 : 1;
					
					// Get the values for comparison
					$valueA = $this->extractValue($a, $property);
					$valueB = $this->extractValue($b, $property);
					
					// If both values are null, continue to the next field
					if ($valueA === null && $valueB === null) {
						continue;
					}
					
					// Null values are considered larger in PHP
					if ($valueA === null) {
						return $direction;
					}
					
					if ($valueB === null) {
						return -$direction;
					}
					
					// If both values are strings, use case-insensitive comparison
					if (is_string($valueA) && is_string($valueB)) {
						$result = strcasecmp($valueA, $valueB);
						
						if ($result > 0) {
							return $direction;
						}
						
						if ($result < 0) {
							return -$direction;
						}
					} elseif ($valueA > $valueB) {
						return $direction;
					} elseif ($valueA < $valueB) {
						return -$direction;
					}
					
					// If the values are equal, continue to the next field
				}
			} catch (\ReflectionException $e) {
				// Log any reflection errors
				error_log("Reflection error in collection sort");
			}
			
			// If all fields are equal, maintain the original order
			return 0;
		}
		
		/**
		 * Extract a value from a variable based on the given property
		 * @param mixed $var The variable to extract the value from
		 * @param string $property The name of the property to extract
		 * @return mixed The extracted value, or null if not found
		 */
		protected function extractValue(mixed $var, string $property): mixed {
			// If $var is an array, try to get the value with the property as key
			if (is_array($var)) {
				return $var[$property] ?? null;
			}
			
			// If $var is an object, try to get the value in different ways
			if (is_object($var)) {
				// Check for a getter method (e.g. getName() for property 'name')
				if (method_exists($var, 'get' . ucfirst($property))) {
					return $var->{'get' . ucfirst($property)}();
				}
				
				// Use reflection to access private/protected properties
				try {
					$reflection = new \ReflectionClass($var);
					
					if ($reflection->hasProperty($property)) {
						$prop = $reflection->getProperty($property);
						$prop->setAccessible(true);
						return $prop->getValue($var);
					}
				} catch (\ReflectionException $e) {
					// Log the error if reflection fails
					error_log("Reflection error in collection sort: " . $e->getMessage());
				}
			}
			
			// For scalar values (int, float, string, bool), if
			// the property is 'value', return the value itself.
			if ($property === 'value' && is_scalar($var)) {
				return $var;
			}
			
			// If none of the above methods work, return null
			return null;
		}
		
		/**
		 * Calculate and sort the keys if needed.
		 * @return void
		 */
		protected function calculateSortedKeys(): void {
			// Check if the data hasn't changed and the keys are already calculated
			if (!$this->isDirty && $this->sortedKeys !== null) {
				return; // Nothing to do, early return
			}
			
			// Get the keys
			$this->sortedKeys = $this->getKeys();
			
			// Sort the keys if a sort order is set
			if (!empty($this->sortOrder)) {
				usort($this->sortedKeys, function($keyA, $keyB) {
					return $this->sortCallback($this->collection[$keyA], $this->collection[$keyB]);
				});
			}
			
			// Mark the keys as up-to-date
			$this->isDirty = false;
		}
		
		/**
		 * Get the sorted keys of the collection
		 * @return array<string|int>
		 */
		protected function getSortedKeys(): array {
			$this->calculateSortedKeys();
			return $this->sortedKeys;
		}
		
		/**
		 * Removes all entries from the collection
		 * @return void
		 */
		public function clear(): void {
			$this->collection = [];
			$this->position = null;
		}
		
		/**
		 * Returns true if the given key exists in the collection, false if not
		 * @param string $key
		 * @return bool
		 */
		public function containsKey(string $key): bool {
			return isset($this->collection[$key]);
		}
		
		/**
		 * Returns true if the given value exists in the collection, false if not
		 * @param T $entity
		 * @return bool
		 */
		public function contains(mixed $entity): bool {
			return in_array($entity, $this->collection, true);
		}
		
		/**
		 * Returns true if the collection is empty, false if populated
		 * @return bool
		 */
		public function isEmpty(): bool {
			return empty($this->collection);
		}
		
		/**
		 * Returns the number of items in the collection
		 * @return int
		 */
		public function getCount(): int {
			return count($this->collection);
		}
		
		/**
		 * Returns the current element in the collection based on the current position.
		 * @return T|null
		 */
		public function current() {
			if ($this->position === null) {
				return null;
			}
			
			$keys = $this->getSortedKeys();
			
			if (!isset($keys[$this->position])) {
				return null;
			}
			
			return $this->collection[$keys[$this->position]];
		}
		
		/**
		 * Returns the first element in the collection.
		 * @return T|null The first element in the collection, or null if the collection is empty.
		 */
		public function first() {
			$keys = $this->getSortedKeys();
			
			if (!empty($keys)) {
				return $this->collection[$keys[0]];
			}
			
			return null;
		}
		
		/**
		 * Moves the internal pointer to the next element in the collection and returns this element.
		 * @return void
		 */
		public function next(): void {
			if ($this->position !== null) {
				$this->position++;
			}
		}
		
		/**
		 * Checks if a certain key exists in the collection.
		 * @param mixed $offset
		 * @return bool
		 */
		public function offsetExists(mixed $offset): bool {
			return array_key_exists($offset, $this->collection);
		}
		
		/**
		 * Retrieves an element from the collection based on the given key.
		 * @param string|int $offset The key that identifies the element in the collection.
		 * @return T|null The element that corresponds to the given key, or null if the key doesn't exist.
		 */
		public function offsetGet($offset) {
			return $this->collection[$offset] ?? null;
		}
		
		/**
		 * Sets an element in the collection at a specific key.
		 * @param mixed $offset
		 * @param T $value
		 */
		public function offsetSet(mixed $offset, mixed $value): void {
			if (is_null($offset)) {
				$this->collection[] = $value;
			} else {
				$this->collection[$offset] = $value;
			}
			
			$this->isDirty = true;
		}
		
		/**
		 * Removes an element from the collection based on the specified key.
		 * @param mixed $offset The key of the element to be removed.
		 */
		public function offsetUnset(mixed $offset): void {
			unset($this->collection[$offset]);
			$this->isDirty = true;
		}
		
		/**
		 * Returns the current key of the element in the collection.
		 * @return mixed The key of the current element, or null if the position is not valid.
		 */
		public function key(): mixed {
			if ($this->position === null) {
				return null;
			}
			
			$keys = $this->getSortedKeys();
			return $keys[$this->position] ?? null;
		}
		
		/**
		 * Checks if the current position is valid in the collection.
		 * @return bool True if the current position is valid, otherwise false.
		 */
		public function valid(): bool {
			if ($this->position === null) {
				return false;
			}
			
			$keys = $this->getSortedKeys();
			return isset($keys[$this->position]);
		}
		
		/**
		 * Make sure we are sorted before we start iterating
		 * @return void
		 */
		public function rewind(): void {
			$this->calculateSortedKeys();
			$this->position = empty($this->sortedKeys) ? null : 0;
		}
		
		/**
		 * Returns the number of items in the collection
		 * @return int
		 */
		public function count(): int {
			return $this->getCount();
		}
		
		/**
		 * Returns the collection's keys as an array
		 * @return array<string|int>
		 */
		public function getKeys(): array {
			return array_keys($this->collection);
		}
		
		/**
		 * Adds a new value to the collection
		 * @param T $entity
         * @return void
		 */
		public function add($entity): void {
			$this->collection[] = $entity;
			$this->isDirty = true;
		}
		
		/**
		 * Removes a value from the collection
		 * @param T $entity
         * @return bool
         */
		public function remove($entity): bool {
			$key = array_search($entity, $this->collection, true);
			
			if ($key !== false) {
				unset($this->collection[$key]);
				$this->isDirty = true;
				return true;
			}
			
			return false;
		}
		
		/**
		 * Transforms the collection to a sorted array
		 * @return array<T>
		 */
		public function toArray(): array {
			$result = [];

			foreach ($this->getSortedKeys() as $key) {
				$result[] = $this->collection[$key];
			}

			return $result;
		}
		
		/**
		 * Update the sort order
		 * @param string $sortOrder New sort order
		 */
		public function updateSortOrder(string $sortOrder): void {
			// Save the new sort order
			$this->sortOrder = $sortOrder;
			
			// Reset sorted keys
			$this->sortedKeys = null;
			
			// Set dirty flag
			$this->isDirty = true;
		}
	}