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
		 * Returns the set of QUEL cast type names supported by the connected engine,
		 * mapped to the SQL type token that should appear inside the CAST expression.
		 *
		 * The keys are the identifiers users write in QUEL (e.g. 'int', 'float',
		 * 'string', 'decimal'). The values are the exact SQL type tokens emitted
		 * into the generated SQL (e.g. 'SIGNED', 'DOUBLE', 'CHAR', 'DECIMAL').
		 *
		 * ObjectQuel validates cast types against this map at semantic-analysis time
		 * and rejects any cast whose key is absent, so only engine-supported casts
		 * can reach the SQL generator.
		 *
		 * @return array<string, string>  e.g. ['int' => 'SIGNED', 'float' => 'DOUBLE', ...]
		 */
		public function getSupportedCastTypes(): array;

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
	}