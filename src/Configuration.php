<?php
	
	namespace Quellabs\ObjectQuel;
	
	/**
	 * Configuration class for ObjectQuel ORM
	 * @implements \ArrayAccess Provides array-like access to configuration parameters
	 */
	class Configuration implements \ArrayAccess {
		
		/**
		 * @var array Database connection parameters
		 *
		 * Contains all database connection settings including:
		 * - driver: Database driver (mysql, pgsql, sqlite, etc.)
		 * - host: Database server hostname or IP
		 * - database: Database name
		 * - username: Database username
		 * - password: Database password
		 * - port: Database port number
		 * - encoding: Character encoding (default: utf8mb4)
		 * - flags: Additional PDO connection flags
		 * - dsn: Complete DSN string
		 */
		private array $connectionParams = [];
		
		/**
		 * @var string The DSN string for database connection
		 * Data Source Name string used for PDO connections.
		 * Format: driver://username:password@host:port/database?encoding=charset
		 * Example: mysql://user:pass@localhost:3306/mydb?encoding=utf8mb4
		 */
		private string $dsn = '';
		
		/**
		 * @var string Directory where proxy classes will be stored
		 * Proxy classes are dynamically generated PHP classes that extend entity classes
		 * to provide lazy loading capabilities and change tracking functionality.
		 * This directory must be writable by the web server process.
		 */
		private string $proxyDir = '';
		
		/**
		 * @var array Paths to entity classes
		 * Array of directory paths where entity classes are located.
		 * Supports multiple paths to allow for modular entity organization.
		 * The 'core' key is maintained for backwards compatibility.
		 */
		private array $entityPaths = [];
		
		/**
		 * @var string Namespace for entities
		 * Base namespace where all entity classes are located.
		 * Used by the ORM to automatically discover and load entity classes.
		 * Default: 'Quellabs\ObjectQuel\Entity'
		 */
		private string $entityNameSpace = '';
		
		/**
		 * @var bool Whether to use metadata cache
		 * When enabled, entity metadata (annotations, mappings, relationships)
		 * is cached to improve performance by avoiding repeated reflection operations.
		 * Should be enabled in production environments.
		 */
		private bool $useMetadataCache = true;
		
		/**
		 * @var string Annotation cache directory
		 * Directory where cached metadata files are stored.
		 * Must be writable by the web server process.
		 * Only used when $useMetadataCache is true.
		 */
		private string $metadataCachePath = '';
		
		/**
		 * @var string Migration path
		 * Directory containing database migration files.
		 * Migrations are used to version control database schema changes
		 * and ensure consistent database structure across environments.
		 */
		private string $migrationsPath = '';
		
		/**
		 * @var int|null Window size to use for pagination, or null if none
		 * Default number of records to return per page in paginated queries.
		 * Set to null to disable default pagination.
		 * Can be overridden on a per-query basis.
		 */
		private ?int $defaultWindowSize = null;
		
		/**
		 * Constructor - optionally initialize with connection parameters
		 * @param array $connectionParams Database connection parameters (optional)
		 */
		public function __construct(array $connectionParams = []) {
			if (!empty($connectionParams)) {
				$this->connectionParams = $connectionParams;
				
				// If DSN is provided directly, use it without modification
				// This allows for custom DSN formats or connection strings
				if (isset($connectionParams['DSN'])) {
					$this->dsn = $connectionParams['DSN'];
				}
			}
		}
		
		/**
		 * Set database connection parameters and generate DSN
		 * @param string $driver Database driver (mysql, pgsql, sqlite, etc.)
		 * @param string $host Database host (IP address or hostname)
		 * @param string $dbname Database name/schema
		 * @param string $user Database username
		 * @param string $password Database password
		 * @param int $port Database port (default: 3306 for MySQL)
		 * @param string $charset Character set (default: utf8mb4 for full Unicode support)
		 * @param array $options Additional PDO connection options/flags
		 * @return self Returns this instance for method chaining
		 */
		public function setDatabaseParams(
			string $driver,
			string $host,
			string $dbname,
			string $user,
			string $password,
			int    $port = 3306,
			string $charset = 'utf8mb4',
			array  $options = []
		): self {
			// Store all connection parameters in a structured format
			$this->connectionParams = [
				'driver'   => $driver,     // Database driver type
				'host'     => $host,       // Database server address
				'database' => $dbname,     // Database/schema name
				'username' => $user,       // Database username
				'password' => $password,   // Database password
				'port'     => $port,       // Database port number
				'encoding' => $charset,    // Character encoding
				'flags'    => $options     // Additional PDO options
			];
			
			// Generate CakePHP-compatible DSN string
			// Format: driver://username:password@host:port/database?encoding=charset
			$this->dsn = "{$driver}://{$user}:{$password}@{$host}:{$port}/{$dbname}?encoding={$charset}";
			$this->connectionParams['dsn'] = $this->dsn;
			return $this;
		}
		
		/**
		 * Set DSN string directly
		 * @param string $dsn Database connection string
		 * @return self Returns this instance for method chaining
		 */
		public function setDsn(string $dsn): self {
			$this->dsn = $dsn;
			$this->connectionParams['dsn'] = $dsn;
			return $this;
		}
		
		/**
		 * Get DSN string
		 * @return string The database connection DSN
		 */
		public function getDsn(): string {
			return $this->dsn;
		}
		
		/**
		 * Set all database connection parameters at once
		 * @param array $params Connection parameters array
		 *                      Must include either 'dsn' OR all of: driver, host, database, username, password
		 * @return self Returns this instance for method chaining
		 */
		public function setConnectionParams(array $params): self {
			$this->connectionParams = $params;
			
			// If a pre-built DSN is provided, use it directly
			if (isset($params['dsn'])) {
				$this->dsn = $params['dsn'];
				return $this;
			}
			
			// If individual parameters are provided, generate DSN automatically
			if (isset($params['driver'], $params['host'], $params['database'], $params['username'], $params['password'])) {
				$this->setDatabaseParams(
					$params['driver'],
					$params['host'],
					$params['database'],
					$params['username'],
					$params['password'],
					$params['port'] ?? 3306,        // Default MySQL port
					$params['encoding'] ?? 'utf8mb4', // Default to full Unicode support
					$params['flags'] ?? []           // Default to no additional flags
				);
			}
			
			return $this;
		}
		
		/**
		 * Get database connection parameters
		 * @return array Complete array of connection parameters
		 */
		public function getConnectionParams(): array {
			return $this->connectionParams;
		}
		
		/**
		 * Retrieves entity path
		 * @return string Primary entity path
		 */
		public function getEntityPath(): string {
			return $this->entityPaths['core'] ?? '';
		}
		
		/**
		 * Set path where entity classes can be found
		 * @param string $path Directory path containing entity classes
		 * @return self Returns this instance for method chaining
		 */
		public function setEntityPath(string $path): self {
			$this->entityPaths = ['core' => $path];
			return $this;
		}
		
		/**
		 * Adds an additional path where entity classes can be found.
		 * This allows for modular organization of entities across multiple directories
		 * or inclusion of third-party entity libraries.
		 * @param string $path Directory path containing entity classes
		 * @return self Returns this instance for method chaining
		 */
		public function addAdditionalEntityPath(string $path): self {
			$this->entityPaths[] = $path;
			return $this;
		}
		
		/**
		 * Returns all entity paths
		 * @return array Array of directory paths containing entity classes
		 */
		public function getEntityPaths(): array {
			return array_values($this->entityPaths);
		}
		
		/**
		 * Set the directory where proxy classes will be stored
		 *
		 * Proxy classes are dynamically generated by the ORM to provide lazy loading
		 * and change tracking functionality. The specified directory must be writable
		 * by the web server process.
		 *
		 * @param string|null $proxyDir Directory path for proxy classes (null to disable)
		 * @return self Returns this instance for method chaining
		 */
		public function setProxyDir(?string $proxyDir): self {
			$this->proxyDir = $proxyDir ?? '';
			return $this;
		}
		
		/**
		 * Get proxy directory
		 * @return string|null Proxy directory path or null if disabled
		 */
		public function getProxyDir(): ?string {
			return $this->proxyDir ?: null;
		}
		
		/**
		 * Get entity namespace
		 * @return string Base namespace for entity classes
		 */
		public function getEntityNameSpace(): string {
			return $this->entityNameSpace;
		}
		
		/**
		 * Set entity namespace
		 * @param string $entityNameSpace Base namespace for entity classes
		 * @return void
		 */
		public function setEntityNameSpace(string $entityNameSpace): void {
			$this->entityNameSpace = $entityNameSpace;
		}
		
		/**
		 * Get whether to use metadata cache
		 * @return bool True if metadata caching is enabled
		 */
		public function useMetadataCache(): bool {
			return $this->useMetadataCache;
		}
		
		/**
		 * Set whether to use metadata cache
		 * @param bool $useCache True to enable metadata caching, false to disable
		 * @return self Returns this instance for method chaining
		 */
		public function setUseMetadataCache(bool $useCache): self {
			$this->useMetadataCache = $useCache;
			return $this;
		}
		
		/**
		 * Returns the metadata cache directory
		 * @return string Directory path for metadata cache files
		 */
		public function getMetadataCachePath(): string {
			return $this->metadataCachePath;
		}
		
		/**
		 * Sets the metadata cache directory
		 * @param string $metadataCachePath Directory path for metadata cache files
		 * @return void
		 */
		public function setMetadataCachePath(string $metadataCachePath): void {
			$this->metadataCachePath = $metadataCachePath;
		}
		
		/**
		 * Returns the path of migrations
		 * @return string Directory path containing migration files
		 */
		public function getMigrationsPath(): string {
			return $this->migrationsPath;
		}
		
		/**
		 * Sets the path for migrations
		 * @param string $migrationsPath Directory path containing migration files
		 * @return void
		 */
		public function setMigrationsPath(string $migrationsPath): void {
			$this->migrationsPath = $migrationsPath;
		}
		
		/**
		 * Returns the standard window size for pagination
		 * @return int|null Default page size or null if not configured
		 */
		public function getDefaultWindowSize(): ?int {
			return $this->defaultWindowSize;
		}
		
		/**
		 * Sets the standard window size for pagination
		 * @param int|null $defaultWindowSize Default page size (null to disable)
		 * @return void
		 */
		public function setDefaultWindowSize(?int $defaultWindowSize): void {
			$this->defaultWindowSize = $defaultWindowSize;
		}
		
		/**
		 * ArrayAccess implementation - Check if offset exists
		 * @param mixed $offset The key to check for existence
		 * @return bool True if the key exists in connection parameters
		 */
		public function offsetExists(mixed $offset): bool {
			return isset($this->connectionParams[$offset]);
		}
		
		/**
		 * ArrayAccess implementation - Get value at offset
		 * @param mixed $offset The key to retrieve
		 * @return mixed The value at the specified key or null if not found
		 */
		public function offsetGet(mixed $offset): mixed {
			return $this->connectionParams[$offset] ?? null;
		}
		
		/**
		 * ArrayAccess implementation - Set value at offset
		 * @param mixed $offset The key to set
		 * @param mixed $value The value to set
		 * @return void
		 */
		public function offsetSet(mixed $offset, mixed $value): void {
			$this->connectionParams[$offset] = $value;
			
			// Update internal DSN if the legacy DB_DSN key is being set
			// This maintains backwards compatibility with older configuration formats
			if ($offset === 'DB_DSN') {
				$this->dsn = $value;
			}
		}
		
		/**
		 * ArrayAccess implementation - Unset offset
		 * @param mixed $offset The key to remove
		 * @return void
		 */
		public function offsetUnset(mixed $offset): void {
			unset($this->connectionParams[$offset]);
		}
	}