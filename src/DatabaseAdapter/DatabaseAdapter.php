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
	 *
	 * @phpstan-type ForeignKeyDefinition array{
	 *     columns: string[],
	 *     referencedTable: string,
	 *     referencedColumns: string[],
	 *     onDelete: string,
	 *     onUpdate: string
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

			// SQLite disables foreign-key enforcement per-connection by default, even when
			// the schema declares real FK constraints. Without this, a constraint generated
			// from an @Orm\ForeignKey annotation (or any FK already present in the schema)
			// would silently do nothing on SQLite.
			if ($this->getDatabaseType() === 'sqlite') {
				$this->execute('PRAGMA foreign_keys = ON');
			}
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
				'suffix'  => ''
			];

			// Create and cache the adapter
			$this->phinxAdapterCache = AdapterFactory::instance()->getAdapter($phinxConfig['adapter'], $phinxConfig);
			return $this->phinxAdapterCache;
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
			
			// CakePHP's schema model treats UNIQUE indexes as table constraints, not as
			// indexes: $tableSchema->indexes() only ever yields plain KEY / FULLTEXT
			// entries. Without reading constraints() too, every UNIQUE index would be
			// invisible here and would be reported as "missing" by IndexComparator on
			// every single make:migrations run, forever, even immediately after it was
			// created.
			foreach ($tableSchema->constraints() as $constraintName) {
				$constraint = $tableSchema->getConstraint($constraintName);
				
				// getConstraint() can theoretically return null on race conditions or
				// schema inconsistencies, so guard defensively. Only unique constraints
				// are relevant here; primary keys and foreign keys are handled elsewhere.
				if ($constraint === null || $constraint['type'] !== 'unique') {
					continue;
				}
				
				/** @var array{type: string, columns: array<string>} $constraint */
				$result[$constraintName] = [
					'type'    => 'unique',
					'columns' => $constraint['columns'],
					'length'  => null,
				];
			}
			
			return $result;
		}

		/**
		 * Retrieves foreign key constraint definitions for a database table.
		 *
		 * Implemented for every engine getDatabaseType() can identify; the
		 * 'default' branch is a defensive fallback for a future unmapped engine,
		 * returning an empty array rather than throwing. Callers that diff
		 * against the result should check
		 * PlatformCapabilitiesInterface::supportsForeignKeyIntrospection() first —
		 * an empty result there means "not introspectable", not "none exist".
		 * @param string $tableName
		 * @return array<string, ForeignKeyDefinition> Constraint name => definition
		 */
		public function getForeignKeys(string $tableName): array {
			return match ($this->getDatabaseType()) {
				'sqlite'           => $this->getSqliteForeignKeys($tableName),
				'mysql', 'mariadb' => $this->getMysqlForeignKeys($tableName),
				'pgsql'            => $this->getPostgresForeignKeys($tableName),
				'sqlsrv'           => $this->getSqlServerForeignKeys($tableName),
				default            => [],
			};
		}

		/**
		 * Reads foreign keys for a table on MySQL/MariaDB.
		 *
		 * KEY_COLUMN_USAGE alone maps columns to the referenced table/column but doesn't
		 * carry the ON DELETE/UPDATE action, so it's joined against REFERENTIAL_CONSTRAINTS
		 * (matched on CONSTRAINT_NAME + CONSTRAINT_SCHEMA) to get the actual delete/update rule.
		 * @param string $tableName
		 * @return array<string, ForeignKeyDefinition> Constraint name => definition
		 */
		private function getMysqlForeignKeys(string $tableName): array {
			$statement = $this->execute(
				"SELECT
					kcu.CONSTRAINT_NAME AS constraint_name,
					kcu.COLUMN_NAME AS column_name,
					kcu.ORDINAL_POSITION AS ordinal_position,
					kcu.REFERENCED_TABLE_NAME AS referenced_table,
					kcu.REFERENCED_COLUMN_NAME AS referenced_column,
					rc.DELETE_RULE AS delete_rule,
					rc.UPDATE_RULE AS update_rule
				FROM information_schema.KEY_COLUMN_USAGE kcu
				JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
					ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
					AND rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
				WHERE kcu.TABLE_SCHEMA = DATABASE()
					AND kcu.TABLE_NAME = :tableName
					AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
				ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION",
				['tableName' => $tableName]
			);

			if ($statement === null) {
				return [];
			}

			$result = [];

			/** @var array{constraint_name: string, column_name: string, referenced_table: string, referenced_column: string, delete_rule: string, update_rule: string} $row */
			foreach ($statement->fetchAll('assoc') as $row) {
				$name = $row['constraint_name'];

				if (!isset($result[$name])) {
					$result[$name] = [
						'columns'           => [],
						'referencedTable'   => $row['referenced_table'],
						'referencedColumns' => [],
						'onDelete'          => $row['delete_rule'],
						'onUpdate'          => $row['update_rule'],
					];
				}

				$result[$name]['columns'][] = $row['column_name'];
				$result[$name]['referencedColumns'][] = $row['referenced_column'];
			}

			return $result;
		}

		/**
		 * Reads foreign keys for a table on SQLite via PRAGMA foreign_key_list().
		 *
		 * Rows sharing the same 'id' belong to the same (possibly composite) constraint,
		 * ordered by 'seq'. SQLite assigns no constraint name, so a deterministic one is
		 * synthesized from the table and local columns — matching the naming convention
		 * MakeMigrationsCommand uses when generating constraints, so round-tripping a
		 * generated constraint back through this method compares equal.
		 * @param string $tableName
		 * @return array<string, ForeignKeyDefinition> Constraint name => definition
		 */
		private function getSqliteForeignKeys(string $tableName): array {
			$quotedTable = $this->escapeIdentifier($tableName);
			$statement = $this->execute("PRAGMA foreign_key_list({$quotedTable})");

			if ($statement === null) {
				return [];
			}

			$byId = [];

			/** @var array{id: int, seq: int, table: string, from: string, to: string, on_update: string, on_delete: string} $row */
			foreach ($statement->fetchAll('assoc') as $row) {
				$byId[$row['id']]['referencedTable'] ??= $row['table'];
				$byId[$row['id']]['onDelete'] ??= strtoupper($row['on_delete']);
				$byId[$row['id']]['onUpdate'] ??= strtoupper($row['on_update']);
				$byId[$row['id']]['columns'][(int)$row['seq']] = $row['from'];
				$byId[$row['id']]['referencedColumns'][(int)$row['seq']] = $row['to'];
			}

			$result = [];

			foreach ($byId as $definition) {
				ksort($definition['columns']);
				ksort($definition['referencedColumns']);
				$definition['columns'] = array_values($definition['columns']);
				$definition['referencedColumns'] = array_values($definition['referencedColumns']);

				$name = 'fk_' . $tableName . '_' . implode('_', $definition['columns']);
				$result[$name] = $definition;
			}

			return $result;
		}

		/**
		 * Reads foreign keys for a table on PostgreSQL via information_schema.
		 *
		 * information_schema has no ordinal linking a composite constraint's local
		 * columns to its referenced columns, so joining produces every possible
		 * pairing, not just the real ones. Since @Orm\ForeignKey only ever declares
		 * single-column constraints, a constraint resolving to more than one local
		 * column is a real composite FK and is simply not reported, rather than
		 * risk a wrongly-paired column set.
		 * @param string $tableName
		 * @return array<string, ForeignKeyDefinition> Constraint name => definition
		 */
		private function getPostgresForeignKeys(string $tableName): array {
			$statement = $this->execute(
				"SELECT
					tc.constraint_name AS constraint_name,
					kcu.column_name AS column_name,
					ccu.table_name AS referenced_table,
					ccu.column_name AS referenced_column,
					rc.delete_rule AS delete_rule,
					rc.update_rule AS update_rule
				FROM information_schema.table_constraints tc
				JOIN information_schema.key_column_usage kcu
					ON kcu.constraint_name = tc.constraint_name
					AND kcu.constraint_schema = tc.constraint_schema
				JOIN information_schema.referential_constraints rc
					ON rc.constraint_name = tc.constraint_name
					AND rc.constraint_schema = tc.constraint_schema
				JOIN information_schema.constraint_column_usage ccu
					ON ccu.constraint_name = rc.unique_constraint_name
					AND ccu.constraint_schema = rc.unique_constraint_schema
				WHERE tc.constraint_type = 'FOREIGN KEY'
					AND tc.table_schema = current_schema()
					AND tc.table_name = :tableName
				ORDER BY tc.constraint_name, kcu.ordinal_position",
				['tableName' => $tableName]
			);

			if ($statement === null) {
				return [];
			}

			$byName = [];

			/** @var array{constraint_name: string, column_name: string, referenced_table: string, referenced_column: string, delete_rule: string, update_rule: string} $row */
			foreach ($statement->fetchAll('assoc') as $row) {
				$byName[$row['constraint_name']][] = $row;
			}

			$result = [];

			foreach ($byName as $name => $rows) {
				$localColumns = array_values(array_unique(array_column($rows, 'column_name')));

				if (count($localColumns) !== 1) {
					continue;
				}

				$result[$name] = [
					'columns'           => $localColumns,
					'referencedTable'   => $rows[0]['referenced_table'],
					'referencedColumns' => [$rows[0]['referenced_column']],
					'onDelete'          => $rows[0]['delete_rule'],
					'onUpdate'          => $rows[0]['update_rule'],
				];
			}

			return $result;
		}

		/**
		 * Reads foreign keys for a table on SQL Server via sys.foreign_keys /
		 * sys.foreign_key_columns, which stores one row per column pair natively
		 * (constraint_column_id gives the correct ordinal), so composite
		 * constraints round-trip correctly with no pairing ambiguity.
		 * @param string $tableName
		 * @return array<string, ForeignKeyDefinition> Constraint name => definition
		 */
		private function getSqlServerForeignKeys(string $tableName): array {
			$statement = $this->execute(
				"SELECT
					fk.name AS constraint_name,
					pc.name AS column_name,
					fkc.constraint_column_id AS ordinal_position,
					rt.name AS referenced_table,
					rc.name AS referenced_column,
					fk.delete_referential_action_desc AS delete_rule,
					fk.update_referential_action_desc AS update_rule
				FROM sys.foreign_keys fk
				JOIN sys.foreign_key_columns fkc
					ON fkc.constraint_object_id = fk.object_id
				JOIN sys.columns pc
					ON pc.object_id = fkc.parent_object_id AND pc.column_id = fkc.parent_column_id
				JOIN sys.columns rc
					ON rc.object_id = fkc.referenced_object_id AND rc.column_id = fkc.referenced_column_id
				JOIN sys.tables t
					ON t.object_id = fk.parent_object_id
				JOIN sys.tables rt
					ON rt.object_id = fk.referenced_object_id
				WHERE t.name = :tableName
				ORDER BY fk.name, fkc.constraint_column_id",
				['tableName' => $tableName]
			);

			if ($statement === null) {
				return [];
			}

			$byName = [];

			/** @var array{constraint_name: string, column_name: string, ordinal_position: int|string, referenced_table: string, referenced_column: string, delete_rule: string, update_rule: string} $row */
			foreach ($statement->fetchAll('assoc') as $row) {
				$byName[$row['constraint_name']][(int)$row['ordinal_position']] = $row;
			}

			$result = [];

			foreach ($byName as $name => $rows) {
				ksort($rows);
				$first = reset($rows);

				$result[$name] = [
					'columns'           => array_column($rows, 'column_name'),
					'referencedTable'   => $first['referenced_table'],
					'referencedColumns' => array_column($rows, 'referenced_column'),
					// SQL Server uses underscores (NO_ACTION, SET_NULL) where every
					// other engine uses spaces; normalize for a consistent string diff.
					'onDelete'          => str_replace('_', ' ', $first['delete_rule']),
					'onUpdate'          => str_replace('_', ' ', $first['update_rule']),
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