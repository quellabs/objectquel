<?php
	
	namespace Quellabs\ObjectQuel\Collections;
	
	/**
	 * A generic collection class with improved type safety and performance
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
		 * An array of sorted keys, cached for performance.
		 * @var array<string|int>|null
		 */
		protected ?array $sortedKeys = null;
		
		/**
		 * Current position in the iteration of the collection.
		 * @var int
		 */
		protected int $position = 0;
		
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
			$this->collection = [];
			$this->sortOrder = $sortOrder;
			$this->position = 0; // Initialize to 0 instead of null
			$this->isDirty = false;
		}
		
		/**
		 * Removes all entries from the collection
		 * @return void
		 */
		public function clear(): void {
			$this->collection = [];
			$this->position = 0; // Reset to 0 instead of null
			$this->markDirty();
		}
		
		/**
		 * Returns true if the given key exists in the collection, false if not
		 * @param string|int $key
		 * @return bool
		 */
		public function containsKey(string|int $key): bool {
			return array_key_exists($key, $this->collection);
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
			$this->position++;
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
			
			$this->markDirty();
		}
		
		/**
		 * Removes an element from the collection based on the specified key.
		 * @param mixed $offset The key of the element to be removed.
		 */
		public function offsetUnset(mixed $offset): void {
			if (array_key_exists($offset, $this->collection)) {
				unset($this->collection[$offset]);
				$this->markDirty();
			}
		}
		
		/**
		 * Returns the current key of the element in the collection.
		 * @return mixed The key of the current element, or null if the position is not valid.
		 */
		public function key(): mixed {
			$keys = $this->getSortedKeys();
			return $keys[$this->position] ?? null;
		}
		
		/**
		 * Checks if the current position is valid in the collection.
		 * @return bool True if the current position is valid, otherwise false.
		 */
		public function valid(): bool {
			$keys = $this->getSortedKeys();
			return isset($keys[$this->position]);
		}
		
		/**
		 * Make sure we are sorted before we start iterating
		 * @return void
		 */
		public function rewind(): void {
			$this->calculateSortedKeys();
			$this->position = 0; // Always start at 0
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
			$this->markDirty();
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
				$this->markDirty();
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
		 * Filters the collection based on a callback function
		 * @param callable $callback A callback function that takes an element and returns bool
		 * @return array<T> An array containing only elements that pass the filter
		 */
		public function filter(callable $callback): array {
			$filtered = [];
			
			foreach ($this->collection as $key => $item) {
				if ($callback($item, $key)) {
					$filtered[] = $item;
				}
			}
			
			return $filtered;
		}
		
		/**
		 * Maps each element of the collection through a callback function
		 * @param callable $callback A callback function that takes an element and returns a transformed value
		 * @return array An array containing the transformed elements
		 */
		public function map(callable $callback): array {
			$mapped = [];
			
			foreach ($this->collection as $key => $item) {
				$mapped[] = $callback($item, $key);
			}
			
			return $mapped;
		}
		
		/**
		 * Reduces the collection to a single value using a callback function
		 * @param callable $callback A callback function that takes accumulator, current item, and key
		 * @param mixed $initial The initial value for the accumulator
		 * @return mixed The final accumulated value
		 */
		public function reduce(callable $callback, mixed $initial = null): mixed {
			$accumulator = $initial;
			
			foreach ($this->collection as $key => $item) {
				$accumulator = $callback($accumulator, $item, $key);
			}
			
			return $accumulator;
		}
		
		/**
		 * Checks if any element in the collection matches the given callback
		 * @param callable $callback A callback function that takes an element and returns bool
		 * @return bool True if any element matches, false otherwise
		 */
		public function any(callable $callback): bool {
			foreach ($this->collection as $key => $item) {
				if ($callback($item, $key)) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Marks the collection as modified and clears cache
		 */
		protected function markDirty(): void {
			$this->isDirty = true;
			$this->sortedKeys = null; // Clear cache immediately for better performance
		}
		
		/**
		 * Sort callback based on the sortOrder string
		 * This function is used to compare two elements of the collection
		 * @param T $a The first element to compare
		 * @param T $b The second element to compare
		 * @return int An integer indicating whether $a is less than, equal to, or greater than $b
		 */
		protected function sortCallback(mixed $a, mixed $b): int {
			if (empty($this->sortOrder)) {
				return 0; // Early return if no sort order
			}
			
			try {
				$fields = array_map('trim', explode(',', $this->sortOrder));
				
				foreach ($fields as $field) {
					if (empty($field)) continue; // Skip empty fields
					
					// Split each field into property and direction
					// For example, "name ASC" becomes ["name", "ASC"]
					$parts = array_map('trim', explode(' ', $field, 2)); // Limit to 2 parts
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
					
					// Handle null values consistently - null comes last in ASC order
					if ($valueA === null) {
						return $direction;
					}
					
					if ($valueB === null) {
						return -$direction;
					}
					
					// Compare values with proper type handling
					$result = $this->compareValues($valueA, $valueB);
					if ($result !== 0) {
						return $result * $direction;
					}
					
					// If the values are equal, continue to the next field
				}
			} catch (\Exception $e) {
				// Log any errors with more context
				error_log("Collection sort error: " . $e->getMessage());
			}
			
			// If all fields are equal, maintain the original order
			return 0;
		}
		
		/**
		 * Compare two values with proper type handling
		 * @param mixed $a
		 * @param mixed $b
		 * @return int
		 */
		protected function compareValues(mixed $a, mixed $b): int {
			// If both values are strings, use case-insensitive comparison
			if (is_string($a) && is_string($b)) {
				return strcasecmp($a, $b);
			}
			
			// If both are numeric, compare numerically to avoid string comparison issues
			if (is_numeric($a) && is_numeric($b)) {
				return $a <=> $b;
			}
			
			// If both are the same type, use spaceship operator
			if (gettype($a) === gettype($b)) {
				return $a <=> $b;
			}
			
			// Fallback to string comparison for mixed types
			return strcmp((string)$a, (string)$b);
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
			
			// For scalar values (int, float, string, bool), if
			// the property is 'value', return the value itself.
			if ($property === 'value' && is_scalar($var)) {
				return $var;
			}
			
			// If $var is an object, try to get the value in different ways
			if (is_object($var)) {
				// Check for a getter method (e.g. getName() for property 'name') - more efficient than reflection
				$getter = 'get' . ucfirst($property);
				
				if (method_exists($var, $getter)) {
					try {
						return $var->$getter();
					} catch (\Exception $e) {
						error_log("Getter method error for property '{$property}': " . $e->getMessage());
						// Continue to try other methods
					}
				}
				
				// Use reflection to access private/protected properties as last resort
				try {
					$reflection = new \ReflectionClass($var);
					
					if ($reflection->hasProperty($property)) {
						// Fetch the property
						$prop = $reflection->getProperty($property);
						
						// Mark the property to be accessible if it's private/protected
						if (!$prop->isPublic()) {
							$prop->setAccessible(true);
						}
						
						// Check if property is initialized before getting value
						if (!$prop->isInitialized($var)) {
							// Return type-compatible default value for uninitialized typed properties
							$type = $prop->getType();
							
							if ($type instanceof \ReflectionNamedType && !$type->allowsNull()) {
								return match ($type->getName()) {
									'string' => '',
									'int' => 0,
									'float' => 0.0,
									'bool' => false,
									'array' => [],
									default => null
								};
							}
							
							return null;
						}
						
						// Return the value
						return $prop->getValue($var);
					}
				} catch (\ReflectionException $e) {
					// Log the error with more context
					error_log("Reflection error for property '{$property}': " . $e->getMessage());
				}
			}
			
			// If none of the above methods work, return null
			return null;
		}
		
		/**
		 * Calculate and sort the keys if needed with lazy evaluation.
		 * @return void
		 */
		protected function calculateSortedKeys(): void {
			// Check if the data hasn't changed and the keys are already calculated
			if (!$this->isDirty && $this->sortedKeys !== null) {
				return; // Nothing to do, early return
			}
			
			// Get the keys
			$this->sortedKeys = $this->getKeys();
			
			// Sort the keys if a sort order is set and we have items
			if (!empty($this->sortOrder) && !empty($this->sortedKeys)) {
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
			return $this->sortedKeys ?? [];
		}
	}