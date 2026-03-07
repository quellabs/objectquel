<?php
	
	namespace Quellabs\ObjectQuel\Capabilities;
	
	use Cake\Database\Connection;
	use Cake\Database\Driver\Mysql;
	
	/**
	 * PlatformCapabilitiesInterface adapter for a CakePHP database connection.
	 *
	 * Wraps a CakePHP Connection and inspects its driver to determine which
	 * SQL features are available at runtime. Construct this once (typically
	 * alongside your EntityManager) and pass it into QuelToSQL.
	 *
	 * Example:
	 *   $platform = new PlatformCapabilities($connection);
	 *   $quelToSQL = new QuelToSQL($entityStore, $parameters, $platform);
	 */
	readonly class PlatformCapabilities implements PlatformCapabilitiesInterface {
		
		/**
		 * @var Connection CakePHP Database connection
		 */
		private Connection $connection;
		
		/**
		 * Constructor
		 * @param Connection $connection
		 */
		public function __construct(Connection $connection) {
			$this->connection = $connection;
		}
		
		/**
		 * @inheritDoc
		 *
		 * REGEXP_LIKE(col, pattern, flags) is available in MySQL 8.0.0 and later.
		 * MariaDB exposes a REGEXP_LIKE() function but does not accept the flags
		 * argument, so we conservatively restrict this to MySQL only.
		 */
		public function supportsRegexpLike(): bool {
			// Fetch CakePHP driver
			$driver = $this->connection->getDriver();
			
			// Bail if this is not MySQL
			if (!$driver instanceof Mysql) {
				return false;
			}
			
			// Fetch version
			$version = $driver->version();
			
			// MariaDB identifies itself through the Mysql driver but does not support
			// the flags argument in REGEXP_LIKE(). Detect it via the version string.
			if (stripos($version, 'mariadb') !== false) {
				return false;
			}
			
			// getVersion() returns a string like "8.0.32" or "5.7.41"
			return version_compare($driver->version(), '8.0.0', '>=');
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
			// If window functions aren't supported, this will raise a syntax error.
			$probeSql = 'SELECT COUNT(1) OVER () AS __wf FROM (SELECT 1) t';
			
			try {
				$stmt = $this->connection->execute($probeSql);
				$stmt->closeCursor();
				return $cache = true;
			} catch (\Throwable) {
				return $cache = false;
			}
		}
	}