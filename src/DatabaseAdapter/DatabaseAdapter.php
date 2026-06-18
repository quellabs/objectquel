<?php
	
	namespace Quellabs\ObjectQuel\DatabaseAdapter;
	
	use Cake\Database\Schema\CollectionInterface;
	use Cake\Database\StatementInterface;
	use Cake\Database\Connection;
	use Phinx\Db\Adapter\AdapterInterface;
	use Phinx\Db\Adapter\AdapterFactory;
	
	/**
	 * Database adapter that ties ObjectQuel and CakePHP Database together
	 * Wraps CakePHP's database connection to provide ObjectQuel-specific functionality
	 * including schema introspection, transaction management, and cross-database compatibility.
	 *
	 * @phpstan-type ColumnDefinition array{
	 *     type: string,
	 *     php_type: string,
	 *     limit: int|array<int, int>|null,
	 *     default: mixed,
	 *     nullable: bool,
	 *     precision: int|null,
	 *     scale: int|null,
	 *     unsigned: bool,
	 *     generated: mixed,
	 *     identity: bool,
	 *     primary_key: bool,
	 *     values: array<int, string>|null
	 * }
	 *
	 * @phpstan-type IndexDefinition array{
	 *     type: 'primary'|'unique'|'index'|'fulltext',
	 *     columns: string[],
	 *     length: array<int, int>|null,
	 *     name?: string
	 * }
	 */
	class DatabaseAdapter {
		
		/** @var array|string[] The index types ObjectQuel supports */
		const array INDEX_TYPES = ['primary', 'unique', 'index', 'fulltext'];
		
		/**
		 * Keep a list of decimal types for precision/scale inclusion
		 * Phinx seems to sometimes return precision for integer fields which is incorrect
		 * @var array|string[] Decimal types in database
		 */
		const array DECIMAL_TYPES = ['decimal', 'numeric', 'float', 'double'];
		
		/** @var Connection CakePHP database connection instance */
		protected Connection $connection;
		
		/** @var int Error code from the last failed database operation (0 = no error) */
		protected int $last_error;
		
		/** @var string Error message from the last failed database operation */
		protected string $last_error_message;
		
		/** @var int Current nesting level of active transactions (0 = no active transaction) */
		protected int $transaction_depth;
		
		/** @var string|null Cached database type identifier (null = not yet determined) */
		private ?string $databaseTypeCache;
		
		/** @var AdapterInterface|null Cached Phinx adapter instance (null = not yet created) */
		private ?AdapterInterface $phinxAdapterCache;
		
		/**
		 * Cached SQL Server database compatibility level (e.g. 170 for SQL
		 * Server 2025), fetched via DATABASEPROPERTYEX(). Null means "not yet
		 * queried, or the query failed". Only meaningful when getDatabaseType()
		 * is 'sqlsrv' — irrelevant for every other engine.
		 * @var int|null
		 */
		private ?int $sqlServerCompatibilityLevelCache;
		
		/**
		 * Constructs a new database adapter instance
		 * @param Connection $connection CakePHP database connection to wrap
		 */
		public function __construct(Connection $connection) {
			$this->connection = $connection;
			$this->last_error = 0;
			$this->last_error_message = '';
			$this->transaction_depth = 0;
			$this->databaseTypeCache = null;
			$this->phinxAdapterCache = null;
			$this->sqlServerCompatibilityLevelCache = null;
		}
		
		// ==================== Connection & Driver Info ====================
		
		/**
		 * Returns the wrapped CakePHP database connection
		 * @return Connection The underlying CakePHP connection instance
		 */
		public function getConnection(): Connection {
			return $this->connection;
		}
		
		/**
		 * Determines the database type from the CakePHP driver class
		 * @return string Database type identifier: 'mysql', 'mariadb', 'pgsql', 'sqlite', or 'sqlsrv'
		 */
		public function getDatabaseType(): string {
			if ($this->databaseTypeCache !== null) {
				return $this->databaseTypeCache;
			}
			
			$driver = $this->connection->getDriver();
			
			$this->databaseTypeCache = match (get_class($driver)) {
				'Cake\Database\Driver\Postgres' => 'pgsql',
				'Cake\Database\Driver\Sqlite' => 'sqlite',
				'Cake\Database\Driver\Sqlserver' => 'sqlsrv',
				default => stripos($driver->version(), 'mariadb') !== false ? 'mariadb' : 'mysql'
			};
			
			return $this->databaseTypeCache;
		}
		
		/**
		 * Returns the normalized server version string.
		 *
		 * MariaDB advertises itself to MySQL clients with a compatibility prefix to
		 * maintain protocol compatibility with older MySQL clients:
		 *   "5.5.5-10.6.1-MariaDB"
		 *
		 * CakePHP's Driver::version() returns this raw string verbatim. This method
		 * strips the compatibility prefix so callers always receive the real version
		 * number regardless of engine, making version_compare() calls safe for both
		 * MySQL and MariaDB.
		 *
		 * @return string Normalized version string (e.g. "8.0.32", "10.6.1-MariaDB")
		 */
		public function getServerVersion(): string {
			// Fetch version number
			$version = $this->connection->getDriver()->version();
			
			// MariaDB prefixes its version string with "5.5.5-" for MySQL client
			// compatibility. Strip it to expose the real version number.
			if (preg_match('/^\d+\.\d+\.\d+-(\d+\.\d+\.\d+-MariaDB.*)$/', $version, $matches)) {
				return $matches[1];
			} else {
				return $version;
			}
		}
		
		/**
		 * Returns the schema collection for database introspection
		 * @return CollectionInterface Schema collection providing access to table metadata
		 */
		public function getSchemaCollection(): CollectionInterface {
			return $this->connection->getSchemaCollection();
		}
		
		/**
		 * Creates a Phinx adapter instance from the current CakePHP connection
		 * Maps CakePHP driver configuration to Phinx adapter format for schema migration support.
		 * The instance is cached for the lifetime of this DatabaseAdapter, since the
		 * underlying CakePHP connection config is immutable after construction.
		 * @return AdapterInterface Phinx adapter instance configured for the current database
		 */
		public function getPhinxAdapter(): AdapterInterface {
			if ($this->phinxAdapterCache !== null) {
				return $this->phinxAdapterCache;
			}
			
			// Use the existing connection instead of fetching 'default'
			$connection = $this->connection;
			
			/**
			 * Get the CakePHP connection config
			 * @var array<string, string> $config
			 */
			$config = $connection->config();
			
			// Map CakePHP driver to Phinx adapter name
			$driverMap = [
				'Cake\Database\Driver\Mysql'     => 'mysql',
				'Cake\Database\Driver\Postgres'  => 'pgsql',
				'Cake\Database\Driver\Sqlite'    => 'sqlite',
				'Cake\Database\Driver\Sqlserver' => 'sqlsrv'
			];
			
			// Get the appropriate adapter name
			$adapter = $driverMap[$config['driver']] ?? 'mysql';
			
			// Convert CakePHP connection config to Phinx format
			$phinxConfig = [
				'adapter' => $adapter,
				'host'    => $config['host'] ?? 'localhost',
				'name'    => $config['database'],
				'user'    => $config['username'],
				'pass'    => $config['password'],
				'port'    => $config['port'] ?? 3306,
				'charset' => $config['encoding'] ?? 'utf8mb4',
			];
			
			// Create and cache the adapter
			$this->phinxAdapterCache = AdapterFactory::instance()->getAdapter($phinxConfig['adapter'], $phinxConfig);
			return $this->phinxAdapterCache;
		}
		
		// ==================== Database Capability Detection ====================
		
		/**
		 * Checks whether the database supports native ENUM column types
		 * @return bool True if native ENUM types are supported (MySQL/MariaDB), false otherwise
		 */
		public function supportsNativeEnums(): bool {
			return in_array($this->getDatabaseType(), ['mysql', 'mariadb'], true);
		}
		
		// ==================== Schema Introspection ====================
		
		/**
		 * Retrieves a list of all tables in the database (excluding views)
		 * @return string[] List of table names
		 */
		public function getTables(): array {
			$schemaCollection = $this->getSchemaCollection();
			return $schemaCollection->listTablesWithoutViews();
		}
		
		/**
		 * Retrieves detailed column definitions for a database table
		 * @param string $tableName Name of the table to analyze
		 * @return array<string, ColumnDefinition>
		 */
		public function getColumns(string $tableName): array {
			// Fetch the Phinx adapter
			$phinxAdapter = $this->getPhinxAdapter();
			
			// Get primary key columns first so we can mark them in column definitions
			$primaryKey = $this->getPrimaryKeyColumns($tableName);
			
			// Fetch and process each column in the table
			$result = [];
			
			foreach ($phinxAdapter->getColumns($tableName) as $column) {
				$columnType = $column->getType();
				$isOfDecimalType = in_array(strtolower($columnType), self::DECIMAL_TYPES);
				
				$columnData = [
					// Basic column type (integer, string, decimal, etc.)
					'type'        => $columnType,
					
					// PHP type of this column
					'php_type'    => TypeMapper::phinxTypeToPhpType($columnType),
					
					// Maximum length for string types or display width for numeric types
					// Only apply if the column type supports limits
					'limit'       => $column->getLimit() ?? TypeMapper::getDefaultLimit($columnType),
					
					// Default value for the column if not specified during insert
					'default'     => $column->getDefault(),
					
					// Whether NULL values are allowed in this column
					'nullable'    => $column->getNull(),
					
					// For numeric types: total number of digits (precision)
					'precision'   => $isOfDecimalType ? $column->getPrecision() : null,
					
					// For decimal types: number of digits after decimal point
					'scale'       => $isOfDecimalType ? $column->getScale() : null,
					
					// Whether column allows negative values (converted from signed to unsigned)
					'unsigned'    => !$column->getSigned(),
					
					// For generated columns (computed values based on expressions)
					'generated'   => $column->getGenerated(),
					
					// Whether column auto-increments (typically for primary keys)
					'identity'    => $column->getIdentity(),
					
					// Whether this column is part of the primary key
					'primary_key' => in_array($column->getName(), $primaryKey, true),
					
					// Values for enums
					'values'      => $column->getValues()
				];
				
				// For enums put the max length in the column data.
				// This is needed to be able to compare entity data with database data
				if ($columnType === 'enum') {
					$columnData['limit'] = $this->resolveEnumLimit($column->getValues());
				}
				
				$result[$column->getName()] = $columnData;
			}
			
			return $result;
		}
		
		/**
		 * Returns the current database's compatibility level on SQL Server
		 * (e.g. 170 for SQL Server 2025), or null if it could not be determined
		 * or the connection is not SQL Server. Result is cached for the lifetime
		 * of this adapter instance.
		 *
		 * Compatibility level is a per-database setting independent of the
		 * engine version — a SQL Server 2025 instance can host a database still
		 * pinned to an older compatibility level (e.g. migrated without ever
		 * raising it), so the engine version returned by getServerVersion()
		 * alone cannot answer "which T-SQL features does this database support".
		 *
		 * @return int|null
		 */
		public function getSqlServerCompatibilityLevel(): ?int {
			// Return cache
			if ($this->sqlServerCompatibilityLevelCache !== null) {
				return $this->sqlServerCompatibilityLevelCache;
			}
			
			// DB_NAME() resolves to the current connection's database, so this
			// works without the caller needing to know or pass the database name.
			$stmt = $this->execute(
				"SELECT DATABASEPROPERTYEX(DB_NAME(), 'CompatibilityLevel') AS compat_level"
			);
			
			if ($stmt === null) {
				return null;
			}
			
			$row = $stmt->fetchAssoc();
			$stmt->closeCursor();
			
			if (!$row || !isset($row['compat_level'])) {
				return null;
			}
			
			return $this->sqlServerCompatibilityLevelCache = (int)$row['compat_level'];
		}
		
		/**
		 * Computes the storage limit for an enum column based on its longest case.
		 * Falls back to a minimum of 32 to leave headroom for entity-side comparisons
		 * against database data, even when the enum has no defined values.
		 * @param array<int, string>|null $values Enum case values
		 * @return int Limit to use for the column definition
		 */
		private function resolveEnumLimit(?array $values): int {
			if (empty($values)) {
				return 32;
			}
			
			$maxLength = max(array_map('strlen', $values));
			return max($maxLength, 32);
		}
		
		/**
		 * Retrieves the primary key column name for a table
		 * For composite primary keys, returns only the first column.
		 * @param string $tableName Name of the table
		 * @return string Primary key column name, or empty string if no primary key exists
		 */
		public function getPrimaryKey(string $tableName): string {
			// Get all primary key columns
			$primaryKeyColumns = $this->getPrimaryKeyColumns($tableName);
			
			// Return first primary key column (assumes single-column PK)
			// Uses null coalescing operator to return empty string if no columns exist
			return $primaryKeyColumns[0] ?? '';
		}
		
		/**
		 * Retrieves all columns that make up the primary key for a table
		 * Supports both single-column and composite primary keys.
		 * @param string $tableName Name of the table
		 * @return string[] List of column names in the primary key, or empty array if none exists
		 */
		public function getPrimaryKeyColumns(string $tableName): array {
			// Get the schema descriptor for the specified table
			$schema = $this->connection->getSchemaCollection()->describe($tableName);
			
			// Iterate through all constraints defined on the table
			foreach ($schema->constraints() as $constraint) {
				// Get detailed information about the current constraint
				$constraintData = $schema->getConstraint($constraint);
				
				// Check if this constraint is a primary key constraint
				if (isset($constraintData['type']) && $constraintData['type'] === 'primary') {
					/**
					 * Return the column names that make up the primary key
					 * This supports both single and composite primary keys
					 * @var array{type: string, columns: array<string>} $constraintData
					 */
					return $constraintData['columns'];
				}
			}
			
			// Return an empty array if no primary key could be determined
			// This indicates the table has no primary key or it couldn't be detected
			return [];
		}
		
		/**
		 * Retrieves index definitions for a database table.
		 * @param string $tableName
		 * @return array<string, IndexDefinition>
		 */
		public function getIndexes(string $tableName): array {
			// Fetch table schema
			$tableSchema = $this->getSchemaCollection()->describe($tableName);
			
			// Collect indexes
			$result = [];
			
			foreach ($tableSchema->indexes() as $indexName) {
				// Fetch index
				$index = $tableSchema->getIndex($indexName);
				
				// getIndex() can theoretically return null on race conditions or
				// schema inconsistencies, so guard defensively.
				if ($index === null) {
					continue;
				}
				
				// Store the index details in the result array, using the index name as key
				// Index details include columns, type (PRIMARY, UNIQUE, INDEX), and other properties
				/** @var array{type: string, columns: array<string>, length: array<int,int>|null} $index */
				if (in_array($index['type'], self::INDEX_TYPES, true)) {
					$type = $index['type'];
				} else {
					$type = 'index';
				}
				
				$result[$indexName] = [
					'type'    => $type,
					'columns' => $index['columns'],
					'length'  => $index['length'],
				];
			}
			
			return $result;
		}
		
		// ==================== Query Execution ====================
		
		/**
		 * Rewrites duplicate named parameters so PDO can bind them.
		 * @param string $sql The SQL query, modified in place
		 * @param array<int|string, mixed> $parameters The parameter bindings, expanded in place
		 * @return void
		 */
		protected function deduplicateParameters(string &$sql, array &$parameters): void {
			// Track how many times each named parameter has been seen so far
			$seen = [];
			
			// The regex alternation is ordered so that string literals are consumed first
			// and never reach the callback as a match group — only bare :param placeholders do.
			// This prevents false positives like WHERE x = ':term' from being rewritten.
			$sql = preg_replace_callback(
				"/'[^']*'|\"[^\"]*\"|:([a-zA-Z_][a-zA-Z0-9_]*)/",
				function (array $match) use (&$seen, &$parameters): string {
					// No capture group means this was a string literal — return it unchanged
					if (!isset($match[1])) {
						return $match[0];
					}
					
					// Fetch the match
					$name = $match[1];
					
					// First occurrence — leave the placeholder as-is
					if (!isset($seen[$name])) {
						$seen[$name] = 1;
						return $match[0];
					}
					
					// Subsequent occurrence — rename to :name_2, :name_3, etc.
					// and copy the original value so the new placeholder gets bound
					$seen[$name]++;
					$newName = $name . '_' . $seen[$name];
					$parameters[$newName] = $parameters[$name];
					return ':' . $newName;
				},
				$sql
			) ?? $sql;
		}
		
		/**
		 * Executes a SQL query with optional parameter binding
		 * @param string $query SQL query to execute
		 * @param array<int|string, mixed> $parameters Parameter values for prepared statement placeholders
		 * @return StatementInterface|null Statement object on success, false on failure
		 */
		public function execute(string $query, array $parameters = []): ?StatementInterface {
			try {
				$this->deduplicateParameters($query, $parameters);
				return $this->connection->execute($query, $parameters);
			} catch (\Exception $exception) {
				$this->last_error = $exception->getCode();
				$this->last_error_message = $exception->getMessage();
				return null;
			}
		}
		
		/**
		 * Retrieves the auto-generated ID from the last INSERT operation
		 * @return int|string|false The last insert ID, or false if not available
		 */
		public function getInsertId(): int|string|false {
			return $this->connection->getDriver()->lastInsertId();
		}
		
		/**
		 * Escapes a database identifier (table or column name)
		 * @param string $identifier The identifier to escape
		 * @return string The escaped identifier wrapped in the driver's quote character
		 */
		public function escapeIdentifier(string $identifier): string {
			return $this->connection->getDriver()->quoteIdentifier($identifier);
		}
		
		// ==================== Error Handling ====================
		
		/**
		 * Returns the error code from the last failed query
		 * @return int Error code (0 indicates no error)
		 */
		public function getLastError(): int {
			return $this->last_error;
		}
		
		/**
		 * Returns the error message from the last failed query
		 * @return string Error message text (empty string indicates no error)
		 */
		public function getLastErrorMessage(): string {
			return $this->last_error_message;
		}
		
		// ==================== Transaction Management ====================
		
		/**
		 * Begins a new database transaction.
		 *
		 * Nesting is depth-counted, not savepoint-based: an inner
		 * rollbackTrans() does not roll back immediately, it only rolls
		 * back once the outermost call unwinds.
		 *
		 * @return void
		 */
		public function beginTrans(): void {
			if ($this->transaction_depth == 0) {
				$this->connection->begin();
			}
			
			$this->transaction_depth++;
		}
		
		/**
		 * Commits the current transaction.
		 * See beginTrans() for notes on logical (depth-counted) nesting.
		 * @return void
		 * @throws \LogicException If called without a matching beginTrans()
		 */
		public function commitTrans(): void {
			if ($this->transaction_depth <= 0) {
				throw new \LogicException('commitTrans() called without an active transaction');
			}
			
			$this->transaction_depth--;
			
			if ($this->transaction_depth == 0) {
				$this->connection->commit();
			}
		}
		
		/**
		 * Rolls back the current transaction.
		 * See beginTrans() for notes on logical (depth-counted) nesting.
		 * @return void
		 * @throws \LogicException If called without a matching beginTrans()
		 */
		public function rollbackTrans(): void {
			if ($this->transaction_depth <= 0) {
				throw new \LogicException('rollbackTrans() called without an active transaction');
			}
			
			$this->transaction_depth--;
			
			if ($this->transaction_depth == 0) {
				$this->connection->rollback();
			}
		}
	}