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
	class QuelResult implements \ArrayAccess, \IteratorAggregate, \JsonSerializable, \Countable {
		
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
		 * Returns the raw data in this recordset
		 * Provides direct access to the underlying result array
		 * @return array The complete result set
		 */
		public function getResults(): array {
			return $this->result;
		}
		
		/**
		 * Merge another QuelResult or array of rows into this one
		 * Useful for combining multiple result sets
		 * @param array|QuelResult $otherResult The result to merge
		 * @return $this Returns a new QuelResult with merged data
		 */
		public function merge(array|QuelResult $otherResult): self {
			$cloned = clone $this;
			$cloned->index = 0;
			
			if ($otherResult instanceof QuelResult) {
				$cloned->result = array_merge($cloned->result, $otherResult->getResults());
			} else {
				$cloned->result = array_merge($cloned->result, $otherResult);
			}
			
			return $cloned;
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
		
		/**
		 * JsonSerializable implementation: Returns data which can be serialized by json_encode()
		 * @return array The result set that can be JSON serialized
		 */
		public function jsonSerialize(): array {
			return $this->result;
		}
		
		/**
		 * Countable implementation: Returns the number of elements in the result set
		 * @return int Number of elements in the result set
		 */
		public function count(): int {
			return count($this->result);
		}
	}