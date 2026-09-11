<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Execution\ExecutionContext;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstStatement;
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
	 * resolveSqlServerStatements()/resolveSqliteStatements(). A name that resolves to neither
	 * still falls through to the ordinary path unconditionally, so a
	 * genuine typo gets the exact same native engine error (or native `IF
	 * EXISTS` no-op) it always did: this introspection only ever adds a new
	 * destroy target, it never intercepts the existing one.
	 */
	class DestroyIndexExecutor implements DdlStatementExecutorInterface {

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

		private DdlRunner $ddlRunner;

		/**
		 * DestroyIndexExecutor constructor
		 * @param DatabaseAdapter $connection
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(DatabaseAdapter $connection, PlatformCapabilitiesInterface $platform) {
			$this->connection = $connection;
			$this->platform = $platform;
			$this->compiler = new QuelToSQLDestroyIndex($platform);
			$this->ddlRunner = new DdlRunner($connection);
		}

		/**
		 * Compile and execute a `destroy Name on Table [if exists]` statement.
		 * @param AstStatement $statement
		 * @param ExecutionContext $context
		 * @return void
		 * @throws QuelException On DDL failure
		 */
		public function execute(AstStatement $statement, ExecutionContext $context): void {
			assert($statement instanceof AstDestroyIndex);

			$this->ddlRunner->run(
				$this->compileSql($statement),
				"Failed to destroy index '{$statement->getIndexName()}' on '{$statement->getTableName()}'",
				'index_destruction_error'
			);
		}

		/**
		 * Compiles a `destroy Name on Table [if exists]` statement to SQL,
		 * used by execute() before running it. Still performs the
		 * sqlsrv/sqlite fulltext lookups (schema introspection — read-only)
		 * since those determine which SQL is generated.
		 * @param AstDestroyIndex $statement
		 * @return list<string>
		 */
		public function compileSql(AstDestroyIndex $statement): array {
			return match ($this->platform->getDatabaseType()) {
				'sqlsrv' => $this->resolveSqlServerStatements($statement),
				'sqlite' => $this->resolveSqliteStatements($statement),
				default => $this->compiler->convertToSQL($statement),
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
		 * @param AstDestroyIndex $statement
		 * @return list<string>
		 */
		private function resolveSqlServerStatements(AstDestroyIndex $statement): array {
			$tableName = $statement->getTableName();
			$indexes = $this->connection->getIndexes($tableName);

			if (
				!isset($indexes[$statement->getIndexName()]) &&
				$this->connection->hasSqlServerFulltextIndex($tableName) &&
				$this->isSqlServerFulltextIndexName($tableName, $statement->getIndexName())
			) {
				return $this->compiler->convertToSqlServerFulltextDropSQL($statement);
			} else {
				return $this->compiler->convertToSQL($statement);
			}
		}

		/**
		 * Whether $indexName is the QUEL name tagged onto the table's
		 * (unnamed) T-SQL fulltext index — see
		 * QuelToSQLCreateIndex::tagFulltextIndexName().
		 * @param string $tableName
		 * @param string $indexName
		 * @return bool
		 */
		private function isSqlServerFulltextIndexName(string $tableName, string $indexName): bool {
			return $this->connection->getSqlServerExtendedProperty(
				$tableName,
				QuelToSQLCreateIndex::SQL_SERVER_FULLTEXT_INDEX_NAME_PROPERTY
			) === $indexName;
		}

		/**
		 * SQLite's fulltext "index" is an FTS5 external-content virtual
		 * table plus three sync triggers (see
		 * QuelToSQLCreateIndex::compileSqliteFulltext()), not a row in
		 * getIndexes() at all. Same "only intercept a confirmed match"
		 * rationale as resolveSqlServerStatements().
		 * @param AstDestroyIndex $statement
		 * @return list<string>
		 */
		private function resolveSqliteStatements(AstDestroyIndex $statement): array {
			$tableName = $statement->getTableName();
			$indexes = $this->connection->getIndexes($tableName);

			// A real index by this name takes precedence — only chase the
			// FTS5 possibility once $indexName doesn't resolve as one.
			if (!isset($indexes[$statement->getIndexName()])) {
				// Fetch table name
				$baseTable = $this->connection->getSqliteFts5BaseTable($statement->getIndexName());

				// Confirmed match: $indexName is the FTS5 virtual table for
				// this exact table, so compile the FTS5-specific drop
				// (table + sync triggers) instead of an ordinary DROP INDEX.
				if ($baseTable === $tableName) {
					return $this->compiler->convertToSqliteFts5DropSQL($statement);
				}
			}

			// Neither a known index nor a confirmed FTS5 table — fall
			// through to the ordinary path, which fails loudly on its own
			// if $indexName doesn't exist at all.
			return $this->compiler->convertToSQL($statement);
		}
	}
