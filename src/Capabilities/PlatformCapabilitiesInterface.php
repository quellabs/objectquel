<?php
	
	namespace Quellabs\ObjectQuel\Capabilities;
	
	/**
	 * Abstracts database-engine capabilities that affect SQL generation.
	 *
	 * ObjectQuel's SQL output sometimes depends on what the underlying engine
	 * supports — for example, REGEXP_LIKE() with flags (MySQL 8.0+) versus the
	 * plain REGEXP operator (all versions). Rather than coupling the SQL visitor
	 * layer directly to CakePHP's driver classes, this interface lets callers
	 * describe the platform's capabilities without introducing a hard dependency
	 * on any particular database library.
	 *
	 * Implement this interface once per integration point (e.g. a CakePHP adapter)
	 * and inject it into QuelToSQL. When no implementation is provided, ObjectQuel
	 * falls back to NullPlatformCapabilities, which assumes the most conservative
	 * (widest-compatible) behavior.
	 */
	interface PlatformCapabilitiesInterface {
		
		/**
		 * Checks whether the database supports native ENUM column types
		 * @return bool True if native ENUM types are supported (MySQL/MariaDB), false otherwise
		 */
		public function supportsNativeEnums(): bool;
		
		/**
		 * Checks whether the database engine has a real UNSIGNED integer modifier.
		 *
		 * Only MySQL and MariaDB support this. SQLite, PostgreSQL, and SQL Server
		 * have no such concept — integers are always signed. This matters beyond
		 * SQL generation: schema introspection on these engines cannot report
		 * "unsigned" back from an existing column (there is nothing to report),
		 * so 'unsigned' must be excluded from schema comparisons entirely on
		 * engines where this returns false, or diffing will never converge.
		 *
		 * @return bool
		 */
		public function supportsUnsignedIntegers(): bool;
		
		/**
		 * Returns true if the database engine supports REGEXP_LIKE(col, pattern, flags).
		 * @return bool
		 */
		public function supportsRegexpLike(): bool;
		
		/**
		 * Returns true if the database engine supports SQL window functions (OVER clause).
		 * @return bool
		 */
		public function supportsWindowFunctions(): bool;
		
		/**
		 * Returns true if the database engine supports invisible (hidden) indexes.
		 * @return bool
		 */
		public function supportsIndexHiding(): bool;

		/**
		 * Returns the ALTER INDEX keyword pair used to hide an index from the
		 * query optimizer and restore it to visibility. Only meaningful when
		 * supportsIndexHiding() is true.
		 *
		 * Examples by engine:
		 *   MySQL   → ['hidden' => 'INVISIBLE',  'visible' => 'VISIBLE']
		 *   MariaDB → ['hidden' => 'IGNORED',    'visible' => 'NOT IGNORED']
		 *
		 * @return array{hidden: string, visible: string}
		 */
		public function getIndexVisibilityKeywords(): array;
		
		/**
		 * Returns true if the database engine supports ORDER BY FIELD(col, v1, v2, ...).
		 *
		 * Only MySQL and MariaDB implement FIELD(). Every other target engine
		 * (PostgreSQL, SQLite, SQL Server) has no equivalent function, so callers
		 * must fall back to a portable CASE expression when this returns false.
		 *
		 * @return bool
		 */
		public function supportsFieldFunction(): bool;
		
		/**
		 * Returns the fulltext search style supported by the current database engine.
		 *
		 * The returned value determines how ObjectQuel generates fulltext index DDL
		 * and fulltext search predicates:
		 *
		 * - FulltextIndexStyle::Fulltext  → FULLTEXT INDEX + MATCH(col) AGAINST('term')
		 *                                   (MySQL, MariaDB, SQL Server)
		 *
		 * - FulltextIndexStyle::Fts5      → FTS5 virtual table + MATCH predicate
		 *                                   (SQLite)
		 *
		 * - FulltextIndexStyle::Tsvector  → tsvector column + GIN index + @@ to_tsquery()
		 *                                   (PostgreSQL)
		 *
		 * @return FulltextIndexStyle
		 */
		public function getFulltextIndexStyle(): FulltextIndexStyle;
		
		/**
		 * Returns the native JSON column type name for the current database engine.
		 *
		 * ObjectQuel uses 'json' as the canonical ORM type. This method maps it to
		 * the correct DDL type for the connected engine:
		 *   - MySQL / MariaDB / SQLite → 'json'
		 *   - PostgreSQL               → 'jsonb'  (binary JSON; preferred over 'json'
		 *                                           because it supports GIN indexing)
		 *
		 * @return string The DDL type string to use in migrations ('json' or 'jsonb').
		 */
		public function getNativeJsonType(): string;
		
		/**
		 * Returns the JSON path extraction style used by the connected engine.
		 * @return JsonExtractionStyle
		 */
		public function getJsonExtractionStyle(): JsonExtractionStyle;

		/**
		 * Returns a SQL expression that converts a datetime column or value to a
		 * Unix timestamp (integer seconds since 1970-01-01 00:00:00 UTC).
		 *
		 * The placeholder %s must appear exactly once and will be replaced with the
		 * already-generated SQL for the inner expression.
		 *
		 * Examples by engine:
		 *   MySQL/MariaDB  → 'UNIX_TIMESTAMP(%s)'
		 *   PostgreSQL     → 'EXTRACT(EPOCH FROM %s)::BIGINT'
		 *   SQLite         → "strftime('%%s', %s)"
		 *
		 * @return string  A sprintf-compatible template with one %s placeholder.
		 */
		public function getUnixTimestampFunction(): string;

		/**
		 * Returns the SQL expression that yields the current time as a Unix
		 * timestamp (integer seconds since the epoch).
		 *
		 * Examples by engine:
		 *   MySQL/MariaDB  → 'UNIX_TIMESTAMP()'
		 *   PostgreSQL     → 'EXTRACT(EPOCH FROM NOW())::BIGINT'
		 *   SQLite         → "strftime('%s','now')"
		 *
		 * @return string  A complete SQL expression, no placeholders.
		 */
		public function getCurrentUnixTimestamp(): string;
		
		/**
		 * Returns the SQL expression that yields the current date and time as a
		 * native datetime/timestamp value (not a Unix timestamp).
		 *
		 * Used when writing a value into a datetime/timestamp-typed column — e.g.
		 * an @Orm\Version column on insert/update — where the column's native type
		 * is required rather than an integer epoch value.
		 *
		 * Examples by engine:
		 *   MySQL/MariaDB  → 'NOW()'
		 *   PostgreSQL     → 'NOW()'
		 *   SQLite         → "datetime('now')"
		 *   SQL Server     → 'SYSDATETIME()'
		 *
		 * @return string  A complete SQL expression, no placeholders.
		 */
		public function getCurrentDatetimeFunction(): string;
		
		/**
		 * Returns the SQL infix operator(s) used for a regular expression match
		 * on engines where REGEXP_LIKE() is not available (see supportsRegexpLike()).
		 * Callers only reach this when supportsRegexpLike() is false, so flags
		 * are never representable here and are dropped by design — case
		 * sensitivity then depends on column collation rather than an explicit flag.
		 *
		 * Returned as ['match' => ..., 'notMatch' => ...] because some engines use
		 * an unrelated token pair rather than a NOT-prefixed form of the same
		 * operator (e.g. PostgreSQL's '~' vs '!~', not 'NOT ~').
		 *
		 * Examples by engine:
		 *   MySQL/MariaDB  → ['match' => 'REGEXP',  'notMatch' => 'NOT REGEXP']
		 *   PostgreSQL     → ['match' => '~',       'notMatch' => '!~']
		 *   SQLite         → ['match' => 'REGEXP',  'notMatch' => 'NOT REGEXP']
		 *                     (same keyword as MySQL; SQLite parses REGEXP natively
		 *                     but it errors with "no such function: regexp" unless
		 *                     the connection has registered a regexp() user
		 *                     function — that registration is an application/driver
		 *                     concern this interface cannot detect or guarantee)
		 *
		 * SQL Server has no equivalent at all below compatibility level 170
		 * (SQL Server 2025) — there is no plain regex operator at any compatibility
		 * level. Implementations for SQL Server should make supportsRegexpLike()
		 * return true once compatibility level 170+ is confirmed (covering both the
		 * flagged and flag-less cases via REGEXP_LIKE(col, pattern[, flags])), and
		 * may throw from this method for older compatibility levels, since reaching
		 * it there means no regex support exists on the connection at all.
		 *
		 * @return array{match: string, notMatch: string}
		 */
		public function getRegexpFallbackOperators(): array;

		/**
		 * Returns true if the database engine has real, independently addressable
		 * named foreign key constraints, not just inert text embedded at creation
		 * time. True for MySQL, MariaDB, PostgreSQL, and SQL Server; false for
		 * SQLite, which parses a `CONSTRAINT name` clause but never reports or
		 * lets you drop by that name.
		 *
		 * Callers must omit the constraint name from both addForeignKey() and
		 * dropForeignKey() when this returns false.
		 *
		 * @return bool
		 */
		public function supportsNamedForeignKeys(): bool;

		/**
		 * Returns true if DatabaseAdapter::getForeignKeys() can actually
		 * introspect real foreign key constraints for this engine. True for
		 * every engine getDatabaseType() can identify today.
		 *
		 * Exists so callers that diff against a live schema
		 * (ForeignKeyComparator, IndexComparator) can tell "no foreign keys
		 * exist" apart from "this engine isn't introspectable" — both return an
		 * empty array, but treating the latter as the former would make every
		 * entity-declared foreign key look newly added on every run.
		 *
		 * @return bool
		 */
		public function supportsForeignKeyIntrospection(): bool;

		/**
		 * Returns the connected database engine's type identifier.
		 *
		 * This is the most basic fact PlatformCapabilities reports — every other
		 * method here is, internally, a decision made from this same value. It's
		 * exposed directly so collaborators that build engine-specific SQL text
		 * from scratch (e.g. DDLTypeMapper's temporary-table DDL) can branch on
		 * the engine without PlatformCapabilities having to grow a bespoke
		 * reporting method for every syntax difference between engines.
		 *
		 * @return string One of 'mysql', 'mariadb', 'pgsql', 'sqlite', 'sqlsrv'
		 */
		public function getDatabaseType(): string;
	}