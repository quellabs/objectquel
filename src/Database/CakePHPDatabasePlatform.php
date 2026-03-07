<?php
	
	namespace Quellabs\ObjectQuel\Database;
	
	use Cake\Database\Connection;
	use Cake\Database\Driver\Mysql;
	
	/**
	 * DatabasePlatformInterface adapter for a CakePHP database connection.
	 *
	 * Wraps a CakePHP Connection and inspects its driver to determine which
	 * SQL features are available at runtime. Construct this once (typically
	 * alongside your EntityManager) and pass it into QuelToSQL.
	 *
	 * Example:
	 *   $platform = new CakePHPDatabasePlatform($connection);
	 *   $quelToSQL = new QuelToSQL($entityStore, $parameters, $platform);
	 */
	readonly class CakePHPDatabasePlatform implements DatabasePlatformInterface {
		
		public function __construct(private Connection $connection) {}
		
		/**
		 * @inheritDoc
		 *
		 * REGEXP_LIKE(col, pattern, flags) is available in MySQL 8.0.0 and later.
		 * MariaDB exposes a REGEXP_LIKE() function but does not accept the flags
		 * argument, so we conservatively restrict this to MySQL only.
		 */
		public function supportsRegexpLike(): bool {
			$driver = $this->connection->getDriver();
			
			if (!$driver instanceof Mysql) {
				return false;
			}
			
			// getVersion() returns a string like "8.0.32" or "5.7.41"
			return version_compare($driver->version(), '8.0.0', '>=');
		}
	}