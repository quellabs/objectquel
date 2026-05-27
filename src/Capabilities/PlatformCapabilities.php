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
		 * Checks whether the database supports native ENUM column types
		 * @return bool True if native ENUM types are supported (MySQL/MariaDB), false otherwise
		 */
		public function supportsNativeEnums(): bool {
			return in_array($this->adapter->getDatabaseType(), ['mysql', 'mariadb']);
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
				'postgres', 'postgresql' => FulltextIndexStyle::Tsvector,
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
				'postgres', 'postgresql' => 'jsonb',
				default => 'json',
			};
		}
		
		/**
		 * @inheritDoc
		 *
		 * JSON path extraction style depends on the engine and version:
		 * - PostgreSQL:        col #>> '{a,b}'          (all versions)
		 * - MariaDB >= 10.9:  JSON_VALUE(col, '$.a.b')
		 * - SQLite >= 3.38:   JSON_VALUE(col, '$.a.b')
		 * - All others:       JSON_UNQUOTE(JSON_EXTRACT(col, '$.a.b'))
		 */
		public function getJsonExtractionStyle(): JsonExtractionStyle {
			return match ($this->adapter->getDatabaseType()) {
				'postgres', 'postgresql' => JsonExtractionStyle::HashDoubleArrow,
				'mariadb' => version_compare($this->adapter->getServerVersion(), '10.9.0', '>=')
					? JsonExtractionStyle::JsonValue
					: JsonExtractionStyle::JsonUnquote,
				'sqlite' => version_compare($this->adapter->getServerVersion(), '3.38.0', '>=')
					? JsonExtractionStyle::JsonValue
					: JsonExtractionStyle::JsonUnquote,
				default => JsonExtractionStyle::JsonUnquote,
			};
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
				'postgres', 'postgresql' => [
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
	}