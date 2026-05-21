<?php
	
	namespace Quellabs\ObjectQuel\Collections;
	
	/**
	 * A generic collection class
	 * @template T
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
		 * @var list<string|int>|null
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
		 * Parsed sort rules derived from $sortOrder.
		 * Each entry is ['property' => string, 'direction' => int] where direction is 1 (ASC) or -1 (DESC).
		 * @var list<array{property: string, direction: int}>
		 */
		protected array $sortRules = [];
		
		/**
		 * Cache of ReflectionClass instances keyed by class name, to avoid repeated instantiation during sorting.
		 * @var array<class-string, \ReflectionClass<object>>
		 */
		protected array $reflectionCache = [];
		
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
			$this->sortRules = $this->parseSortOrder($sortOrder);
			$this->position = null;
			$this->isDirty = false;
		}
		
		/**
		 * Always returns true, as a non-lazy collection is initialized by definition.
		 * @return bool
		 */
		public function isInitialized(): bool {
			return true;
		}
		
		/**
		 * Parses a sort order string into a structured list of rules.
		 * Parsing happens once (at construction or when sort order changes) rather than
		 * on every comparison during usort.
		 * @param string $sortOrder Comma-separated list of "property [ASC|DESC]" tokens
		 * @return list<array{property: string, direction: int}>
		 */
		protected function parseSortOrder(string $sortOrder): array {
			// Do not re-sort if no sort order provided
			if ($sortOrder === '') {
				return [];
			}
			
			// Perform sorting
			$rules = [];
			
			foreach (explode(',', $sortOrder) as $token) {
				// Split on whitespace and discard empty parts, so "name    DESC" works correctly
				$parts = array_values(array_filter(array_map('trim', explode(' ', trim($token)))));
				
				if (empty($parts) || $parts[0] === '') {
					continue;
				}
				
				$rules[] = [
					'property'  => $parts[0],
					'direction' => isset($parts[1]) && strtolower($parts[1]) === 'desc' ? -1 : 1,
				];
			}
			
			return $rules;
		}
		
		/**
		 * Sort callback based on the pre-parsed sortRules.
		 * @param mixed $a The first element to compare
		 * @param mixed $b The second element to compare
		 * @return int Negative, zero, or positive as required by usort
		 * @throws \RuntimeException When property access via reflection fails
		 */
		protected function sortCallback(mixed $a, mixed $b): int {
			foreach ($this->sortRules as $rule) {
				$property = $rule['property'];
				$direction = $rule['direction'];
				
				$valueA = $this->extractValue($a, $property);
				$valueB = $this->extractValue($b, $property);
				
				// If both values are null, continue to the next field
				if ($valueA === null && $valueB === null) {
					continue;
				}
				
				// Null values are considered larger
				if ($valueA === null) {
					return $direction;
				}
				
				if ($valueB === null) {
					return -$direction;
				}
				
				// If both values are strings, use case-insensitive comparison
				if (is_string($valueA) && is_string($valueB)) {
					$result = strcasecmp($valueA, $valueB);
					
					if ($result !== 0) {
						return $result > 0 ? $direction : -$direction;
					}
				} elseif (is_scalar($valueA) && is_scalar($valueB)) {
					if ($valueA > $valueB) {
						return $direction;
					}
					
					if ($valueA < $valueB) {
						return -$direction;
					}
				}
				
				// Values are equal on this field; continue to the next
			}
			
			// All fields equal; maintain original order
			return 0;
		}
		
		/**
		 * Extract a value from a variable based on the given property.
		 * ReflectionClass instances are cached by class name to avoid repeated instantiation
		 * across the O(n log n) comparisons that usort performs.
		 * @param mixed $var The variable to extract the value from
		 * @param string $property The name of the property to extract
		 * @return mixed The extracted value, or null if not found
		 * @throws \RuntimeException When reflection fails unexpectedly
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
				
				// Use reflection to access private/protected properties.
				// ReflectionClass instances are cached per class name.
				$className = get_class($var);
				
				try {
					if (!isset($this->reflectionCache[$className])) {
						$this->reflectionCache[$className] = new \ReflectionClass($var);
					}
					
					$reflection = $this->reflectionCache[$className];
					
					if ($reflection->hasProperty($property)) {
						$prop = $reflection->getProperty($property);
						$prop->setAccessible(true);
						return $prop->getValue($var);
					}
				} catch (\ReflectionException $e) {
					throw new \RuntimeException(
						"Failed to read property '{$property}' on {$className} during collection sort: " . $e->getMessage(),
						0,
						$e
					);
				}
				
				return null;
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
		 * @throws \RuntimeException
		 */
		protected function calculateSortedKeys(): void {
			// Check if the data hasn't changed and the keys are already calculated
			if (!$this->isDirty && $this->sortedKeys !== null) {
				return; // Nothing to do, early return
			}
			
			// Get the keys
			$this->sortedKeys = array_values($this->getKeys());
			
			// Sort the keys if sort rules are present
			if (!empty($this->sortRules)) {
				usort($this->sortedKeys, function ($keyA, $keyB) {
					return $this->sortCallback($this->collection[$keyA], $this->collection[$keyB]);
				});
			}
			
			// Mark the keys as up-to-date
			$this->isDirty = false;
		}
		
		/**
		 * Get the sorted keys of the collection
		 * @return list<string|int>
		 */
		protected function getSortedKeys(): array {
			$this->calculateSortedKeys();
			return $this->sortedKeys ?? [];
		}
		
		/**
		 * Removes all entries from the collection
		 * @return void
		 */
		public function clear(): void {
			$this->collection = [];
			$this->sortedKeys = null;
			$this->position = null;
			$this->isDirty = false;
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
		 * @param T $value
		 * @return bool
		 */
		public function contains(mixed $value): bool {
			return in_array($value, $this->collection, true);
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
		public function current(): mixed {
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
		public function first(): mixed {
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
			return (is_int($offset) || is_string($offset)) && array_key_exists($offset, $this->collection);
		}
		
		/**
		 * Retrieves an element from the collection based on the given key.
		 * @param mixed $offset The key that identifies the element in the collection.
		 * @return T|null The element that corresponds to the given key, or null if the key doesn't exist.
		 */
		public function offsetGet(mixed $offset): mixed {
			if (!is_int($offset) && !is_string($offset)) {
				return null;
			}
			
			return $this->collection[$offset] ?? null;
		}
		
		/**
		 * Sets an element in the collection at a specific key.
		 * @param string|int|null $offset
		 * @param T $value
		 */
		public function offsetSet(mixed $offset, mixed $value): void {
			// No offset
			if ($offset === null) {
				$this->collection[] = $value;
				$this->isDirty = true;
				return;
			}
			
			// Scalar offset
			if (is_int($offset) || is_string($offset)) {
				$this->collection[$offset] = $value;
				$this->isDirty = true;
			}
		}
		
		/**
		 * Removes an element from the collection based on the specified key.
		 * @param mixed $offset The key of the element to be removed.
		 */
		public function offsetUnset(mixed $offset): void {
			if (is_int($offset) || is_string($offset)) {
				unset($this->collection[$offset]);
			}
			
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
		 * @param T $value
		 * @return void
		 */
		public function add(mixed $value): void {
			$this->collection[] = $value;
			$this->isDirty = true;
		}
		
		/**
		 * Removes a value from the collection
		 * @param T $value
		 * @return bool
		 */
		public function remove(mixed $value): bool {
			$key = array_search($value, $this->collection, true);
			
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
			
			// Parse into structured rules
			$this->sortRules = $this->parseSortOrder($sortOrder);
			
			// Reset sorted keys
			$this->sortedKeys = null;
			
			// Set dirty flag
			$this->isDirty = true;
		}
	}