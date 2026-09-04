<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\HydrationException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\Execution\Hydration\EntityHydrator;
	use Quellabs\ObjectQuel\Execution\Hydration\RelationshipLoader;
	use Quellabs\ObjectQuel\Execution\Hydration\ResultTransformer;
	
	/**
	 * Represents a Quel result.
	 *
	 * Two kinds of statement produce one of these: a `retrieve` (hydration,
	 * relationship loading, and transformation of database rows into entities/
	 * arrays — the ArrayAccess/IteratorAggregate/row-fetching surface below),
	 * and a bulk, set-based write-verb statement (`append`, and later
	 * `replace`/`delete`/upsert — see objectquel-write-verbs-design.md), which
	 * has no rows to hydrate and instead carries an affected-row count and,
	 * when applicable, a generated primary key (getAffectedRows()/
	 * getGeneratedId()). Construct via the named factories below rather than
	 * directly — fromRetrieve() for the former, fromWriteStatement() for the
	 * latter — since the two need entirely different setup.
	 * @implements \ArrayAccess<int, array<string, mixed>>
	 * @implements \IteratorAggregate<int, array<string, mixed>>
	 */
	class QuelResult implements \ArrayAccess, \IteratorAggregate, \JsonSerializable, \Countable {

		/**
		 * Responsible for converting raw data into entity objects.
		 * Null for a write-statement result — there's nothing to hydrate.
		 */
		private readonly ?EntityHydrator $entityHydrator;

		/**
		 * Handles loading relationships between entities.
		 * Null for a write-statement result.
		 */
		private readonly ?RelationshipLoader $relationshipLoader;

		/**
		 * Performs transformations on the result set (like sorting).
		 * Null for a write-statement result.
		 */
		private readonly ?ResultTransformer $resultTransformer;

		/**
		 * Flag indicating if sorting should be handled in application logic rather than database
		 */
		private readonly bool $sortInApplicationLogic;

		/**
		 * The actual result set containing hydrated entities and data.
		 * Always empty for a write-statement result — see getAffectedRows().
		 * @var array<int, array<string, mixed>>
		 */
		private array $result;

		/**
		 * Current position in the result set for iteration
		 */
		private int $index;

		/**
		 * Number of rows a write-verb statement affected. 0 for a retrieve
		 * result — recordCount()/count() are the row-count accessors there.
		 */
		private readonly int $affectedRows;

		/**
		 * The primary key a write-verb statement generated, when applicable
		 * and known (see AppendExecutor). Always null for a retrieve result.
		 */
		private readonly mixed $generatedId;

		/**
		 * Private — construct via fromRetrieve() or fromWriteStatement().
		 */
		private function __construct() {
			$this->index = 0;
		}

		/**
		 * Builds a QuelResult for a `retrieve` query: hydrates the raw rows
		 * into entities/arrays, loads relationships, and applies
		 * application-side sorting when needed.
		 * @param EntityManager $entityManager Entity manager for data handling
		 * @param AstRetrieve $retrieve AST object containing query information
		 * @param array<int, array<string, mixed>> $data Raw data from the database query
		 * @return self
		 * @throws EntityResolutionException
		 * @throws HydrationException
		 * @throws QuelException
		 * @throws \DateInvalidTimeZoneException
		 * @throws \DateMalformedStringException
		 */
		public static function fromRetrieve(EntityManager $entityManager, AstRetrieve $retrieve, array $data): self {
			$instance = new self();

			// Initialize helper objects
			$instance->entityHydrator = new EntityHydrator($entityManager);
			$instance->relationshipLoader = new RelationshipLoader($entityManager, $retrieve);
			$instance->resultTransformer = new ResultTransformer();

			// Determine if sorting should be done in application logic
			// This happens when sort contains method calls and InValuesAreFinal directive is not set
			$instance->sortInApplicationLogic =
				$retrieve->sortContainsJsonIdentifier() || (
					$retrieve->getSortInApplicationLogic() &&
					empty($retrieve->getDirective('InValuesAreFinal'))
				);

			// Get values from the AST (Abstract Syntax Tree)
			$ast = $retrieve->getValues();

			// Process raw data into entity objects
			$result = $instance->entityHydrator->hydrateEntities($ast, $data);

			// Store the processed result
			$instance->result = $result['result'];

			// Load relationships between entities
			$instance->relationshipLoader->loadRelationships(array_values($result['entities']));

			// Sort the results if needed:
			// 1) A method is called in SORT BY clause
			// 2) InValuesAreFinal is not set (with InValuesAreFinal, sorting is based on the IN() list)
			if ($instance->sortInApplicationLogic) {
				$instance->resultTransformer->sortResults($instance->result, $retrieve->getSort());
			}

			$instance->affectedRows = 0;
			$instance->generatedId = null;

			return $instance;
		}

		/**
		 * Builds a QuelResult for a bulk, set-based write-verb statement
		 * (`append`, and later `replace`/`delete`/upsert) — there are no
		 * rows to hydrate, just how many rows were affected and, when
		 * applicable, a generated primary key (see AppendExecutor).
		 * @param int $affectedRows Number of rows affected by the statement
		 * @param mixed $generatedId The generated primary key, if applicable and known; null otherwise
		 * @return self
		 */
		public static function fromWriteStatement(int $affectedRows, mixed $generatedId = null): self {
			$instance = new self();
			$instance->entityHydrator = null;
			$instance->relationshipLoader = null;
			$instance->resultTransformer = null;
			$instance->sortInApplicationLogic = false;
			$instance->result = [];
			$instance->affectedRows = $affectedRows;
			$instance->generatedId = $generatedId;
			return $instance;
		}

		/**
		 * Returns the number of rows inside this recordset
		 * @return int Total count of records in the result set
		 */
		public function recordCount(): int {
			return count($this->result);
		}

		/**
		 * Returns the number of rows a write-verb statement affected. Always
		 * 0 for a retrieve result — see recordCount()/count() for those.
		 * @return int
		 */
		public function getAffectedRows(): int {
			return $this->affectedRows;
		}

		/**
		 * Returns the primary key a write-verb statement generated, when
		 * applicable and known (see AppendExecutor). Always null for a
		 * retrieve result.
		 * @return mixed
		 */
		public function getGeneratedId(): mixed {
			return $this->generatedId;
		}

		/**
		 * Reads a row of a result set and advances the recordset pointer
		 * Similar to PDO's fetch() method
		 * @return array<string, mixed>|null The current row (entity or array) or null if no more rows
		 */
		public function fetchRow(): ?array {
			if ($this->index >= $this->recordCount()) {
				return null;
			}
			
			$result = $this->result[$this->index];
			++$this->index;
			return $result;
		}
		
		/**
		 * Returns the value of $columnName for all rows at once
		 * Similar to PDO's fetchColumn() but returns all matching values
		 * @param string|int $columnName Column name or index to fetch
		 * @return array<int, mixed> Array of values from the specified column
		 */
		public function fetchCol(string|int $columnName = 0): array {
			// If index specifies column, convert to column name
			if (is_int($columnName)) {
				$firstRow = $this->result[0] ?? [];
				$columns = array_keys($firstRow);
				
				if (!isset($columns[$columnName])) {
					throw new \OutOfBoundsException(
						"Column index {$columnName} does not exist."
					);
				}
				
				$columnName = $columns[$columnName];
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
			if ($pos < 0 || $pos > $this->recordCount()) {
				throw new \OutOfBoundsException("Position {$pos} does not exist.");
			}
			
			$this->index = $pos;
		}
		
		/**
		 * Resets the internal cursor to the first row in the result set.
		 * @return void
		 */
		public function rewind(): void {
			$this->index = 0;
		}
		
		/**
		 * Returns all rows in the result set.
		 * Does not modify the current cursor position.
		 * @return array<int, array<string, mixed>> All rows in the result set
		 */
		public function fetchAll(): array {
			return $this->result;
		}
		
		/**
		 * Returns the raw data in this recordset
		 * Provides direct access to the underlying result array
		 * @return array<int, array<string, mixed>> The complete result set
		 */
		public function getResults(): array {
			return $this->result;
		}
		
		/**
		 * Merge another QuelResult or array of rows into this one
		 * Useful for combining multiple result sets
		 * @param array<int, array<string, mixed>>|QuelResult $otherResult The result to merge
		 * @return static Returns a new QuelResult with merged data
		 */
		public function merge(array|QuelResult $otherResult): static {
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
		 * @return \ArrayIterator<int, array<string, mixed>> An iterator for the result set
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
			return isset($this->result[$this->validOffset($offset)]);
		}
		
		/**
		 * ArrayAccess implementation: Gets value at offset
		 * @param mixed $offset The offset to retrieve
		 * @return array<string, mixed>|null The value at the specified offset or null if not found
		 */
		public function offsetGet(mixed $offset): mixed {
			return $this->result[$this->validOffset($offset)] ?? null;
		}
		
		/**
		 * ArrayAccess implementation: Sets value at offset
		 * @param int|null $offset The offset to set
		 * @param array<string, mixed> $value The value to set
		 * @return void
		 */
		public function offsetSet(mixed $offset, mixed $value): void {
			throw new \LogicException('QuelResult is immutable.');
		}
		
		/**
		 * ArrayAccess implementation: Unsets value at offset
		 * @param mixed $offset The offset to unset
		 * @return void
		 */
		public function offsetUnset(mixed $offset): void {
			throw new \LogicException('QuelResult is immutable.');
		}
		
		/**
		 * JsonSerializable implementation: Returns data which can be serialized by json_encode()
		 * @return array<int, array<string, mixed>> The result set that can be JSON serialized
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
		
		/**
		 * Asserts that an offset is a valid array key type (int).
		 * Used by ArrayAccess methods to satisfy static analysis; mixed offsets
		 * are rejected at runtime rather than silently coerced.
		 * @param mixed $offset
		 * @return int
		 */
		private function validOffset(mixed $offset): int {
			if (!is_int($offset)) {
				throw new \InvalidArgumentException(sprintf(
					'Array offset must be int, %s given.',
					get_debug_type($offset)
				));
			}
			
			return $offset;
		}
	}