<?php
	
	/**
	 * ObjectQuel - A Sophisticated Object-Relational Mapping (ORM) System
	 *
	 * ObjectQuel is an ORM that brings a fresh approach to database interaction,
	 * featuring a unique query language, a streamlined architecture, and powerful
	 * entity relationship management. It implements the Data Mapper pattern for
	 * clear separation between domain models and underlying database structures.
	 *
	 * @author      Floris van den Berg
	 * @copyright   Copyright (c) 2025 ObjectQuel
	 * @license     MIT
	 * @version     1.0.0
	 * @package     Quellabs\ObjectQuel
	 */

	namespace Quellabs\ObjectQuel;
	
	/**
	 * Configuration class for ObjectQuel ORM
	 * Holds database connection details and entity mapping information
	 */
	class Configuration implements \ArrayAccess {
		
		/**
		 * @var array Database connection parameters
		 */
		private array $connectionParams = [];
		
		/**
		 * @var string The DSN string for database connection
		 */
		private string $dsn = '';
		
		/**
		 * @var string Directory where proxy classes will be stored
		 */
		private string $proxyDir = '';
		
		/**
		 * @var array Paths to entity classes
		 */
		private array $entityPaths = [];
		
		/**
		 * @var string Namespace for entities
		 */
		private string $entityNameSpace = 'Quellabs\\ObjectQuel\\Entity';
		
		/**
		 * @var bool Whether to use metadata cache
		 */
		private bool $useMetadataCache = true;
		
		/**
		 * @var string Annotation cache directory
		 */
		private string $metadataCachePath = '';
		
		/**
		 * @var string Migration path
		 */
		private string $migrationsPath = '';
		
		/**
		 * @var int|null Window size to use for pagination, or null if none
		 */
		private ?int $defaultWindowSize = null;
		
		/**
		 * Constructor - optionally initialize with connection parameters
		 * @param array $connectionParams Database connection parameters
		 */
		public function __construct(array $connectionParams = []) {
			if (!empty($connectionParams)) {
				$this->connectionParams = $connectionParams;
				
				// If DB_DSN is provided, use it directly
				if (isset($connectionParams['DSN'])) {
					$this->dsn = $connectionParams['DSN'];
				}
			}
		}
		
		/**
		 * Set database connection parameters and generate DSN
		 * @param string $driver Database driver (mysql, pgsql, etc.)
		 * @param string $host Database host
		 * @param string $dbname Database name
		 * @param string $user Database user
		 * @param string $password Database password
		 * @param int $port Database port
		 * @param string $charset Character set (default: utf8mb4)
		 * @param array $options Additional connection options
		 * @return self
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
			$this->connectionParams = [
				'driver'   => $driver,
				'host'     => $host,
				'database' => $dbname,
				'username' => $user,
				'password' => $password,
				'port'     => $port,
				'encoding' => $charset,
				'flags'    => $options
			];
			
			// Generate DSN string for CakePHP
			$this->dsn = "{$driver}://{$user}:{$password}@{$host}:{$port}/{$dbname}?encoding={$charset}";
			$this->connectionParams['dsn'] = $this->dsn;
			
			return $this;
		}
		
		/**
		 * Set DSN string directly
		 * @param string $dsn Database connection string
		 * @return self
		 */
		public function setDsn(string $dsn): self {
			$this->dsn = $dsn;
			$this->connectionParams['dsn'] = $dsn;
			return $this;
		}
		
		/**
		 * Get DSN string
		 * @return string
		 */
		public function getDsn(): string {
			return $this->dsn;
		}
		
		/**
		 * Set all database connection parameters at once
		 * @param array $params Connection parameters
		 * @return self
		 */
		public function setConnectionParams(array $params): self {
			$this->connectionParams = $params;
			
			// If DB_DSN is provided, extract it
			if (isset($params['dsn'])) {
				$this->dsn = $params['dsn'];
				return $this;
			}
			
			if (isset($params['driver'], $params['host'], $params['database'], $params['username'], $params['password'])) {
				// Generate DSN from individual parameters
				$this->setDatabaseParams(
					$params['driver'],
					$params['host'],
					$params['database'],
					$params['username'],
					$params['password'],
					$params['port'] ?? 3306,
					$params['encoding'] ?? 'utf8mb4',
					$params['flags'] ?? []
				);
			}
			
			return $this;
		}
		
		/**
		 * Get database connection parameters
		 * @return array
		 */
		public function getConnectionParams(): array {
			return $this->connectionParams;
		}
		
		/**
		 * Sets paths where entity classes can be found
		 * @param array $paths
		 * @return self
		 */
		public function setEntityPaths(array $paths): self {
			$this->entityPaths = $paths;
			return $this;
		}
		
		/**
		 * Get the configured entity paths
		 * @return array
		 */
		public function getEntityPaths(): array {
			return $this->entityPaths;
		}
		
		/**
		 * Determines the appropriate entity path for creating new entities.
		 * This method prioritizes the 'user' entity path if available, otherwise
		 * returns the first available entity path from the collection.
		 * @return string|null The entity path to use for creation, or null if no paths are available
		 */
		public function getEntityCreationPath(): ?string {
			// Retrieve all available entity paths
			$entityPaths = $this->getEntityPaths();
			
			// Return null if no entity paths are configured
			if (empty($entityPaths)) {
				return null;
			}
			
			// Prefer the 'user' entity path if it exists
			if (isset($entityPaths['writeable'])) {
				return $entityPaths['writeable'];
			}
			
			// Fallback to the first available entity path
			return $entityPaths[array_key_first($entityPaths)];
		}
		
		/**
		 * Set directory where proxy classes will be stored
		 * @param string|null $proxyDir
		 * @return self
		 */
		public function setProxyDir(?string $proxyDir): self {
			$this->proxyDir = $proxyDir;
			return $this;
		}
		
		/**
		 * Get proxy directory
		 * @return string|null
		 */
		public function getProxyDir(): ?string {
			return $this->proxyDir;
		}
		
		public function getEntityNameSpace(): string {
			return $this->entityNameSpace;
		}
		
		public function setEntityNameSpace(string $entityNameSpace): void {
			$this->entityNameSpace = $entityNameSpace;
		}
		
		/**
		 * Get whether to use metadata cache
		 * @return bool
		 */
		public function useMetadataCache(): bool {
			return $this->useMetadataCache;
		}
		
		/**
		 * Set whether to use metadata cache
		 * @param bool $useCache
		 * @return self
		 */
		public function setUseMetadataCache(bool $useCache): self {
			$this->useMetadataCache = $useCache;
			return $this;
		}
		
		/**
		 * Returns the metadata cache directory
		 * @return string
		 */
		public function getMetadataCachePath(): string {
			return $this->metadataCachePath;
		}
		
		/**
		 * Sets the metadata cache directory
		 * @param string $annotationCacheDir
		 * @return void
		 */
		public function setMetadataCachePath(string $annotationCacheDir): void {
			$this->metadataCachePath = $annotationCacheDir;
		}
		
		/**
		 * Returns the path of migrations
		 * @return string
		 */
		public function getMigrationsPath(): string {
			return $this->migrationsPath;
		}
		
		/**
		 * Sets the path for migrations
		 * @param string $migrationPath
		 * @return void
		 */
		public function setMigrationsPath(string $migrationPath): void {
			$this->migrationsPath = $migrationPath;
		}
		
		/**
		 * Returns the standard window size for pagination
		 * @return int|null
		 */
		public function getDefaultWindowSize(): ?int {
			return $this->defaultWindowSize;
		}
		
		/**
		 * Sets the standard window size for pagination
		 * @param int|null $standardWindowSize
		 * @return void
		 */
		public function setDefaultWindowSize(?int $standardWindowSize): void {
			$this->defaultWindowSize = $standardWindowSize;
		}
		
		/**
		 * ArrayAccess implementation - Check if offset exists
		 * @param mixed $offset
		 * @return bool
		 */
		public function offsetExists(mixed $offset): bool {
			return isset($this->connectionParams[$offset]);
		}
		
		/**
		 * ArrayAccess implementation - Get value at offset
		 * @param mixed $offset
		 * @return mixed
		 */
		public function offsetGet(mixed $offset): mixed {
			return $this->connectionParams[$offset] ?? null;
		}
		
		/**
		 * ArrayAccess implementation - Set value at offset
		 * @param mixed $offset
		 * @param mixed $value
		 * @return void
		 */
		public function offsetSet(mixed $offset, mixed $value): void {
			$this->connectionParams[$offset] = $value;
			
			// Update DSN if DB_DSN is set
			if ($offset === 'DB_DSN') {
				$this->dsn = $value;
			}
		}
		
		/**
		 * ArrayAccess implementation - Unset offset
		 * @param mixed $offset
		 * @return void
		 */
		public function offsetUnset(mixed $offset): void {
			unset($this->connectionParams[$offset]);
		}
	}