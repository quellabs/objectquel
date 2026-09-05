<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateIndex;

	/**
	 * Compiles an AstCreateIndex statement to dialect-correct DDL. Sibling to
	 * QuelToSQLCreate/QuelToSQLDestroy — each QUEL statement kind gets its
	 * own compiler here.
	 *
	 * The plain/unique case is a single, near-uniform
	 * `CREATE [UNIQUE] INDEX <name> ON <table> (<cols>)` statement across
	 * every dialect (see objectquel-create-index-plan.md's "Compile"
	 * section) — only identifier quoting differs, so no DDLTypeMapper is
	 * needed there.
	 *
	 * `index fulltext on ...` is a materially different case per dialect, not
	 * "the same statement with one word changed" — each of the four target
	 * engines models full-text search as a genuinely different kind of
	 * object:
	 * - mysql/mariadb: `CREATE FULLTEXT INDEX` — a real index, one statement.
	 * - pgsql: no native "fulltext index" statement at all — a GIN
	 *   expression index over `to_tsvector('english', ...)` is Postgres's
	 *   own idiom for this. 'english' is a fixed default text-search
	 *   configuration for v1 (not user-selectable — see the plan doc).
	 *   Columns are coalesced to '' before concatenation so a single NULL
	 *   column doesn't null out the whole indexed document.
	 * - sqlsrv: needs a full-text catalog to exist first (bootstrapped here,
	 *   idempotently, into a single shared catalog — see
	 *   FULLTEXT_CATALOG_NAME) and a `KEY INDEX` naming an existing
	 *   unique/primary index on the table, resolved by the caller (see
	 *   CreateIndexExecutor) since it requires schema introspection this
	 *   compiler has no connection to do itself. T-SQL fulltext indexes are
	 *   also unnamed (one per table) — $statement->getIndexName() has no
	 *   equivalent in the `CREATE FULLTEXT INDEX` syntax itself, so it's
	 *   instead recorded as a table-level extended property (see
	 *   tagFulltextIndexName()) — SQL Server's standard, inspectable
	 *   object-annotation mechanism — so a later `destroy Name on Table`
	 *   can verify $name actually refers to this table's fulltext index
	 *   rather than accepting any name typed against a table that merely
	 *   has one (see objectquel-destroy-index-plan.md's "Fulltext index
	 *   destroy on sqlsrv/sqlite" section, and QuelToSQLDestroyIndex,
	 *   which reads this tag back via
	 *   DatabaseAdapter::getSqlServerExtendedProperty()).
	 * - sqlite: has no fulltext index concept on an ordinary table at all —
	 *   an FTS5 *virtual table* is created instead (using the index name as
	 *   its physical name), kept in sync with the base table via three
	 *   triggers (SQLite's own documented pattern for external-content FTS5
	 *   tables — content is never auto-copied on writes without them).
	 *   Needs the base table's primary key column (content_rowid), resolved
	 *   by the caller for the same reason as sqlsrv's KEY INDEX.
	 *
	 * Every convertToSQL() call returns a list of one or more statements to
	 * run in order (mirrors QuelToSQLDestroy) — sqlsrv/sqlite's fulltext
	 * paths need more than one.
	 */
	class QuelToSQLCreateIndex {

		private const string SQL_SERVER_FULLTEXT_CATALOG = 'quel_fulltext_catalog';

		/**
		 * Extended-property name a sqlsrv fulltext index's QUEL name is
		 * tagged under (see tagFulltextIndexName()). Public: read back by
		 * QuelToSQLDestroyIndex/DestroyIndexExecutor via
		 * DatabaseAdapter::getSqlServerExtendedProperty() — both sides must
		 * agree on the exact same property name.
		 */
		public const string SQL_SERVER_FULLTEXT_INDEX_NAME_PROPERTY = 'quel_fulltext_index_name';

		private SqlIdentifierQuoter $identifierQuoter;

		/**
		 * QuelToSQLCreateIndex constructor
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(private readonly PlatformCapabilitiesInterface $platform) {
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
		}

		/**
		 * Compiles an `index [unique|fulltext] on Table is index_name (...)`
		 * statement to SQL.
		 * @param AstCreateIndex $statement
		 * @param string|null $primaryKeyColumn Base table's primary key column — required only for sqlite fulltext
		 * @param string|null $sqlServerKeyIndexName An existing unique/primary index on the table — required only for sqlsrv fulltext
		 * @return list<string>
		 */
		public function convertToSQL(AstCreateIndex $statement, ?string $primaryKeyColumn = null, ?string $sqlServerKeyIndexName = null): array {
			if ($statement->getType() !== 'fulltext') {
				return [$this->compilePlainOrUnique($statement)];
			}

			return match ($this->platform->getDatabaseType()) {
				'pgsql' => [$this->compilePostgresFulltext($statement)],
				'sqlite' => $this->compileSqliteFulltext($statement, $primaryKeyColumn),
				'sqlsrv' => $this->compileSqlServerFulltext($statement, $sqlServerKeyIndexName),
				default => [$this->compileMysqlFulltext($statement)],
			};
		}

		/**
		 * The plain/unique case: `CREATE [UNIQUE] INDEX <name> ON <table> (<cols>)`.
		 */
		private function compilePlainOrUnique(AstCreateIndex $statement): string {
			$keyword = $statement->isUnique() ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';

			return sprintf(
				'%s %s ON %s (%s)',
				$keyword,
				$this->identifierQuoter->quoteIdentifier($statement->getIndexName()),
				$this->identifierQuoter->quoteIdentifier($statement->getTableName()),
				$this->quotedColumnList($statement->getColumns())
			);
		}

		/**
		 * mysql/mariadb: a real, named index — same shape as the plain case,
		 * FULLTEXT instead of [UNIQUE].
		 */
		private function compileMysqlFulltext(AstCreateIndex $statement): string {
			return sprintf(
				'CREATE FULLTEXT INDEX %s ON %s (%s)',
				$this->identifierQuoter->quoteIdentifier($statement->getIndexName()),
				$this->identifierQuoter->quoteIdentifier($statement->getTableName()),
				$this->quotedColumnList($statement->getColumns())
			);
		}

		/**
		 * pgsql: a GIN expression index over to_tsvector('english', ...).
		 * Each column is coalesced to '' before concatenation — Postgres's
		 * `||` yields NULL for the whole expression if any operand is NULL,
		 * which would silently drop that row from the index entirely.
		 */
		private function compilePostgresFulltext(AstCreateIndex $statement): string {
			$vectorExpression = implode(
				" || ' ' || ",
				array_map(
					fn(string $column) => sprintf('coalesce(%s, \'\')', $this->identifierQuoter->quoteIdentifier($column)),
					$statement->getColumns()
				)
			);

			return sprintf(
				"CREATE INDEX %s ON %s USING GIN (to_tsvector('english', %s))",
				$this->identifierQuoter->quoteIdentifier($statement->getIndexName()),
				$this->identifierQuoter->quoteIdentifier($statement->getTableName()),
				$vectorExpression
			);
		}

		/**
		 * sqlsrv: bootstrap the shared fulltext catalog if it doesn't exist
		 * yet, then create the table's (unnamed, one-per-table) fulltext
		 * index against it.
		 * @param string|null $keyIndexName Resolved by CreateIndexExecutor via schema introspection
		 * @return list<string>
		 */
		private function compileSqlServerFulltext(AstCreateIndex $statement, ?string $keyIndexName): array {
			if ($keyIndexName === null) {
				throw new \InvalidArgumentException('compileSqlServerFulltext() requires $sqlServerKeyIndexName to be resolved first');
			}

			$catalog = $this->identifierQuoter->quoteIdentifier(self::SQL_SERVER_FULLTEXT_CATALOG);

			$bootstrapCatalog = sprintf(
				"IF NOT EXISTS (SELECT 1 FROM sys.fulltext_catalogs WHERE name = %s) CREATE FULLTEXT CATALOG %s AS DEFAULT",
				$this->quoteStringLiteral(self::SQL_SERVER_FULLTEXT_CATALOG),
				$catalog
			);

			$createIndex = sprintf(
				'CREATE FULLTEXT INDEX ON %s (%s) KEY INDEX %s ON %s',
				$this->identifierQuoter->quoteIdentifier($statement->getTableName()),
				$this->quotedColumnList($statement->getColumns()),
				$this->identifierQuoter->quoteIdentifier($keyIndexName),
				$catalog
			);

			return [$bootstrapCatalog, $createIndex, $this->tagFulltextIndexName($statement)];
		}

		/**
		 * Tags the table with an extended property recording the QUEL
		 * index name (see this class's docblock, "sqlsrv" bullet).
		 * Idempotent — updates the property instead of erroring when a
		 * prior tag already exists (sp_addextendedproperty fails on a
		 * duplicate), which matters if a fulltext index was previously
		 * dropped and recreated with a different name.
		 */
		private function tagFulltextIndexName(AstCreateIndex $statement): string {
			$tableName = $this->quoteStringLiteral($statement->getTableName());
			$propertyName = $this->quoteStringLiteral(self::SQL_SERVER_FULLTEXT_INDEX_NAME_PROPERTY);
			$indexName = $this->quoteStringLiteral($statement->getIndexName());

			return sprintf(
				"IF EXISTS (SELECT 1 FROM sys.extended_properties WHERE major_id = OBJECT_ID(%s) AND minor_id = 0 AND name = %s) " .
				"EXEC sp_updateextendedproperty @name = %s, @value = %s, @level0type = N'SCHEMA', @level0name = N'dbo', @level1type = N'TABLE', @level1name = %s " .
				"ELSE EXEC sp_addextendedproperty @name = %s, @value = %s, @level0type = N'SCHEMA', @level0name = N'dbo', @level1type = N'TABLE', @level1name = %s",
				$tableName, $propertyName,
				$propertyName, $indexName, $tableName,
				$propertyName, $indexName, $tableName
			);
		}

		/**
		 * sqlite: an FTS5 external-content virtual table (physically named
		 * after the index), plus the three triggers SQLite's own
		 * documentation prescribes to keep it in sync with the base table —
		 * an external-content FTS5 table is never updated automatically when
		 * the base table changes.
		 * @param string|null $primaryKeyColumn Resolved by CreateIndexExecutor via schema introspection
		 * @return list<string>
		 */
		private function compileSqliteFulltext(AstCreateIndex $statement, ?string $primaryKeyColumn): array {
			if ($primaryKeyColumn === null) {
				throw new \InvalidArgumentException('compileSqliteFulltext() requires $primaryKeyColumn to be resolved first');
			}

			$ftsTable = $statement->getIndexName();
			$quotedFtsTable = $this->identifierQuoter->quoteIdentifier($ftsTable);
			$quotedBaseTable = $this->identifierQuoter->quoteIdentifier($statement->getTableName());
			$quotedPrimaryKeyColumn = $this->identifierQuoter->quoteIdentifier($primaryKeyColumn);
			$columns = $statement->getColumns();
			$quotedColumns = $this->quotedColumnList($columns);

			$createVirtualTable = sprintf(
				'CREATE VIRTUAL TABLE %s USING fts5(%s, content=%s, content_rowid=%s)',
				$quotedFtsTable,
				$quotedColumns,
				$this->quoteStringLiteral($statement->getTableName()),
				$this->quoteStringLiteral($primaryKeyColumn)
			);

			$newColumnValues = implode(', ', array_map(fn(string $column) => 'new.' . $this->identifierQuoter->quoteIdentifier($column), $columns));
			$oldColumnValues = implode(', ', array_map(fn(string $column) => 'old.' . $this->identifierQuoter->quoteIdentifier($column), $columns));

			$insertTrigger = sprintf(
				'CREATE TRIGGER %s AFTER INSERT ON %s BEGIN INSERT INTO %s(rowid, %s) VALUES (new.%s, %s); END',
				$this->identifierQuoter->quoteIdentifier("{$ftsTable}_ai"),
				$quotedBaseTable,
				$quotedFtsTable,
				$quotedColumns,
				$quotedPrimaryKeyColumn,
				$newColumnValues
			);

			$deleteTrigger = sprintf(
				'CREATE TRIGGER %s AFTER DELETE ON %s BEGIN INSERT INTO %s(%s, rowid, %s) VALUES (\'delete\', old.%s, %s); END',
				$this->identifierQuoter->quoteIdentifier("{$ftsTable}_ad"),
				$quotedBaseTable,
				$quotedFtsTable,
				$quotedFtsTable,
				$quotedColumns,
				$quotedPrimaryKeyColumn,
				$oldColumnValues
			);

			$updateTrigger = sprintf(
				'CREATE TRIGGER %s AFTER UPDATE ON %s BEGIN ' .
				'INSERT INTO %s(%s, rowid, %s) VALUES (\'delete\', old.%s, %s); ' .
				'INSERT INTO %s(rowid, %s) VALUES (new.%s, %s); END',
				$this->identifierQuoter->quoteIdentifier("{$ftsTable}_au"),
				$quotedBaseTable,
				$quotedFtsTable,
				$quotedFtsTable,
				$quotedColumns,
				$quotedPrimaryKeyColumn,
				$oldColumnValues,
				$quotedFtsTable,
				$quotedColumns,
				$quotedPrimaryKeyColumn,
				$newColumnValues
			);

			return [$createVirtualTable, $insertTrigger, $deleteTrigger, $updateTrigger];
		}

		/**
		 * @param string[] $columns
		 */
		private function quotedColumnList(array $columns): string {
			return implode(', ', array_map(fn(string $column) => $this->identifierQuoter->quoteIdentifier($column), $columns));
		}

		/**
		 * Escapes and wraps a value as a single-quoted SQL string literal
		 * (doubling embedded quotes, the ANSI SQL escaping rule) — same
		 * convention QuelToSQLCreate/QuelToSQLDestroy use.
		 */
		private function quoteStringLiteral(string $value): string {
			return "'" . str_replace("'", "''", $value) . "'";
		}
	}
