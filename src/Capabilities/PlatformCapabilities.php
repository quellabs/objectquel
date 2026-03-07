<?php
	
	namespace Quellabs\ObjectQuel\Capabilities;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	
	/**
	 * PlatformCapabilitiesInterface adapter backed by a DatabaseAdapter instance.
	 *
	 * Wraps a DatabaseAdapter and uses it to determine which SQL features are
	 * available at runtime. Construct this once (typically alongside your
	 * EntityManager) and pass it into QuelToSQL.
	 *
	 * Example:
	 *   $platform = new PlatformCapabilities($adapter);
	 *   $quelToSQL = new QuelToSQL($entityStore, $parameters, $platform);
	 */
	readonly class PlatformCapabilities implements PlatformCapabilitiesInterface {
		
		/**
		 * @var DatabaseAdapter
		 */
		private DatabaseAdapter $adapter;
		
		/**
		 * Constructor
		 * @param DatabaseAdapter $adapter
		 */
		public function __construct(DatabaseAdapter $adapter) {
			$this->adapter = $adapter;
		}
		
		/**
		 * @inheritDoc
		 *
		 * REGEXP_LIKE(col, pattern, flags) is available in MySQL 8.0.0 and later.
		 * MariaDB exposes REGEXP_LIKE() but does not accept the flags argument,
		 * so this returns false for MariaDB and all non-MySQL databases.
		 */
		public function supportsRegexpLike(): bool {
			if ($this->adapter->getDatabaseType() !== 'mysql') {
				return false;
			}
			
			return version_compare($this->adapter->getMysqlVersion(), '8.0.0', '>=');
		}
		
		/**
		 * @inheritDoc
		 *
		 * Performs feature detection by executing a probe query the first time it is
		 * called. Result is cached for the lifetime of this instance.
		 */
		public function supportsWindowFunctions(): bool {
			static $cache = null;
			
			if ($cache !== null) {
				return $cache;
			}
			
			// Portable probe: COUNT(...) OVER () over a single-row derived table.
			// If window functions aren't supported, execute() returns false.
			$probeSql = 'SELECT COUNT(1) OVER () AS __wf FROM (SELECT 1) t';
			$stmt = $this->adapter->execute($probeSql);
			
			if ($stmt === false) {
				return $cache = false;
			}
			
			$stmt->closeCursor();
			return $cache = true;
		}
	}