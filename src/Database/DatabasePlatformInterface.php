<?php
	
	namespace Quellabs\ObjectQuel\Database;
	
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
	 * falls back to NullDatabasePlatform, which assumes the most conservative
	 * (widest-compatible) behaviour.
	 */
	interface DatabasePlatformInterface {
		
		/**
		 * Returns true if the database engine supports REGEXP_LIKE(col, pattern, flags).
		 *
		 * When true, ObjectQuel will emit REGEXP_LIKE() for regex patterns that carry
		 * flags (e.g. /pattern/i), enabling features like case-insensitive matching
		 * that the plain REGEXP operator cannot express.
		 *
		 * When false, ObjectQuel falls back to col REGEXP "pattern" and flags are
		 * silently ignored — behaviour is then determined by the column's collation.
		 *
		 * MySQL: supported from 8.0.0 onward.
		 * MariaDB: not supported (REGEXP_LIKE does not accept a flags argument).
		 *
		 * @return bool
		 */
		public function supportsRegexpLike(): bool;
		
		/**
		 * Returns true if the database engine supports SQL window functions (OVER clause).
		 *
		 * When true, ObjectQuel's aggregate optimizer may rewrite eligible aggregates
		 * as window functions (e.g. COUNT(*) OVER()) for better performance.
		 * When false, the optimizer falls back to correlated subqueries.
		 *
		 * MySQL: supported from 8.0.0 onward.
		 * MariaDB: supported from 10.2 onward.
		 *
		 * @return bool
		 */
		public function supportsWindowFunctions(): bool;
	}