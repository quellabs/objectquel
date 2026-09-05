<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyIndex;

	/**
	 * Compiles an AstDestroyIndex statement (`destroy Name on Table [if
	 * exists]`) to dialect-correct DDL. Sibling to QuelToSQLDestroy/
	 * QuelToSQLCreateIndex.
	 *
	 * Dialect branching is only about whether `ON <table>` is part of `DROP
	 * INDEX`'s own syntax:
	 * - mysql/mariadb/sqlsrv: `DROP INDEX <name> ON <table>` — `ON` is
	 *   mandatory.
	 * - pgsql/sqlite: `DROP INDEX <name>` — no `ON`; index names are unique
	 *   per-schema on both, not per-table. `$tableName` is still parsed
	 *   (and required in the AST) since mysql/sqlsrv need it and the
	 *   grammar itself is dialect-independent.
	 *
	 * This same compile path also covers mysql/pgsql fulltext index destroy
	 * with no special-casing — both are real, named, ordinary-enough index
	 * objects that plain `DROP INDEX` drops the same way as a plain index.
	 *
	 * `IF EXISTS`: pgsql, sqlite, sqlsrv (2016+), and MariaDB all support
	 * `DROP INDEX IF EXISTS` natively. Plain MySQL never added it, so it's
	 * emulated here via dynamic SQL (multi-statement compile) rather than
	 * silently dropping the qualifier — see emulateMysqlIfExists().
	 *
	 * sqlsrv's and sqlite's own fulltext "indexes" don't fit convertToSQL()
	 * at all — neither is visible via DatabaseAdapter::getIndexes(), so
	 * DestroyIndexExecutor resolves and verifies them itself via schema
	 * introspection first, then calls one of these two dedicated methods
	 * directly instead of convertToSQL():
	 * - convertToSqlServerFulltextDropSQL(): a T-SQL fulltext index is
	 *   unnamed (one per table) — see QuelToSQLCreateIndex's
	 *   tagFulltextIndexName(), the only place $indexName is durably
	 *   recorded for it.
	 * - convertToSqliteFts5DropSQL(): SQLite's fulltext "index" is an FTS5
	 *   external-content virtual table plus three sync triggers (see
	 *   QuelToSQLCreateIndex::compileSqliteFulltext()), not an index row
	 *   at all.
	 * Both were originally deferred — see objectquel-destroy-index-plan.md's
	 * "Fulltext index destroy on sqlsrv/sqlite" section for why a name
	 * can't just be trusted on these two dialects without that resolution
	 * step.
	 */
	class QuelToSQLDestroyIndex {

		private SqlIdentifierQuoter $identifierQuoter;

		private PlatformCapabilitiesInterface $platform;

		/**
		 * QuelToSQLDestroyIndex constructor
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(PlatformCapabilitiesInterface $platform) {
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
			$this->platform = $platform;
		}

		/**
		 * Compiles a `destroy Name on Table [if exists]` statement to SQL.
		 * @param AstDestroyIndex $statement
		 * @return list<string>
		 */
		public function convertToSQL(AstDestroyIndex $statement): array {
			$dialect = $this->platform->getDatabaseType();

			if ($statement->isIfExists() && $dialect === 'mysql') {
				return $this->emulateMysqlIfExists($statement);
			}

			$needsOn = in_array($dialect, ['mysql', 'mariadb', 'sqlsrv'], true);

			return [$this->plainDrop($statement, $needsOn, $statement->isIfExists())];
		}

		/**
		 * Drops a SQL Server table's fulltext index. Only ever called by
		 * DestroyIndexExecutor once it has already confirmed, via
		 * DatabaseAdapter::hasSqlServerFulltextIndex() and
		 * getSqlServerExtendedProperty(), that $statement's index name
		 * really is the one tagged on this table's fulltext index — so the
		 * drop itself is unconditional, the same "existence already
		 * confirmed" precedent QuelToSQLDestroy's sqlServerUnqualifiedDrop()
		 * uses for temp tables. Also removes the extended-property tag, so
		 * a later fulltext index created on this table starts untagged
		 * rather than carrying a stale name.
		 * @param AstDestroyIndex $statement
		 * @return list<string>
		 */
		public function convertToSqlServerFulltextDropSQL(AstDestroyIndex $statement): array {
			$tableName = $this->quoteStringLiteral($statement->getTableName());
			$propertyName = $this->quoteStringLiteral(QuelToSQLCreateIndex::SQL_SERVER_FULLTEXT_INDEX_NAME_PROPERTY);

			$dropProperty = sprintf(
				"IF EXISTS (SELECT 1 FROM sys.extended_properties WHERE major_id = OBJECT_ID(%s) AND minor_id = 0 AND name = %s) " .
				"EXEC sp_dropextendedproperty @name = %s, @level0type = N'SCHEMA', @level0name = N'dbo', @level1type = N'TABLE', @level1name = %s",
				$tableName,
				$propertyName,
				$propertyName,
				$tableName
			);

			$dropIndex = sprintf(
				'DROP FULLTEXT INDEX ON %s',
				$this->identifierQuoter->quoteIdentifier($statement->getTableName())
			);

			return [$dropProperty, $dropIndex];
		}

		/**
		 * Drops a SQLite FTS5 external-content virtual table plus its three
		 * sync triggers (see QuelToSQLCreateIndex::compileSqliteFulltext(),
		 * which names them `<index>_ai`/`_ad`/`_au`). Only ever called by
		 * DestroyIndexExecutor once it has already confirmed, via
		 * DatabaseAdapter::getSqliteFts5BaseTable(), that $statement's
		 * index name really is an FTS5 virtual table built against this
		 * table — so, like convertToSqlServerFulltextDropSQL(), the drop is
		 * unconditional. Triggers are dropped before the virtual table
		 * itself, though SQLite would drop them automatically regardless
		 * (they reference it directly).
		 * @param AstDestroyIndex $statement
		 * @return list<string>
		 */
		public function convertToSqliteFts5DropSQL(AstDestroyIndex $statement): array {
			$ftsTable = $statement->getIndexName();

			return [
				'DROP TRIGGER ' . $this->identifierQuoter->quoteIdentifier("{$ftsTable}_ai"),
				'DROP TRIGGER ' . $this->identifierQuoter->quoteIdentifier("{$ftsTable}_ad"),
				'DROP TRIGGER ' . $this->identifierQuoter->quoteIdentifier("{$ftsTable}_au"),
				'DROP TABLE ' . $this->identifierQuoter->quoteIdentifier($ftsTable),
			];
		}

		/**
		 * A single `DROP INDEX [IF EXISTS] <name> [ON <table>]` statement.
		 */
		private function plainDrop(AstDestroyIndex $statement, bool $includeOn, bool $ifExists): string {
			$sql = 'DROP INDEX ' .
				($ifExists ? 'IF EXISTS ' : '') .
				$this->identifierQuoter->quoteIdentifier($statement->getIndexName());

			if ($includeOn) {
				$sql .= ' ON ' . $this->identifierQuoter->quoteIdentifier($statement->getTableName());
			}

			return $sql;
		}

		/**
		 * Plain MySQL has no `DROP INDEX IF EXISTS` and no inline
		 * conditional-DDL statement (unlike sqlsrv's
		 * `IF OBJECT_ID(...) ... ELSE ...` — see QuelToSQLDestroy) — a
		 * conditional drop needs dynamic SQL via prepared statements
		 * instead, checked against information_schema.statistics.
		 * @return list<string>
		 */
		private function emulateMysqlIfExists(AstDestroyIndex $statement): array {
			$dropStatement = $this->plainDrop($statement, true, false);

			return [
				sprintf(
					'SET @idx_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s)',
					$this->quoteStringLiteral($statement->getTableName()),
					$this->quoteStringLiteral($statement->getIndexName())
				),
				sprintf(
					"SET @sql = IF(@idx_exists > 0, %s, 'DO 0')",
					$this->quoteStringLiteral($dropStatement)
				),
				'PREPARE stmt FROM @sql',
				'EXECUTE stmt',
				'DEALLOCATE PREPARE stmt',
			];
		}

		/**
		 * Escapes and wraps a value as a single-quoted SQL string literal
		 * (doubling embedded quotes, the ANSI SQL escaping rule) — same
		 * convention QuelToSQLCreate/QuelToSQLDestroy/QuelToSQLCreateIndex use.
		 */
		private function quoteStringLiteral(string $value): string {
			return "'" . str_replace("'", "''", $value) . "'";
		}
	}
