<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateIndex;

	/**
	 * Compiles an AstCreateIndex statement to dialect-correct DDL. Sibling to
	 * QuelToSQLCreate/QuelToSQLDestroy.
	 *
	 * The plain/unique case is a single, near-uniform `CREATE [UNIQUE]
	 * INDEX` statement across all dialects — only identifier quoting
	 * differs. `index fulltext on ...` is a materially different case per
	 * dialect: mysql/mariadb use a real `CREATE FULLTEXT INDEX`; pgsql has
	 * no such statement, so a GIN expression index over `to_tsvector(...)`
	 * is used instead; sqlsrv needs a full-text catalog bootstrapped first
	 * plus a `KEY INDEX` resolved by the caller, and since T-SQL fulltext
	 * indexes are unnamed, the index name is recorded as a table-level
	 * extended property instead (see tagFulltextIndexName(), read back by
	 * QuelToSQLDestroyIndex); sqlite has no fulltext index concept at all,
	 * so an FTS5 virtual table plus three sync triggers is created instead.
	 *
	 * convertToSQL() returns a list of one or more statements to run in
	 * order (mirrors QuelToSQLDestroy) — sqlsrv/sqlite's fulltext paths
	 * need more than one.
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

		private PlatformCapabilitiesInterface $platform;

		/**
		 * QuelToSQLCreateIndex constructor
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(PlatformCapabilitiesInterface $platform) {
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
			$this->platform = $platform;
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
		 * @param AstCreateIndex $statement
		 * @return string
		 */
		private function compilePlainOrUnique(AstCreateIndex $statement): string {
			$keyword = $statement->isUnique() ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';

			return sprintf(
				'%s %s ON %s (%s)',
				$keyword,
				$this->identifierQuoter->quoteIdentifier($statement->getIndexName()),
				$this->identifierQuoter->quoteIdentifier($statement->getTableName()),
				$this->identifierQuoter->quoteIdentifierList($statement->getColumns())
			);
		}

		/**
		 * mysql/mariadb: a real, named index — same shape as the plain case,
		 * FULLTEXT instead of [UNIQUE].
		 * @param AstCreateIndex $statement
		 * @return string
		 */
		private function compileMysqlFulltext(AstCreateIndex $statement): string {
			return sprintf(
				'CREATE FULLTEXT INDEX %s ON %s (%s)',
				$this->identifierQuoter->quoteIdentifier($statement->getIndexName()),
				$this->identifierQuoter->quoteIdentifier($statement->getTableName()),
				$this->identifierQuoter->quoteIdentifierList($statement->getColumns())
			);
		}

		/**
		 * pgsql: a GIN expression index over to_tsvector('english', ...).
		 * Each column is coalesced to '' before concatenation — Postgres's
		 * `||` yields NULL for the whole expression if any operand is NULL,
		 * which would silently drop that row from the index entirely.
		 * @param AstCreateIndex $statement
		 * @return string
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
		 * @param AstCreateIndex $statement
		 * @param string|null $keyIndexName Resolved by CreateIndexExecutor via schema introspection
		 * @return list<string>
		 * @throws SemanticException If $keyIndexName was not resolved by the caller
		 */
		private function compileSqlServerFulltext(AstCreateIndex $statement, ?string $keyIndexName): array {
			if ($keyIndexName === null) {
				throw new SemanticException('compileSqlServerFulltext() requires $sqlServerKeyIndexName to be resolved first');
			}

			$catalog = $this->identifierQuoter->quoteIdentifier(self::SQL_SERVER_FULLTEXT_CATALOG);

			$bootstrapCatalog = sprintf(
				"IF NOT EXISTS (SELECT 1 FROM sys.fulltext_catalogs WHERE name = %s) CREATE FULLTEXT CATALOG %s AS DEFAULT",
				$this->identifierQuoter->quoteStringLiteral(self::SQL_SERVER_FULLTEXT_CATALOG),
				$catalog
			);

			$createIndex = sprintf(
				'CREATE FULLTEXT INDEX ON %s (%s) KEY INDEX %s ON %s',
				$this->identifierQuoter->quoteIdentifier($statement->getTableName()),
				$this->identifierQuoter->quoteIdentifierList($statement->getColumns()),
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
		 * @param AstCreateIndex $statement
		 * @return string
		 */
		private function tagFulltextIndexName(AstCreateIndex $statement): string {
			$tableName = $this->identifierQuoter->quoteStringLiteral($statement->getTableName());
			$propertyName = $this->identifierQuoter->quoteStringLiteral(self::SQL_SERVER_FULLTEXT_INDEX_NAME_PROPERTY);
			$indexName = $this->identifierQuoter->quoteStringLiteral($statement->getIndexName());

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
		 * @param AstCreateIndex $statement
		 * @param string|null $primaryKeyColumn Resolved by CreateIndexExecutor via schema introspection
		 * @return list<string>
		 * @throws SemanticException If $primaryKeyColumn was not resolved by the caller
		 */
		private function compileSqliteFulltext(AstCreateIndex $statement, ?string $primaryKeyColumn): array {
			if ($primaryKeyColumn === null) {
				throw new SemanticException('compileSqliteFulltext() requires $primaryKeyColumn to be resolved first');
			}

			$ftsTable = $statement->getIndexName();
			$quotedFtsTable = $this->identifierQuoter->quoteIdentifier($ftsTable);
			$quotedBaseTable = $this->identifierQuoter->quoteIdentifier($statement->getTableName());
			$quotedPrimaryKeyColumn = $this->identifierQuoter->quoteIdentifier($primaryKeyColumn);
			$columns = $statement->getColumns();
			$quotedColumns = $this->identifierQuoter->quoteIdentifierList($columns);

			$createVirtualTable = sprintf(
				'CREATE VIRTUAL TABLE %s USING fts5(%s, content=%s, content_rowid=%s)',
				$quotedFtsTable,
				$quotedColumns,
				$this->identifierQuoter->quoteStringLiteral($statement->getTableName()),
				$this->identifierQuoter->quoteStringLiteral($primaryKeyColumn)
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
	}
