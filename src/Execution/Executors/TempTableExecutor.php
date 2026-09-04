<?php
	
	namespace Quellabs\ObjectQuel\Execution\Executors;
	
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\DDLTypeMapper;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Planner\TempTableStage;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	
	/**
	 * Executes a TempTableStage by:
	 *   1. Running the inner query through the full QueryExecutor pipeline (which
	 *      handles JSON and database stages correctly via the existing flow).
	 *   2. Inspecting the first result row to infer a column schema, or falling back
	 *      to the inner query's projection list when the result is empty.
	 *   3. Creating a session-scoped temporary table with that schema, using DDL
	 *      appropriate to the connected engine (see DDLTypeMapper).
	 *   4. Inserting all result rows in batches.
	 *   5. Mutating the AstRangeDatabase via setTableName() with the resolved
	 *      physical table name, so QuelToSQLRetrieve will reference the temp table as a
	 *      plain table.
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
	 *   Each column's SQL type is resolved from the source entity's declared @Column
	 *   type via EntityStore metadata, when the projected value is a plain entity
	 *   property reference. Columns that don't trace back to a single entity property
	 *   (function calls, computed expressions, literals) fall back to VARCHAR(255).
	 *
	 * Batch size:
	 *   Rows are inserted in batches of INSERT_BATCH_SIZE to avoid hitting
	 *   per-statement/packet size limits (e.g. MySQL's max_allowed_packet) on
	 *   very large result sets.
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
		 * Entity metadata source used to resolve declared column types for the
		 * temp table schema.
		 * @var EntityStore
		 */
		private EntityStore $entityStore;

		/**
		 * Renders temp table DDL (CREATE/DROP keywords, physical name, column
		 * types) correctly for whichever engine is connected.
		 * @var DDLTypeMapper
		 */
		private DDLTypeMapper $ddlTypeMapper;

		/**
		 * Quotes table/column identifiers correctly for whichever engine is connected.
		 * @var SqlIdentifierQuoter
		 */
		private SqlIdentifierQuoter $identifierQuoter;

		/**
		 * Names of temporary tables created during this execution, registered for cleanup.
		 * These are physical names (see DDLTypeMapper::getTempTableName()),
		 * not the logical range table name.
		 * @var string[]
		 */
		private array $createdTables = [];

		/**
		 * TempTableExecutor constructor
		 * @param DatabaseAdapter $connection
		 * @param EntityStore $entityStore
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(DatabaseAdapter $connection, EntityStore $entityStore, PlatformCapabilitiesInterface $platform) {
			$this->connection = $connection;
			$this->entityStore = $entityStore;
			$this->ddlTypeMapper = new DDLTypeMapper($platform);
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
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
			$innerQuery = $stage->getQuery();

			// Resolve the physical table name for the connected engine (SQL Server
			// prefixes with '#'; every other engine uses the logical name as-is)
			// and store it back on the range so downstream SQL generation
			// (QuelToSQLRetrieve) references the same physical table this method creates.
			$tableName = $this->ddlTypeMapper->getTempTableName($range->getTableName());
			$range->setTableName($tableName);
			
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
			
			// Resolve each column's SQL type from the source entity's declared
			// @Column metadata, so joins against typed columns don't run through
			// implicit VARCHAR conversion (see class docblock).
			$columnTypes = $this->resolveColumnTypes($innerQuery);

			// Create the temporary table and populate it
			$this->createTable($tableName, $columns, $columnTypes);
			
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
		 * every supported engine drops session-scoped temporary tables automatically
		 * when the connection closes anyway.
		 */
		public function cleanup(): void {
			foreach ($this->createdTables as $tableName) {
				try {
					$quotedName = $this->identifierQuoter->quoteIdentifier($tableName);
					$this->connection->execute("{$this->ddlTypeMapper->getDropTempTableKeyword()} {$quotedName}");
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
		 * Resolves the SQL column type for every projected value, keyed by output
		 * column name (the AstAlias name), by inspecting the query's projection list.
		 * @param AstRetrieve $query
		 * @return array<string, string> Column name => SQL type
		 */
		private function resolveColumnTypes(AstRetrieve $query): array {
			$types = [];

			foreach ($query->getValues() as $value) {
				$types[$value->getName()] = $this->resolveColumnType($value->getExpression());
			}

			return $types;
		}

		/**
		 * Resolves the SQL type for a single projected expression. Only a plain
		 * entity property reference (e.g. `p.price`) can be traced back to a
		 * declared @Column type; anything else (function calls, computed
		 * expressions, literals) falls back to VARCHAR(255).
		 * @param AstInterface $expression
		 * @return string
		 */
		private function resolveColumnType(AstInterface $expression): string {
			if (!$expression instanceof AstIdentifier) {
				return 'VARCHAR(255)';
			}

			$entityName = $expression->getEntityName();

			if ($entityName === null) {
				return 'VARCHAR(255)';
			}

			try {
				$metadata = $this->entityStore->getMetadata($entityName);
			} catch (EntityResolutionException) {
				return 'VARCHAR(255)';
			}

			$columnName = $metadata->getColumnName($expression->getPropertyName());
			$columnDefinition = $columnName !== null ? ($metadata->columnDefinitions[$columnName] ?? null) : null;

			if ($columnDefinition === null) {
				return 'VARCHAR(255)';
			}

			return $this->ddlTypeMapper->getTempTableColumnType($columnDefinition);
		}

		/**
		 * Creates a temporary table with the given name and columns.
		 * Each column uses the SQL type resolved by resolveColumnTypes(), falling
		 * back to VARCHAR(255) for columns that couldn't be resolved — see class
		 * docblock for rationale.
		 * @param string $tableName
		 * @param string[] $columns
		 * @param array<string, string> $columnTypes Column name => SQL type
		 * @throws QuelException
		 */
		private function createTable(string $tableName, array $columns, array $columnTypes): void {
			$columnDefs = array_map(
				fn(string $col) => $this->identifierQuoter->quoteIdentifier($col) . ' ' . ($columnTypes[$col] ?? 'VARCHAR(255)') . " NULL",
				$columns
			);

			$sql = sprintf(
				"%s %s (%s)",
				$this->ddlTypeMapper->getTemporaryCreateTableKeyword(),
				$this->identifierQuoter->quoteIdentifier($tableName),
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
		 * Batching avoids hitting per-statement/packet size limits (e.g. MySQL's
		 * max_allowed_packet) on large result sets.
		 * @param string $tableName
		 * @param string[] $columns
		 * @param list<array<string, bool|float|int|string|null>> $rows
		 * @throws QuelException
		 */
		private function insertRows(string $tableName, array $columns, array $rows): void {
			$columnList = implode(', ', array_map(fn($c) => $this->identifierQuoter->quoteIdentifier($c), $columns));
			$quotedTableName = $this->identifierQuoter->quoteIdentifier($tableName);
			$placeholderRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

			foreach (array_chunk($rows, self::INSERT_BATCH_SIZE) as $batch) {
				$placeholders = implode(', ', array_fill(0, count($batch), $placeholderRow));
				$sql = "INSERT INTO {$quotedTableName} ({$columnList}) VALUES {$placeholders}";
				
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