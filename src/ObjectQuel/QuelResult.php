<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\EntityHydrator;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\RelationshipLoader;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\ResultTransformer;
	
	/**
	 * Represents a Quel result.
	 * This class handles the hydration, relationship loading, and transformation of database query results.
	 * It implements ArrayAccess and IteratorAggregate to allow array-like access and iteration over the result set.
	 */
	class QuelResult implements \ArrayAccess, \IteratorAggregate {
		
		/**
		 * Responsible for converting raw data into entity objects
		 */
		private EntityHydrator $entityHydrator;
		
		/**
		 * Handles loading relationships between entities
		 */
		private RelationshipLoader $relationShipLoader;
		
		/**
		 * Performs transformations on the result set (like sorting)
		 */
		private ResultTransformer $resultTransformer;
		
		/**
		 * The actual result set containing hydrated entities and data
		 */
		private array $result;
		
		/**
		 * Current position in the result set for iteration
		 */
		private int $index;
		
		/**
		 * Flag indicating if sorting should be handled in application logic rather than database
		 */
		private bool $sortInApplicationLogic;
		
		/**
		 * Pagination window value (for pagination functionality)
		 */
		private ?int $window;
		
		/**
		 * Number of items per window (for pagination functionality)
		 */
		private ?int $windowSize;
		
		/**
		 * Constructor initializes helpers and processes the raw data into structured results
		 * @param entityManager $entityManager Entity manager for data handling
		 * @param AstRetrieve $retrieve AST object containing query information
		 * @param array $data Raw data from the database query
		 */
		public function __construct(EntityManager $entityManager, AstRetrieve $retrieve, array $data) {
			// Initialize helper objects
			$this->entityHydrator = new EntityHydrator($entityManager);
			$this->relationShipLoader = new RelationshipLoader($entityManager, $retrieve);
			$this->resultTransformer = new ResultTransformer();
			
			// Determine if sorting should be done in application logic
			// This happens when sort contains method calls and InValuesAreFinal directive is not set
			$this->sortInApplicationLogic =
				$retrieve->sortContainsJsonIdentifier() || (
					$retrieve->getSortInApplicationLogic() &&
					empty($retrieve->getDirective('InValuesAreFinal'))
				);
			
			// Initialize iterator position
			$this->index = 0;
			
			// Set pagination parameters
			$this->window = $retrieve->getWindow();
			$this->windowSize = $retrieve->getWindowSize() ?? $entityManager->getDefaultWindowSize();
			
			// Get values from the AST (Abstract Syntax Tree)
			$ast = $retrieve->getValues();
			
			// Process raw data into entity objects
			$result = $this->entityHydrator->hydrateEntities($ast, $data);
			
			// Store the processed result
			$this->result = $result['result'];
			
			// Load relationships between entities
			$this->relationShipLoader->loadRelationships($result['entities']);
			
			// Sort the results if needed:
			// 1) A method is called in SORT BY clause
			// 2) InValuesAreFinal is not set (with InValuesAreFinal, sorting is based on the IN() list)
			if ($this->sortInApplicationLogic) {
				$this->resultTransformer->sortResults($this->result, $retrieve->getSort());
			}
		}
		
		/**
		 * Returns the number of rows inside this recordset
		 * @return int Total count of records in the result set
		 */
		public function recordCount(): int {
			return count($this->result);
		}
		
		/**
		 * Reads a row of a result set and advances the recordset pointer
		 * Similar to PDO's fetch() method
		 * @return mixed The current row (entity or array) or false if no more rows
		 */
		public function fetchRow(): mixed {
			if ($this->index >= $this->recordCount()) {
				return false;
			}
			
			$result = $this->result[$this->index];
			++$this->index;
			return $result;
		}
		
		/**
		 * Returns the value of $columnName for all rows at once
		 * Similar to PDO's fetchColumn() but returns all matching values
		 * @param string|int $columnName Column name or index to fetch
		 * @return array Array of values from the specified column
		 */
		public function fetchCol(string|int $columnName=0): array {
			// If index specifies column, convert to column name
			if (is_int($columnName)) {
				$keys = array_keys($this->result);
				$columnName = $keys[$columnName];
			}
			
			return array_column($this->result, $columnName);
		}
		
		/**
		 * Moves the result index to the given position
		 * Similar to PDOStatement::seek()
		 * @param int $pos Position to move to in the result set
		 * @return void
		 */
		public function seek(int $pos): void {
			$this->index = $pos;
		}
		
		/**
		 * Resets the pointer and returns all rows as an array
		 * Useful for getting the full result set at once
		 * @return array All rows in the result set
		 */
		public function fetchAll(): array {
			$this->index = 0; // Reset the pointer
			return $this->result;
		}
		
		/**
		 * Returns true if sort and pagination are done in application logic, false if using mysql
		 * Useful for determining how results are being processed
		 * @return bool True if sorting in application, false if in database
		 */
		public function getSortInApplicationLogic(): bool {
			return $this->sortInApplicationLogic;
		}
		
		/**
		 * Returns the pagination window, or null if there is none
		 * Part of the pagination mechanism
		 * @return int|null Current window value or null if pagination is not active
		 */
		public function getWindow(): ?int {
			return $this->window;
		}
		
		/**
		 * Returns the pagination page_size, or null if there is none
		 * Part of the pagination mechanism
		 * @return int|null Current page size or null if pagination is not active
		 */
		public function getWindowSize(): ?int {
			return $this->windowSize;
		}
		
		/**
		 * Returns the raw data in this recordset
		 * Provides direct access to the underlying result array
		 * @return array The complete result set
		 */
		public function getResults(): array {
			return $this->result;
		}
		
		/**
		 * Apply a custom transformation function to the entire result set
		 * Allows for flexible post-processing of results
		 * @param callable $transformer Function that transforms the entire result array
		 * @return $this Returns $this for method chaining
		 */
		public function transform(callable $transformer): self {
			$this->result = $transformer($this->result);
			return $this;
		}
		
		/**
		 * Filters the entire result set
		 * Allows for custom filtering logic to be applied
		 * @param callable $condition Function that takes the result array and returns filtered array
		 * @return $this For method chaining
		 */
		public function filter(callable $condition): self {
			$this->result = $condition($this->result);
			return $this;
		}
		
		/**
		 * Merge another QuelResult or array of rows into this one
		 * Useful for combining multiple result sets
		 * @param array|QuelResult $otherResult The result to merge
		 * @return $this Returns $this for method chaining
		 */
		public function merge(array|QuelResult $otherResult): self {
			if ($otherResult instanceof QuelResult) {
				$this->result = array_merge($this->result, $otherResult->getResults());
			} else {
				$this->result = array_merge($this->result, $otherResult);
			}
			
			return $this;
		}
		
		/**
		 * Extract values for a specific field from the result set
		 * Handles both object entities and array results
		 * @param string $field The field to extract
		 * @return array Array of extracted values, deduplicated and with nulls removed
		 */
		public function extractFieldValues(string $field): array {
			$values = [];
			
			// Implementation depends on how your QuelResult stores data
			// This is a generic example:
			foreach ($this->getResults() as $result) {
				if (is_object($result)) {
					// Handle entity objects using getter methods
					$getter = 'get' . ucfirst($field);
					
					if (method_exists($result, $getter)) {
						$values[] = $result->$getter();
					} elseif (property_exists($result, $field)) {
						// Try property access if getter doesn't exist
						$values[] = $result->$field;
					}
				} elseif (is_array($result) && isset($result[$field])) {
					// Handle array results
					$values[] = $result[$field];
				}
			}
			
			// Remove duplicates and null values
			return array_values(array_filter(array_unique($values)));
		}
		
		/**
		 * IteratorAggregate implementation: Gets an iterator for this object
		 * This allows foreach iteration over the result set
		 * @return \ArrayIterator An iterator for the result set
		 */
		public function getIterator(): \Traversable {
			return new \ArrayIterator($this->result);
		}
		
		/**
		 * ArrayAccess implementation: Checks if offset exists
		 * @param mixed $offset The offset to check
		 * @return bool True if offset exists, false otherwise
		 */
		public function offsetExists(mixed $offset): bool {
			return isset($this->result[$offset]);
		}
		
		/**
		 * ArrayAccess implementation: Gets value at offset
		 * @param mixed $offset The offset to retrieve
		 * @return mixed The value at the specified offset or null if not found
		 */
		public function offsetGet(mixed $offset): mixed {
			return $this->result[$offset] ?? null;
		}
		
		/**
		 * ArrayAccess implementation: Sets value at offset
		 * @param mixed $offset The offset to set
		 * @param mixed $value The value to set
		 * @return void
		 */
		public function offsetSet(mixed $offset, mixed $value): void {
			if (is_null($offset)) {
				$this->result[] = $value;
			} else {
				$this->result[$offset] = $value;
			}
		}
		
		/**
		 * ArrayAccess implementation: Unsets value at offset
		 * @param mixed $offset The offset to unset
		 * @return void
		 */
		public function offsetUnset(mixed $offset): void {
			unset($this->result[$offset]);
		}
	}