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
		 * Only reached when supportsRegexpLike() is false. For 'sqlsrv' that
		 * covers two cases: an engine older than SQL Server 2025, or a SQL Server
		 * 2025+ engine hosting a database whose compatibility level hasn't been
		 * raised to 170+. Either way there is no regex operator available, so
		 * this throws rather than returning a syntactically-valid-looking
		 * operator that isn't.
		 */
		public function getRegexpFallbackOperators(): array {
			return match ($this->adapter->getDatabaseType()) {
				'pgsql' => ['match' => '~', 'notMatch' => '!~'],
				'sqlsrv' => throw new \RuntimeException(
					'No regular expression support is available on this SQL Server ' .
					'connection. REGEXP_LIKE() requires SQL Server 2025 (compatibility ' .
					'level 170+); no fallback operator exists on earlier versions.'
				),
				
				// MySQL, MariaDB, SQLite all parse the REGEXP keyword the same way.
				// SQLite specifically requires a regexp() user function to be
				// registered on the connection or this will fail at query time with
				// "no such function: regexp" — that registration is outside what
				// this interface can control.
				default => ['match' => 'REGEXP', 'notMatch' => 'NOT REGEXP'],
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
		 * PostgreSQL's 'jsonb' binary type is preferred over 'json' because it
		 * supports GIN indexing and generally has better performance for reads.
		 * All other engines use 'json'.
		 */
		public function getNativeJsonType(): string {
			return match ($this->adapter->getDatabaseType()) {
				'pgsql' => 'jsonb',
				default => 'json',
			};
		}
		
		/**
		 * @inheritDoc
		 *
		 * JSON path extraction style depends on the engine and version:
		 * - PostgreSQL:       col #>> '{a,b}'          (all versions)
		 * - MariaDB >= 10.9:  JSON_VALUE(col, '$.a.b')
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
		 * Cast type maps per engine:
		 *
		 * MySQL / MariaDB
		 *   Integer arithmetic uses SIGNED (signed 64-bit) rather than INT because
		 *   CAST(x AS INT) is not valid in MySQL; SIGNED / UNSIGNED are the correct
		 *   integer target types for CAST().
		 *
		 * PostgreSQL
		 *   Uses standard ANSI type names. INTEGER and FLOAT are the idiomatic choices;
		 *   TEXT is preferred over VARCHAR (no length constraint) for string casts.
		 *
		 * SQLite
		 *   SQLite CAST() accepts a limited set of type affinities: INTEGER, REAL,
		 *   TEXT, NUMERIC, BLOB. There is no separate FLOAT or DOUBLE type.
		 */
		public function getSupportedCastTypes(): array {
			return match ($this->adapter->getDatabaseType()) {
				'pgsql' => [
					'int'     => 'INTEGER',
					'float'   => 'FLOAT',
					'string'  => 'TEXT',
					'decimal' => 'DECIMAL',
					'bool'    => 'BOOLEAN',
				],
				'sqlite' => [
					'int'     => 'INTEGER',
					'float'   => 'REAL',
					'string'  => 'TEXT',
					'decimal' => 'NUMERIC',
				],
				default => [
					'int'     => 'SIGNED',
					'float'   => 'DOUBLE',
					'string'  => 'CHAR',
					'decimal' => 'DECIMAL',
				],
			};
		}
		
		/**
		 * @inheritDoc
		 *
		 * Unix timestamp conversion function per engine:
		 * - MySQL / MariaDB: UNIX_TIMESTAMP(col)
		 * - PostgreSQL:      EXTRACT(EPOCH FROM col)::BIGINT
		 * - SQLite:          strftime('%s', col)
		 */
		public function getUnixTimestampFunction(): string {
			return match ($this->adapter->getDatabaseType()) {
				'pgsql' => 'EXTRACT(EPOCH FROM %s)::BIGINT',
				'sqlite' => "strftime('%%s', %s)",
				default => 'UNIX_TIMESTAMP(%s)',
			};
		}
		
		/**
		 * @inheritDoc
		 *
		 * Current time as Unix timestamp per engine:
		 * - MySQL / MariaDB: UNIX_TIMESTAMP()
		 * - PostgreSQL:      EXTRACT(EPOCH FROM NOW())::BIGINT
		 * - SQLite:          strftime('%s','now')
		 */
		public function getCurrentUnixTimestamp(): string {
			return match ($this->adapter->getDatabaseType()) {
				'pgsql' => 'EXTRACT(EPOCH FROM NOW())::BIGINT',
				'sqlite' => "strftime('%s','now')",
				default => 'UNIX_TIMESTAMP()',
			};
		}
		
		/**
		 * @inheritDoc
		 *
		 * Current date/time as a native datetime value per engine:
		 * - MySQL / MariaDB: NOW()
		 * - PostgreSQL:      NOW()
		 * - SQLite:          CURRENT_TIMESTAMP
		 * - SQL Server:      SYSDATETIME() — higher precision than GETDATE(),
		 *                    which reduces the chance of two concurrent writes
		 *                    producing an identical version timestamp.
		 */
		public function getCurrentDatetimeFunction(): string {
			return match ($this->adapter->getDatabaseType()) {
				'sqlite' => 'CURRENT_TIMESTAMP',
				'sqlsrv' => 'SYSDATETIME()',
				default => 'NOW()',
			};
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