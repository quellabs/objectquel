<?php
	
	/*
	 * ╔═══════════════════════════════════════════════════════════════════════════════════════╗
	 * ║                                                                                       ║
	 * ║   ██████╗ ██████╗      ██╗███████╗ ██████╗████████╗ ██████╗ ██╗   ██╗███████╗██╗      ║
	 * ║  ██╔═══██╗██╔══██╗     ██║██╔════╝██╔════╝╚══██╔══╝██╔═══██╗██║   ██║██╔════╝██║      ║
	 * ║  ██║   ██║██████╔╝     ██║█████╗  ██║        ██║   ██║   ██║██║   ██║█████╗  ██║      ║
	 * ║  ██║   ██║██╔══██╗██   ██║██╔══╝  ██║        ██║   ██║▄▄ ██║██║   ██║██╔══╝  ██║      ║
	 * ║  ╚██████╔╝██████╔╝╚█████╔╝███████╗╚██████╗   ██║   ╚██████╔╝╚██████╔╝███████╗███████╗ ║
	 * ║   ╚═════╝ ╚═════╝  ╚════╝ ╚══════╝ ╚═════╝   ╚═╝    ╚══▀▀═╝  ╚═════╝ ╚══════╝╚══════╝ ║
	 * ║                                                                                       ║
	 * ║  ObjectQuel - Powerful Object-Relational Mapping built on the Data Mapper pattern     ║
	 * ║                                                                                       ║
	 * ║  Clean separation between entities and persistence logic with an intuitive,           ║
	 * ║  object-oriented query language. Powered by CakePHP's robust database foundation.     ║
	 * ║                                                                                       ║
	 * ╚═══════════════════════════════════════════════════════════════════════════════════════╝
	 */
	
	namespace Quellabs\ObjectQuel;
	
	/**
	 * Configuration class for ObjectQuel ORM
	 */
	class Configuration {
		
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
	}