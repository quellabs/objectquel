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
			
			return version_compare($this->adapter->getServerVersion(), '8.0.0', '>=');
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
			
			if ($stmt === null) {
				return $cache = false;
			}
			
			$stmt->closeCursor();
			return $cache = true;
		}
		
		/**
		 * @inheritDoc
		 *
		 * Invisible index support was introduced in:
		 * - MySQL    8.0.0   (https://dev.mysql.com/doc/refman/8.0/en/invisible-indexes.html)
		 * - MariaDB  10.6.0  (https://mariadb.com/kb/en/invisible-indexes/)
		 *
		 * Other engines (PostgreSQL, SQLite, SQL Server) do not support this feature.
		 */
		public function supportsIndexHiding(): bool {
			// Engines that support invisible indexes, mapped to the minimum required version.
			// Any engine absent from this map does not support the feature at all.
			$minimumVersions = [
				'mysql'   => '8.0.0',
				'mariadb' => '10.6.0',
			];
			
			$dbType = $this->adapter->getDatabaseType();
			
			// Bail out early for unsupported engines (PostgreSQL, SQLite, SQL Server, etc.)
			if (!array_key_exists($dbType, $minimumVersions)) {
				return false;
			}
			
			// getServerVersion() returns a normalized version string — MariaDB's MySQL
			// compatibility prefix ("5.5.5-") is already stripped, so version_compare()
			// is safe to use directly for both engines.
			return version_compare($this->adapter->getServerVersion(), $minimumVersions[$dbType], '>=');
		}
	}