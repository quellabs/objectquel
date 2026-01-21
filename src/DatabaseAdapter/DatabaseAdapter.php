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
	 * Database adapter that ties ObjectQuel and CakePHP Database together
	 * Wraps CakePHP's database connection to provide ObjectQuel-specific functionality
	 * including schema introspection, transaction management, and cross-database compatibility.
	 */
	class DatabaseAdapter {
		
		/** @var Configuration Configuration instance for ObjectQuel settings */
		protected Configuration $configuration;
		
		/** @var Connection CakePHP database connection instance */
		protected Connection $connection;
		
		/** @var array Cached table descriptions for schema introspection */
		protected array $descriptions;
		
		/** @var array Cached extended column descriptions */
		protected array $columns_ex_descriptions;
		
		/** @var int Error code from the last failed database operation (0 = no error) */
		protected int $last_error;
		
		/** @var string Error message from the last failed database operation */
		protected string $last_error_message;
		
		/** @var int Current nesting level of active transactions (0 = no active transaction) */
		protected int $transaction_depth;
		
		/** @var array Cached index definitions for tables */
		protected array $indexes;
		
		/** @var bool|null Cached result of window function support detection (null = not yet tested) */
		private ?bool $supportsWindowFunctionsCache;
		
		/** @var string|null Cached database type identifier (null = not yet determined) */
		private ?string $databaseTypeCache;
		
		/**
		 * Constructs a new database adapter instance
		 * @param Connection $connection CakePHP database connection to wrap
		 */
		public function __construct(Connection $connection) {
			// Store connection
			$this->connection = $connection;
			
			// setup ORM
			$this->descriptions = [];
			$this->columns_ex_descriptions = [];
			$this->indexes = [];
			$this->last_error = 0;
			$this->last_error_message = '';
			$this->transaction_depth = 0;
			$this->supportsWindowFunctionsCache = null;
			$this->databaseTypeCache = null;
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
			$driverClass = get_class($driver);
			
			$this->databaseTypeCache = match ($driverClass) {
				'Cake\Database\Driver\Postgres' => 'pgsql',
				'Cake\Database\Driver\Sqlite' => 'sqlite',
				'Cake\Database\Driver\Sqlserver' => 'sqlsrv',
				default => 'mysql'
			};
			
			return $this->databaseTypeCache;
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
		 * @return AdapterInterface Phinx adapter instance configured for the current database
		 */
		public function getPhinxAdapter(): AdapterInterface {
			// Use the existing connection instead of fetching 'default'
			$connection = $this->connection;
			
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
		
		// ==================== Database Capability Detection ====================
		
		/**
		 * Tests whether the database supports SQL window functions (OVER clause)
		 *
		 * Performs feature detection by executing a test query. Result is cached
		 * for the lifetime of the adapter instance.
		 *
		 * @return bool True if window functions are supported, false otherwise
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
				$this->supportsWindowFunctionsCache = true;
				return true;
			} catch (\Throwable $e) {
				// Window functions not supported (or extremely old engine quirks) → treat as false
				$this->supportsWindowFunctionsCache = false;
				return false;
			}
		}
		
		/**
		 * Checks whether the database supports native ENUM column types
		 * @return bool True if native ENUM types are supported (MySQL/MariaDB), false otherwise
		 */
		public function supportsNativeEnums(): bool {
			return in_array($this->getDatabaseType(), ['mysql', 'mariadb']);
		}
		
		// ==================== Schema Introspection ====================
		
		/**
		 * Retrieves a list of all tables in the database (excluding views)
		 * @return array List of table names
		 */
		public function getTables(): array {
			$schemaCollection = $this->getSchemaCollection();
			return $schemaCollection->listTablesWithoutViews();
		}
		
		/**
		 * Retrieves detailed column definitions for a database table
		 *
		 * Returns comprehensive metadata for each column including type, constraints,
		 * defaults, and special properties like auto-increment and primary key status.
		 *
		 * @param string $tableName Name of the table to analyze
		 * @return array Associative array of column definitions indexed by column name,
		 *               each containing: type, php_type, limit, default, nullable, precision,
		 *               scale, unsigned, generated, identity, primary_key, values
		 */
		public function getColumns(string $tableName): array {
			// Fetch the Phinx adapter
			$phinxAdapter = $this->getPhinxAdapter();
			
			// Get primary key columns first so we can mark them in column definitions
			$primaryKey = $this->getPrimaryKeyColumns($tableName);
			
			// Keep a list of decimal types for precision/scale inclusion
			// Phinx seems to sometimes return precision for integer fields which is incorrect
			$decimalTypes = ['decimal', 'numeric', 'float', 'double'];
			
			// Fetch and process each column in the table
			$result = [];
			
			foreach ($phinxAdapter->getColumns($tableName) as $column) {
				$columnType = $column->getType();
				$isOfDecimalType = in_array(strtolower($columnType), $decimalTypes);
				
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
					'primary_key' => in_array($column->getName(), $primaryKey),
					
					// Values for enums
					'values'      => $column->getValues()
				];
				
				// For enums put the max length in the column data.
				// This is needed to be able to compare entity data with database data
				if ($columnType === 'enum') {
					$columnData['limit'] = max(max(array_map('strlen', $column->getValues())), 32);
				}
				
				$result[$column->getName()] = $columnData;
			}
			
			return $result;
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
		 * @return array List of column names in the primary key, or empty array if none exists
		 */
		public function getPrimaryKeyColumns(string $tableName): array {
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
			
			// Return an empty array if no primary key could be determined
			// This indicates the table has no primary key or it couldn't be detected
			return [];
		}
		
		/**
		 * Retrieves index definitions for a database table
		 * @param string $tableName Name of the table
		 * @return array Associative array of index configurations indexed by index name,
		 *               each containing: columns, type (PRIMARY, UNIQUE, INDEX), and other properties
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
		
		// ==================== Query Execution ====================
		
		/**
		 * Executes a SQL query with optional parameter binding
		 * @param string $query SQL query to execute
		 * @param array $parameters Parameter values for prepared statement placeholders
		 * @return StatementInterface|false Statement object on success, false on failure
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
		 * Retrieves the auto-generated ID from the last INSERT operation
		 * @return int|string|false The last insert ID, or false if not available
		 */
		public function getInsertId(): int|string|false {
			return $this->connection->getDriver()->lastInsertId();
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
		 * Begins a new database transaction
		 * @return void
		 */
		public function beginTrans(): void {
			if ($this->transaction_depth == 0) {
				$this->connection->begin();
			}
			
			$this->transaction_depth++;
		}
		
		/**
		 * Commits the current transaction
		 * @return void
		 */
		public function commitTrans(): void {
			$this->transaction_depth--;
			
			if ($this->transaction_depth == 0) {
				$this->connection->commit();
			}
		}
		
		/**
		 * Rolls back the current transaction
		 * @return void
		 */
		public function rollbackTrans(): void {
			$this->transaction_depth--;
			
			if ($this->transaction_depth == 0) {
				$this->connection->rollback();
			}
		}
	}