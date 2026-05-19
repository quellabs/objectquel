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
	}