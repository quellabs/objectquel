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
	 * and inject it into QuelToSQLRetrieve. When no implementation is provided, ObjectQuel
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
		 * Returns whether the database supports native offset-based pagination.
		 * @return bool
		 */
		public function supportsOffsetPagination(): bool;
		
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
		 * Returns the JSON path extraction style used by the connected engine.
		 * @return JsonExtractionStyle
		 */
		public function getJsonExtractionStyle(): JsonExtractionStyle;

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
		 * Returns true if the database engine treats DDL as transactional —
		 * a CREATE/ALTER/DROP statement issued inside a BEGIN/COMMIT can be
		 * rolled back like any other write. MySQL/MariaDB DDL auto-commits
		 * per statement regardless of an open transaction, so this is false
		 * there; PostgreSQL, SQLite, and SQL Server all honor DDL rollback,
		 * so it's true for them.
		 *
		 * Exists so callers issuing more than one DDL statement for a single
		 * ObjectQuel statement (CreateTableExecutor, AlterTableExecutor) can
		 * wrap the whole sequence in a transaction where that's meaningful,
		 * rather than leaving a partial result behind on a mid-sequence
		 * failure (see objectquel-index-clause-design.md, decision 4).
		 *
		 * @return bool
		 */
		public function supportsTransactionalDDL(): bool;

		/**
		 * Returns true if the database engine allows a qualified column
		 * (`alias.col`) on the LEFT side of an UPDATE's SET assignment.
		 *
		 * PostgreSQL and SQLite both reject `SET alias.col = ...` as a syntax
		 * error — the SET target must always be a bare column name on those
		 * engines. MySQL/MariaDB and SQL Server accept either form.
		 *
		 * Shared by QuelToSQLReplace and VersionValueHandler so `replace` SQL
		 * and @Orm\Version bump SQL agree on when a SET target may be
		 * qualified, rather than each hardcoding its own engine list.
		 *
		 * @return bool
		 */
		public function supportsQualifiedSetTarget(): bool;

		/**
		 * Returns true if UPDATE accepts an alias after the target table (`UPDATE t AS a`).
		 * SQL Server declares the UPDATE alias in FROM instead; DELETE syntax is rendered separately.
		 * @return bool
		 */
		public function supportsAliasAfterDmlTarget(): bool;

		/**
		 * Returns true if SQL has TRUE/FALSE literals. SQL Server has none and
		 * uses 1/0 for BIT values.
		 * @return bool
		 */
		public function supportsBooleanLiterals(): bool;

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
