<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyIndex;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLCreateIndex;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLDestroyIndex;

	/**
	 * Executes an AstDestroyIndex statement: compiles it via
	 * QuelToSQLDestroyIndex and runs the resulting DDL statement(s) directly
	 * against the connection, in order, stopping at the first failure.
	 *
	 * Bypasses the `retrieve` pipeline entirely — none of it applies to a DDL
	 * statement with no rows to return. Mirrors CreateIndexExecutor/
	 * DestroyExecutor.
	 *
	 * mysql/mariadb/pgsql need no schema introspection at all — every index
	 * they can drop this way (plain, unique, or their own real named
	 * fulltext index) is visible via DatabaseAdapter::getIndexes(), so the
	 * compiled SQL is simply run and left to succeed, no-op (native `IF
	 * EXISTS`), or fail loudly on its own (see QuelToSQLDestroyIndex, and
	 * objectquel-destroy-index-plan.md's "No compiler-side existence
	 * pre-check" decision).
	 *
	 * sqlsrv and sqlite are different: each has its own fulltext "index"
	 * that getIndexes() can never report (sqlsrv's is unnamed catalog
	 * metadata, not a schema-collection index at all; sqlite's is a plain
	 * FTS5 virtual table, not an index row) — see
	 * objectquel-destroy-index-plan.md's "Fulltext index destroy on
	 * sqlsrv/sqlite" section, which originally deferred exactly this. Both
	 * need one extra round of introspection first, to decide whether
	 * $indexName actually correlates to that special object — see
	 * executeSqlServer()/executeSqlite(). A name that resolves to neither
	 * still falls through to the ordinary path unconditionally, so a
	 * genuine typo gets the exact same native engine error (or native `IF
	 * EXISTS` no-op) it always did: this introspection only ever adds a new
	 * destroy target, it never intercepts the existing one.
	 */
	class DestroyIndexExecutor {

		/**
		 * Database connection used to execute the generated DDL, and to
		 * resolve the sqlsrv/sqlite fulltext special cases (see class
		 * docblock).
		 * @var DatabaseAdapter
		 */
		private DatabaseAdapter $connection;

		/**
		 * Compiles the AstDestroyIndex statement to dialect-correct SQL.
		 * @var QuelToSQLDestroyIndex
		 */
		private QuelToSQLDestroyIndex $compiler;

		private PlatformCapabilitiesInterface $platform;

		/**
		 * DestroyIndexExecutor constructor
		 * @param DatabaseAdapter $connection
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(DatabaseAdapter $connection, PlatformCapabilitiesInterface $platform) {
			$this->connection = $connection;
			$this->platform = $platform;
			$this->compiler = new QuelToSQLDestroyIndex($platform);
		}

		/**
		 * Compile and execute a `destroy Name on Table [if exists]` statement.
		 * @param AstDestroyIndex $statement
		 * @return void
		 * @throws QuelException On DDL failure
		 */
		public function execute(AstDestroyIndex $statement): void {
			match ($this->platform->getDatabaseType()) {
				'sqlsrv' => $this->executeSqlServer($statement),
				'sqlite' => $this->executeSqlite($statement),
				default => $this->runStatements($statement, $this->compiler->convertToSQL($statement)),
			};
		}

		/**
		 * A T-SQL fulltext index is unnamed (one per table) — see
		 * QuelToSQLCreateIndex::tagFulltextIndexName(), the only place the
		 * QUEL index name is durably recorded for it. Only takes the
		 * fulltext path for a name that ISN'T an ordinary index and that
		 * tag actually confirms; every other name (including a genuine
		 * typo) falls through to the ordinary path, whose own native `DROP
		 * INDEX [IF EXISTS]` handles it exactly as before this method
		 * existed.
		 */
		private function executeSqlServer(AstDestroyIndex $statement): void {
			$tableName = $statement->getTableName();
			$indexes = $this->connection->getIndexes($tableName);

			if (
				!isset($indexes[$statement->getIndexName()]) &&
				$this->connection->hasSqlServerFulltextIndex($tableName) &&
				$this->connection->getSqlServerExtendedProperty(
					$tableName,
					QuelToSQLCreateIndex::SQL_SERVER_FULLTEXT_INDEX_NAME_PROPERTY
				) === $statement->getIndexName()
			) {
				$this->runStatements($statement, $this->compiler->convertToSqlServerFulltextDropSQL($statement));
				return;
			}

			$this->runStatements($statement, $this->compiler->convertToSQL($statement));
		}

		/**
		 * SQLite's fulltext "index" is an FTS5 external-content virtual
		 * table plus three sync triggers (see
		 * QuelToSQLCreateIndex::compileSqliteFulltext()), not a row in
		 * getIndexes() at all. Same "only intercept a confirmed match"
		 * rationale as executeSqlServer().
		 */
		private function executeSqlite(AstDestroyIndex $statement): void {
			$tableName = $statement->getTableName();
			$indexes = $this->connection->getIndexes($tableName);

			if (!isset($indexes[$statement->getIndexName()])) {
				$baseTable = $this->connection->getSqliteFts5BaseTable($statement->getIndexName());

				if ($baseTable === $tableName) {
					$this->runStatements($statement, $this->compiler->convertToSqliteFts5DropSQL($statement));
					return;
				}
			}

			$this->runStatements($statement, $this->compiler->convertToSQL($statement));
		}

		/**
		 * @param string[] $statements
		 * @throws QuelException On the first failing statement
		 */
		private function runStatements(AstDestroyIndex $statement, array $statements): void {
			foreach ($statements as $sql) {
				// execute() swallows the exception and returns null on failure
				// rather than throwing (same as CreateIndexExecutor).
				if ($this->connection->execute($sql) === null) {
					throw new QuelException(
						"Failed to destroy index '{$statement->getIndexName()}' on '{$statement->getTableName()}': {$this->connection->getLastErrorMessage()}",
						'index_destruction_error'
					);
				}
			}
		}
	}
