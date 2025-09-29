<?php
	
	namespace Quellabs\ObjectQuel\DatabaseAdapter;
	
	use Cake\Database\Schema\CollectionInterface;
	use Cake\Database\StatementInterface;
	use Cake\Database\Connection;
	use Cake\Datasource\ConnectionManager;
	use Phinx\Db\Adapter\AdapterInterface;
	use Quellabs\ObjectQuel\Configuration;
	use Phinx\Db\Adapter\AdapterFactory;
	
	/**
	 * Database adapter that ties ObjectQuel and Cakephp/Database together
	 */
	class DatabaseAdapter {
		
		protected Configuration $configuration;
		protected Connection $connection;
		protected array $descriptions;
		protected array $columns_ex_descriptions;
		protected int $last_error;
		protected string $last_error_message;
		protected int $transaction_depth;
		protected array $indexes;
		private ?bool $supportsWindowFunctionsCache;
		private string $databaseTypeCache;
		
		/**
		 * Database Adapter constructor.
		 * This file wraps the functions of CakePHP Database
		 * @param Configuration $configuration
		 */
		public function __construct(Configuration $configuration) {
			// Store configuration object
			$this->configuration = $configuration;
			
			// setup ORM
			$this->descriptions = [];
			$this->columns_ex_descriptions = [];
			$this->indexes = [];
			$this->last_error = 0;
			$this->last_error_message = '';
			$this->transaction_depth = 0;
			$this->supportsWindowFunctionsCache = null;
			
			// Check if connection already exists and drop it if needed
			if (ConnectionManager::getConfig('default')) {
				ConnectionManager::drop('default');
			}
			
			// Create the database connection
			ConnectionManager::setConfig('default', ['url' => $configuration->getDsn()]);
			
			/** @var Connection $connection */
			$connection = ConnectionManager::get('default');
			$this->connection = $connection;
		}
		
		/**
		 * Returns the CakePHP connection
		 * @return Connection
		 */
		public function getConnection(): Connection {
			return $this->connection;
		}
		
		/**
		 * Returns the last occurred error
		 * @return int
		 */
		public function getLastError(): int {
			return $this->last_error;
		}
		
		/**
		 * Returns the last occurred error message
		 * @return string
		 */
		public function getLastErrorMessage(): string {
			return $this->last_error_message;
		}
		
		/**
		 * Get the database type from the CakePHP driver
		 * @return string The database type (mysql, mariadb, pgsql, sqlite, sqlsrv)
		 */
		public function getDatabaseType(): string {
			if ($this->databaseTypeCache === null) {
				$driver = $this->connection->getDriver();
				$driverClass = get_class($driver);
				
				$this->databaseTypeCache = match($driverClass) {
					'Cake\Database\Driver\Mysql' => 'mysql',
					'Cake\Database\Driver\Postgres' => 'pgsql',
					'Cake\Database\Driver\Sqlite' => 'sqlite',
					'Cake\Database\Driver\Sqlserver' => 'sqlsrv',
					default => 'mysql'
				};
			}
			
			return $this->databaseTypeCache;
		}
		
		/**
		 * Execute a query
		 * @param string $query
		 * @param array $parameters Parameters for prepared statements
		 * @return StatementInterface|false
		 */
		public function execute(string $query, array $parameters = []): StatementInterface|false {
			try {
				return $this->connection->execute($query, $parameters);
			} catch (\Exception $exception) {
				$this->last_error = $exception->getCode();
				$this->last_error_message = $exception->getMessage();
				return false;
			}
		}
		
		/**
		 * Retrieves and formats column definitions from the database table
		 * @param string $tableName Name of the table to analyze
		 * @return array Associative array of column definitions indexed by column name
		 */
		public function getColumns(string $tableName): array {
			$result = [];
			
			// Fetch the Phinx adapter
			$phinxAdapter = $this->getPhinxAdapter();
			
			// Get primary key columns first so we can mark them in column definitions
			$primaryKey = $this->getPrimaryKeyColumns($tableName);
			
			// Keep a list of decimal types for precision/scale inclusion
			// Phinx seems to sometimes return precision for integer fields which is incorrect
			$decimalTypes = ['decimal', 'numeric', 'float', 'double'];
			
			// Fetch and process each column in the table
			foreach ($phinxAdapter->getColumns($tableName) as $column) {
				$columnType = $column->getType();
				$isOfDecimalType = in_array(strtolower($columnType), $decimalTypes);
				
				$result[$column->getName()] = [
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
					'primary_key' => in_array($column->getName(), $primaryKey),
				];
			}
			
			return $result;
		}
		
		/**
		 * Returns the name of the primary key column
		 * @param string $tableName
		 * @return string
		 */
		public function getPrimaryKey(string $tableName): string {
			// Get all primary key columns
			$primaryKeyColumns = $this->getPrimaryKeyColumns($tableName);
			
			// Return first primary key column (assumes single-column PK)
			// Uses null coalescing operator to return empty string if no columns exist
			return $primaryKeyColumns[0] ?? '';
		}
		
		/**
		 * Returns the primary key columns for a table
		 * @param string $tableName
		 * @return array
		 */
		private function getPrimaryKeyColumns(string $tableName): array {
			try {
				// Get the schema descriptor for the specified table
				$schema = $this->connection->getSchemaCollection()->describe($tableName);
				
				// Iterate through all constraints defined on the table
				foreach ($schema->constraints() as $constraint) {
					// Get detailed information about the current constraint
					$constraintData = $schema->getConstraint($constraint);
					
					// Check if this constraint is a primary key constraint
					if ($constraintData['type'] === 'primary') {
						// Return the column names that make up the primary key
						// This supports both single and composite primary keys
						return $constraintData['columns'];
					}
				}
			} catch (\Exception $e) {
				// Silently fail and return empty string
			}
			
			// Return an empty array if no primary key could be determined
			// This indicates the table has no primary key or it couldn't be detected
			return [];
		}
		
		/**
		 * Fetch a list of tables
		 * @return array
		 */
		public function getTables(): array {
			$schemaCollection = $this->getSchemaCollection();
			return $schemaCollection->listTablesWithoutViews();
		}
		
		/**
		 * Begin a new transaction.
		 * @return void
		 */
		public function beginTrans(): void {
			if ($this->transaction_depth == 0) {
				$this->connection->begin();
			}
			
			$this->transaction_depth++;
		}
		
		/**
		 * Commit the current transaction.
		 * @return void
		 */
		public function commitTrans(): void {
			$this->transaction_depth--;
			
			if ($this->transaction_depth == 0) {
				$this->connection->commit();
			}
		}
		
		/**
		 * Rollback the current transaction.
		 * @return void
		 */
		public function rollbackTrans(): void {
			$this->transaction_depth--;
			
			if ($this->transaction_depth == 0) {
				$this->connection->rollback();
			}
		}
		
		/**
		 * Fetches a single value from the database using the provided query and parameters
		 * @param string $query The SQL query to execute
		 * @param array $parameters Optional array of parameters to bind to the query
		 * @return mixed            Returns the first column of the first row if found, false if no results
		 */
		public function getOne(string $query, array $parameters = []): mixed {
			// Execute the query with provided parameters
			$rs = $this->execute($query, $parameters);
			
			// Return false if no recordset returned
			if (!$rs) {
				return false;
			}
			
			// Fetch the first row
			$row = $rs->fetch('assoc');
			
			// Return false if no row found
			if (empty($row)) {
				return false;
			}
			
			// Return the first column value from the row
			return reset($row);
		}
		
		/**
		 * Fetches a single row from the database using the provided query and parameters
		 * @param string $query The SQL query to execute
		 * @param array $parameters Optional array of parameters to bind to the query
		 * @return array             Returns the first row as an associative array if found, empty array if no results
		 */
		public function getRow(string $query, array $parameters = []): array {
			// Execute the query with provided parameters
			$rs = $this->execute($query, $parameters);
			
			// Return an empty array if no recordset returned
			if (!$rs) {
				return [];
			}
			
			// Return first row from recordset as an array
			$row = $rs->fetch('assoc');
			return $row ?: [];
		}
		
		/**
		 * Fetches a column from the database using the provided query and parameters
		 * @param string $query The SQL query to execute
		 * @param array $parameters Optional array of parameters to bind to the query
		 * @return array             Returns the values from the first column as an array
		 */
		public function getCol(string $query, array $parameters = []): array {
			// Execute the query with provided parameters
			$rs = $this->execute($query, $parameters);
			
			// Return an empty array if no recordset returned
			if (!$rs) {
				return [];
			}
			
			// Fetch all rows and extract the first column
			$result = [];
			$firstCol = null;
			
			while ($row = $rs->fetch('assoc')) {
				if ($firstCol === null) {
					$keys = array_keys($row);
					$firstCol = $keys[0];
				}
				$result[] = $row[$firstCol];
			}
			
			return $result;
		}
		
		/**
		 * Fetches all rows from the database using the provided query and parameters
		 * @param string $query The SQL query to execute
		 * @param array $parameters Optional array of parameters to bind to the query
		 * @return array             Returns all rows as an array of associative arrays
		 */
		public function getAll(string $query, array $parameters = []): array {
			// Execute the query with provided parameters
			$rs = $this->execute($query, $parameters);
			
			// Return an empty array if no recordset returned
			if (!$rs) {
				return [];
			}
			
			// Fetch all rows
			$result = [];
			while ($row = $rs->fetch('assoc')) {
				$result[] = $row;
			}
			
			return $result;
		}
		
		/**
		 * Returns a table's foreign key information
		 * @param string $tableName
		 * @return array
		 */
		public function getForeignKeys(string $tableName): array {
			try {
				// This array will receive the foreign key data
				$foreignKeys = [];
				
				// Get the schema descriptor for the specified table
				$schema = $this->connection->getSchemaCollection()->describe($tableName);
				
				// Iterate through all constraints defined on the table
				foreach ($schema->constraints() as $constraint) {
					// Get detailed information about the current constraint
					$constraintData = $schema->getConstraint($constraint);
					
					// Check if this constraint is a foreign key constraint
					if ($constraintData['type'] === 'foreign') {
						// Build the foreign key information array to match the original format
						foreach ($constraintData['columns'] as $index => $column) {
							$foreignKeys[] = [
								'COLUMN_NAME'            => $column,
								'CONSTRAINT_NAME'        => $constraint,
								'REFERENCED_TABLE_NAME'  => $constraintData['references'][0] ?? null,
								'REFERENCED_COLUMN_NAME' => $constraintData['references'][1][$index] ?? null
							];
						}
					}
				}
				
				return $foreignKeys;
			} catch (\Exception $e) {
				// Silently fail and return an empty array
				return [];
			}
		}
		
		/**
		 * Returns the insert id
		 * @return int|string|false
		 */
		public function getInsertId(): int|string|false {
			return $this->connection->getDriver()->lastInsertId();
		}
		
		/**
		 * Returns the schema collection of this connection
		 * @return CollectionInterface
		 */
		public function getSchemaCollection(): CollectionInterface {
			return $this->connection->getSchemaCollection();
		}
		
		/**
		 * Returns a list of indexes for a specified database table
		 * @param string $tableName The name of the table to retrieve indexes from
		 * @return array An associative array of indexes with their details
		 */
		public function getIndexes(string $tableName): array {
			// Get the schema collection which provides access to database metadata
			$schemaCollection = $this->getSchemaCollection();
			
			// Retrieve the table schema which contains structural information about the table
			$tableSchema = $schemaCollection->describe($tableName);
			
			// Get an array of index names defined on this table
			$indexes = $tableSchema->indexes();
			
			// Iterate through each index name and retrieve its detailed configuration
			$result = [];
			
			foreach ($indexes as $index) {
				// Store the index details in the result array, using the index name as key
				// Index details include columns, type (PRIMARY, UNIQUE, INDEX), and other properties
				$result[$index] = $tableSchema->getIndex($index);
			}
			
			return $result;
		}
		
		/**
		 * Get a Phinx adapter instance using CakePHP's database connection
		 * @return AdapterInterface
		 */
		public function getPhinxAdapter(): AdapterInterface {
			// Get the CakePHP connection
			$connection = ConnectionManager::get('default');
			
			// Get the CakePHP connection config
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
			
			// Create and return the adapter
			return AdapterFactory::instance()->getAdapter($phinxConfig['adapter'], $phinxConfig);
		}

		/**
		 * Detects support for SQL window functions (OVER()) by feature probing.
		 * Uses a portable query that should work across vendors that implement windows.
		 * Result is cached for the lifetime of this adapter.
		 */
		public function supportsWindowFunctions(): bool {
			if ($this->supportsWindowFunctionsCache !== null) {
				return $this->supportsWindowFunctionsCache;
			}
			
			// Portable probe: COUNT(...) OVER () over a single-row derived table.
			// If window functions aren't supported, this will raise a syntax error.
			$probeSql = 'SELECT COUNT(1) OVER () AS __wf FROM (SELECT 1) t';
			
			try {
				// Bypass our execute() wrapper so we don't set last_error on capability checks
				$stmt = $this->connection->execute($probeSql);
				
				// Some drivers need an explicit close to free the cursor
				$stmt->closeCursor();
				
				// Return true if window functions are supported
				return $this->supportsWindowFunctionsCache = true;
			} catch (\Throwable $e) {
				// Window functions not supported (or extremely old engine quirks) → treat as false
				return $this->supportsWindowFunctionsCache = false;
			}
		}
		
		/**
		 * Check if the database supports native ENUM column types
		 * @return bool True if native enums are supported
		 */
		public function supportsNativeEnums(): bool {
			return in_array($this->getDatabaseType(), ['mysql', 'mariadb']);
		}
	}