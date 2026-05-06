<?php
	
	namespace Quellabs\ObjectQuel\Execution\Executors;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Planner\TempTableStage;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\Exception\QuelException;
	
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
		private const int INSERT_BATCH_SIZE = 500;
		
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
		 * @param callable $runner
		 * @return void
		 * @throws QuelException On execution or DDL failure
		 */
		public function execute(TempTableStage $stage, callable $runner): void {
			$range = $stage->getRange();
			$tableName = $range->getTableName();
			$innerQuery = $stage->getQuery();
			
			// Execute the inner query through the full pipeline.
			// This handles JSON stages, sub-decomposition, etc. transparently.
			$rows = $runner($stage->getInnerPlan());
			
			// INNER JOIN: an empty source means the outer query can produce no rows.
			// Skip table creation entirely — PlanExecutor will produce an empty result set.
			if (empty($rows) && $range->isRequired()) {
				return;
			}
			
			// Infer column schema from result rows when available, or fall back to the
			// projection list for LEFT JOINs where the inner query returned no rows.
			if (empty($rows)) {
				$columns = $this->extractColumnNamesFromQuery($innerQuery);
			} else {
				$columns = array_map(fn($key) => (string)$key, array_keys($rows[0]));
			}
			
			// Create the temporary table and populate it
			$this->createTable($tableName, $columns);
			
			if (!empty($rows)) {
				$this->insertRows($tableName, $columns, $rows);
			}
			
			// Register the table name so cleanup() can DROP it later
			$this->createdTables[] = $tableName;
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
					'table_creation_error',
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
		 * @param list<array<string, bool|float|int|string|null>> $rows
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
						$params[] = $row[$col] ?? null;
					}
				}
				
				try {
					$this->connection->execute($sql, $params);
				} catch (\Throwable $e) {
					throw new QuelException(
						"Failed to insert rows into temporary table '{$tableName}': {$e->getMessage()}",
						'table_population_error',
						0,
						$e
					);
				}
			}
		}
	}