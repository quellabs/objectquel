<?php
	
	namespace Quellabs\ObjectQuel\Execution\Executors;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Execution\TempTableStage;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
	/**
	 * Executes a TempTableStage by:
	 *   1. Running the inner query through the full QueryExecutor pipeline (which
	 *      handles JSON and database stages correctly via the existing flow).
	 *   2. Inspecting the first result row to infer a column schema, or falling back
	 *      to the inner query's projection list when the result is empty.
	 *   3. Creating a MySQL temporary table with that schema.
	 *   4. Inserting all result rows in batches.
	 *   5. Mutating the AstRangeDatabase: setQuery(null) + setTableName(), so that
	 *      QuelToSQL will reference the temp table as a plain table.
	 *   6. Registering the temp table name for cleanup after the outer query completes.
	 *
	 * Cleanup must be called explicitly by the orchestrating code (PlanExecutor)
	 * in a finally block after the outer stage has finished.
	 *
	 * Empty inner results:
	 *   The correct behaviour depends on the join type of the range:
	 *   - INNER JOIN (isRequired() === true): an empty source means the outer query
	 *     will produce no rows regardless. We skip table creation and return early,
	 *     and PlanExecutor returns an empty result set.
	 *   - LEFT JOIN (isRequired() === false): the outer query must still run and return
	 *     its rows with NULLs for this range's columns. We create an empty temp table
	 *     using column names derived from the inner query's projection list (AstAlias
	 *     nodes), since there are no result rows to infer the schema from.
	 *
	 * Column type inference:
	 *   All columns are created as VARCHAR(255) NULL on first use. This is intentional:
	 *   the exact MySQL type does not matter for a session-scoped temporary table that
	 *   is only used within a single query's execution. Refinement (e.g. detecting
	 *   INT from PHP int values) can be added later without changing the contract.
	 *
	 * Batch size:
	 *   Rows are inserted in batches of INSERT_BATCH_SIZE to avoid hitting MySQL's
	 *   max_allowed_packet limit on very large result sets.
	 */
	class TempTableExecutor {
		
		/**
		 * Number of rows to insert per batch
		 */
		private const INSERT_BATCH_SIZE = 500;
		
		/**
		 * Database connection used to create, populate, and drop temp tables
		 * @var DatabaseAdapter
		 */
		private DatabaseAdapter $connection;
		
		/**
		 * Names of temporary tables created during this execution, registered for cleanup
		 * @var string[]
		 */
		private array $createdTables = [];
		
		/**
		 * Constructor
		 * @param DatabaseAdapter $connection
		 */
		public function __construct(DatabaseAdapter $connection) {
			$this->connection = $connection;
		}
		
		/**
		 * Execute a TempTableStage.
		 *
		 * Runs the inner query through the provided callable (which wraps the full
		 * decomposition pipeline so JSON stages are handled), materialises the results
		 * into a temp table, then mutates the stage's AstRangeDatabase so downstream
		 * SQL generation treats it as an ordinary table reference.
		 *
		 * @param TempTableStage $stage The stage to materialise
		 * @param callable $innerQueryRunner callable(AstRetrieve $query, array $params): array
		 *        Executes the inner query and returns raw rows. Provided by PlanExecutor.
		 * @param array<string, mixed> $params Parameters forwarded to the inner query
		 * @throws QuelException On execution or DDL failure
		 */
		public function execute(TempTableStage $stage, callable $innerQueryRunner, array $params = []): void {
			$range = $stage->getRange();
			$innerQuery = $stage->getInnerQuery();
			
			// Execute the inner query through the full pipeline.
			// This handles JSON stages, sub-decomposition, etc. transparently.
			$rows = $innerQueryRunner($innerQuery, $params);
			
			if (empty($rows)) {
				if ($range->isRequired()) {
					// INNER JOIN: an empty source means the outer query can produce no rows.
					// Skip table creation entirely and return early — PlanExecutor will
					// produce an empty result set, which is correct.
					return;
				}
				
				// LEFT JOIN: the outer query must still run and return its rows with NULLs
				// for this range's columns. Create an empty temp table using column names
				// derived from the inner query's projection list, since there are no result
				// rows to infer the schema from.
				$columns = $this->extractColumnNamesFromQuery($innerQuery);
				$tempName = 'tmp_' . $range->getName() . '_' . uniqid();
				$this->createTable($tempName, $columns);
			} else {
				// Infer column schema from the keys of the first result row
				$columns = array_keys($rows[0]);
				$tempName = 'tmp_' . $range->getName() . '_' . uniqid();
				$this->createTable($tempName, $columns);
				$this->insertRows($tempName, $columns, $rows);
			}
			
			// Register the table name so cleanup() can DROP it later
			$this->createdTables[] = $tempName;
			
			// Mutate the range so QuelToSQL emits a plain table reference.
			// Because this is the same object instance held by the outer ExecutionStage,
			// no further wiring is needed — the change is visible to QuelToSQL immediately.
			//
			// FROM vs JOIN determination: QuelToSQL::getFrom() picks the FROM by finding
			// the first AstRangeDatabase with joinProperty === null. QueryDecomposer::
			// promoteTempTableRanges() promotes temp-table ranges to JOINs by extracting
			// a join condition from the WHERE clause, so that a real database table can
			// take the FROM position when one exists. If no real database table is present,
			// the temp table legitimately becomes the FROM and is left untouched.
			$range->setQuery(null);
			$range->setTableName($tempName);
		}
		
		/**
		 * Drop all temporary tables created during this execution.
		 * Must be called in a finally block after the outer query completes,
		 * whether execution succeeded or failed.
		 * Errors during cleanup are silently swallowed to avoid masking the real result:
		 * MySQL will drop session-scoped temporary tables automatically when the
		 * connection closes anyway.
		 */
		public function cleanup(): void {
			foreach ($this->createdTables as $tableName) {
				try {
					$this->connection->execute("DROP TEMPORARY TABLE IF EXISTS `{$tableName}`");
				} catch (\Throwable) {
					// Silently ignore cleanup failures — see docblock above
				}
			}
			
			$this->createdTables = [];
		}
		
		/**
		 * Returns the list of temporary table names created so far.
		 * Useful for debugging and testing.
		 * @return string[]
		 */
		public function getCreatedTables(): array {
			return $this->createdTables;
		}
		
		/**
		 * Extracts column names from the inner query's projection list.
		 * Used when the inner query returns no rows and the schema cannot be inferred
		 * from result data. Each value in the projection list is an AstAlias node
		 * whose getName() returns the output column name.
		 * @param AstRetrieve $query
		 * @return string[]
		 */
		private function extractColumnNamesFromQuery(AstRetrieve $query): array {
			$columns = [];
			
			foreach ($query->getValues() as $value) {
				$columns[] = $value->getName();
			}
			
			return $columns;
		}
		
		/**
		 * Creates a temporary table with the given name and columns.
		 * All columns are VARCHAR(255) NULL — see class docblock for rationale.
		 * @param string $tableName
		 * @param string[] $columns
		 * @throws QuelException
		 */
		private function createTable(string $tableName, array $columns): void {
			$columnDefs = array_map(
				fn(string $col) => "`{$col}` VARCHAR(255) NULL",
				$columns
			);
			
			$sql = sprintf(
				"CREATE TEMPORARY TABLE `%s` (%s)",
				$tableName,
				implode(', ', $columnDefs)
			);
			
			try {
				$this->connection->execute($sql);
			} catch (\Throwable $e) {
				throw new QuelException(
					"Failed to create temporary table '{$tableName}': {$e->getMessage()}",
					0,
					$e
				);
			}
		}
		
		/**
		 * Inserts rows into the temporary table in batches.
		 * Batching avoids hitting MySQL's max_allowed_packet limit on large result sets.
		 * @param string $tableName
		 * @param string[] $columns
		 * @param list<list<bool|float|int|string|null>> $rows
		 * @throws QuelException
		 */
		private function insertRows(string $tableName, array $columns, array $rows): void {
			$columnList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
			$placeholderRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
			
			foreach (array_chunk($rows, self::INSERT_BATCH_SIZE) as $batch) {
				$placeholders = implode(', ', array_fill(0, count($batch), $placeholderRow));
				$sql = "INSERT INTO `{$tableName}` ({$columnList}) VALUES {$placeholders}";
				
				// Flatten the batch of rows into a single parameter array.
				// Missing keys are treated as null; objects are cast to string defensively.
				$params = [];
				
				foreach ($batch as $row) {
					foreach ($columns as $col) {
						$value = $row[$col] ?? null;
						$params[] = is_scalar($value) || $value === null ? $value : (string)$value;
					}
				}
				
				try {
					$this->connection->execute($sql, $params);
				} catch (\Throwable $e) {
					throw new QuelException(
						"Failed to insert rows into temporary table '{$tableName}': {$e->getMessage()}",
						0,
						$e
					);
				}
			}
		}
	}