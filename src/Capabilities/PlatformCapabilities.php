<?php
	
	namespace Quellabs\ObjectQuel\Capabilities;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	
	/**
	 * PlatformCapabilitiesInterface adapter backed by a DatabaseAdapter instance.
	 *
	 * Wraps a DatabaseAdapter and uses it to determine which SQL features are
	 * available at runtime. Construct this once (typically alongside your
	 * EntityManager) and pass it into QuelToSQLRetrieve.
	 *
	 * This class only reports facts about the connected engine (feature flags,
	 * supported styles, and getDatabaseType() itself) — it never builds SQL text.
	 * Collaborators that render whole DDL/SQL fragments from those facts (e.g.
	 * DDLTypeMapper) take a PlatformCapabilitiesInterface and query it, rather
	 * than this class delegating out to them.
	 *
	 * Example:
	 *   $platform = new PlatformCapabilities($adapter);
	 *   $quelToSQL = new QuelToSQLRetrieve($entityStore, $parameters, $platform);
	 */
	class PlatformCapabilities implements PlatformCapabilitiesInterface {
		
		/**
		 * @var DatabaseAdapter
		 */
		private readonly DatabaseAdapter $adapter;
		
		/**
		 * Lazily-populated cache for supportsWindowFunctions()'s probe query result.
		 * Per-instance (unlike a `static` local, which would be shared across every
		 * PlatformCapabilities instance regardless of which connection it wraps).
		 * Null means "not yet probed".
		 * @var bool|null
		 */
		private ?bool $windowFunctionsCache = null;

		
		/**
		 * Constructor
		 * @param DatabaseAdapter $adapter
		 */
		public function __construct(DatabaseAdapter $adapter) {
			$this->adapter = $adapter;
		}
		
		/**
		 * Checks whether the database supports native ENUM column types
		 * @return bool True if native ENUM types are supported (MySQL/MariaDB), false otherwise
		 */
		public function supportsNativeEnums(): bool {
			return in_array($this->adapter->getDatabaseType(), ['mysql', 'mariadb']);
		}
		
		/**
		 * Checks whether the database engine has a real UNSIGNED integer modifier.
		 * @return bool
		 */
		public function supportsUnsignedIntegers(): bool {
			return in_array($this->adapter->getDatabaseType(), ['mysql', 'mariadb']);
		}
		
		/**
		 * @inheritDoc
		 *
		 * REGEXP_LIKE(col, pattern, flags) is supported by MySQL 8.0+ and SQL Server
		 * 2025+ when the database compatibility level is 170 or higher. Because SQL
		 * Server support depends on compatibility level rather than engine version,
		 * this checks DatabaseAdapter::getSqlServerCompatibilityLevel().
		 *
		 * MariaDB provides REGEXP_LIKE() but rejects the flags argument by design
		 * (MDEV-4425), relying on PCRE inline flags instead, so this returns false
		 * for MariaDB and all other engines.
		 */
		public function supportsRegexpLike(): bool {
			return match ($this->adapter->getDatabaseType()) {
				'mysql' => $this->supportsMysqlRegexpLike(),
				'sqlsrv' => $this->supportsSqlServerRegexpLike(),
				default => false,
			};
		}
		
		/**
		 * @inheritDoc
		 *
		 * Performs feature detection by executing a probe query the first time it
		 * is called. Result is cached per-instance for the lifetime of this object.
		 */
		public function supportsWindowFunctions(): bool {
			if ($this->windowFunctionsCache !== null) {
				return $this->windowFunctionsCache;
			}
			
			// Portable probe: COUNT(...) OVER () over a single-row derived table.
			// If window functions aren't supported, execute() returns false.
			$probeSql = 'SELECT COUNT(1) OVER () AS __wf FROM (SELECT 1) t';
			$stmt = $this->adapter->execute($probeSql);
			
			if ($stmt === null) {
				return $this->windowFunctionsCache = false;
			}
			
			$stmt->closeCursor();
			return $this->windowFunctionsCache = true;
		}

		/**
		 * Reports whether the connected database supports native offset pagination.
		 * SQL Server requires version 2012+ and compatibility level 110+.
		 * @return bool
		 */
		public function supportsOffsetPagination(): bool {
			if ($this->adapter->getDatabaseType() !== 'sqlsrv') {
				return true;
			}

			return version_compare($this->adapter->getServerVersion(), '11.0', '>=')
				&& ($this->adapter->getSqlServerCompatibilityLevel() ?? 0) >= 110;
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

		/**
		 * @inheritDoc
		 *
		 * Maps each supported database engine to its fulltext search style:
		 * - MySQL / MariaDB: FULLTEXT index with MATCH ... AGAINST
		 * - SQL Server:      FULLTEXT index with MATCH ... AGAINST
		 * - SQLite:          FTS5 virtual table with MATCH predicate
		 * - PostgreSQL:      tsvector column + GIN index + @@ to_tsquery()
		 */
		public function getFulltextIndexStyle(): FulltextIndexStyle {
			return match ($this->adapter->getDatabaseType()) {
				'pgsql' => FulltextIndexStyle::Tsvector,
				'sqlite' => FulltextIndexStyle::Fts5,
				default => FulltextIndexStyle::Fulltext,
			};
		}
		
		/**
		 * @inheritDoc
		 *
		 * JSON path extraction style depends on the engine and version:
		 * - PostgreSQL:       col #>> '{a,b}'          (all versions)
		 * - MariaDB >= 10.9:  JSON_VALUE(col, '$.a.b')
		 * - SQL Server:       JSON_VALUE(col, '$.a.b') (available since SQL Server
		 *                     2016; same function/path syntax as MariaDB's)
		 * - SQLite >= 3.38:   col ->> '$.a.b'          (SQLite has no JSON_VALUE())
		 * - All others:       JSON_UNQUOTE(JSON_EXTRACT(col, '$.a.b'))
		 */
		public function getJsonExtractionStyle(): JsonExtractionStyle {
			switch ($this->adapter->getDatabaseType()) {
				case 'pgsql':
					return JsonExtractionStyle::HashDoubleArrow;

				case 'mariadb':
					return $this->supportsMariaDbJsonValue()
						? JsonExtractionStyle::JsonValue
						: JsonExtractionStyle::JsonUnquote;

				case 'sqlsrv':
					return JsonExtractionStyle::JsonValue;

				case 'sqlite':
					return $this->supportsSqliteArrowOperator()
						? JsonExtractionStyle::ArrowOperator
						: JsonExtractionStyle::JsonUnquote;

				default:
					return JsonExtractionStyle::JsonUnquote;
			}
		}
		
		/**
		 * @inheritDoc
		 *
		 * SQLite is the only engine here without real, independently addressable
		 * named constraints — every other supported engine tracks one for real.
		 */
		public function supportsNamedForeignKeys(): bool {
			return $this->adapter->getDatabaseType() !== 'sqlite';
		}

		/**
		 * @inheritDoc
		 *
		 * DatabaseAdapter::getForeignKeys() has a real implementation for every
		 * engine getDatabaseType() can identify.
		 */
		public function supportsForeignKeyIntrospection(): bool {
			return in_array($this->adapter->getDatabaseType(), ['mysql', 'mariadb', 'sqlite', 'pgsql', 'sqlsrv']);
		}

		/**
		 * @inheritDoc
		 *
		 * MySQL/MariaDB are the only supported engines whose DDL auto-commits
		 * per statement; PostgreSQL, SQLite, and SQL Server all support
		 * transactional DDL.
		 */
		public function supportsTransactionalDDL(): bool {
			return !in_array($this->adapter->getDatabaseType(), ['mysql', 'mariadb'], true);
		}

		/**
		 * @inheritDoc
		 *
		 * PostgreSQL and SQLite reject a qualified column on the left side of
		 * SET; MySQL/MariaDB and SQL Server accept it.
		 */
		public function supportsQualifiedSetTarget(): bool {
			return !in_array($this->adapter->getDatabaseType(), ['pgsql', 'sqlite'], true);
		}

		/**
		 * @inheritDoc
		 */
		public function supportsAliasAfterDmlTarget(): bool {
			return $this->adapter->getDatabaseType() !== 'sqlsrv';
		}

		/**
		 * @inheritDoc
		 */
		public function supportsBooleanLiterals(): bool {
			return $this->adapter->getDatabaseType() !== 'sqlsrv';
		}

		/**
		 * @inheritDoc
		 */
		public function getDatabaseType(): string {
			return $this->adapter->getDatabaseType();
		}

		/**
		 * REGEXP_LIKE(col, pattern, flags) was added in MySQL 8.0.0.
		 * @return bool
		 */
		private function supportsMysqlRegexpLike(): bool {
			return version_compare($this->adapter->getServerVersion(), '8.0.0', '>=');
		}
		
		/**
		 * REGEXP_LIKE(col, pattern, flags) is available in SQL Server 2025 and later,
		 * provided the database compatibility level is 170 or higher.
		 * @return bool
		 */
		private function supportsSqlServerRegexpLike(): bool {
			if (!version_compare($this->adapter->getServerVersion(), '17.0', '>=')) {
				return false;
			}
			
			return ($this->adapter->getSqlServerCompatibilityLevel() ?? 0) >= 170;
		}
		
		/**
		 * JSON_VALUE() was added in MariaDB 10.9.0.
		 * @return bool
		 */
		private function supportsMariaDbJsonValue(): bool {
			return version_compare($this->adapter->getServerVersion(), '10.9.0', '>=');
		}
		
		/**
		 * SQLite added the -> and ->> JSON operators in 3.38.0. SQLite has no
		 * JSON_VALUE() function at any version; ->> is the closest equivalent —
		 * it unwraps the result to a plain SQL scalar the same way JSON_VALUE()
		 * does on MariaDB/SQL Server, just with different syntax.
		 * @return bool
		 */
		private function supportsSqliteArrowOperator(): bool {
			return version_compare($this->adapter->getServerVersion(), '3.38.0', '>=');
		}
	}
