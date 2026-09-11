<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\FulltextIndexStyle;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Execution\ExecutionContext;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstStatement;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLCreateIndex;

	/**
	 * Executes an AstCreateIndex statement: compiles it via
	 * QuelToSQLCreateIndex and runs the resulting DDL statement(s) directly
	 * against the connection, in order, stopping at the first failure.
	 *
	 * Bypasses the `retrieve` pipeline entirely — none of it applies to a DDL
	 * statement with no rows to return. Mirrors CreateTableExecutor/
	 * DestroyExecutor.
	 *
	 * A fulltext index on sqlsrv/sqlite needs one extra piece of schema
	 * information QuelToSQLCreateIndex has no connection to look up itself
	 * (an existing unique/primary index name for sqlsrv's KEY INDEX clause;
	 * the base table's primary key column for sqlite's FTS5
	 * content_rowid) — resolved here, via the connection, before compiling.
	 */
	class CreateIndexExecutor implements DdlStatementExecutorInterface {

		/**
		 * Database connection used to execute the generated DDL
		 * @var DatabaseAdapter
		 */
		private DatabaseAdapter $connection;

		/**
		 * Compiles the AstCreateIndex statement to dialect-correct SQL.
		 * @var QuelToSQLCreateIndex
		 */
		private QuelToSQLCreateIndex $compiler;

		private PlatformCapabilitiesInterface $platform;

		private DdlRunner $ddlRunner;

		/**
		 * CreateIndexExecutor constructor
		 * @param DatabaseAdapter $connection
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(DatabaseAdapter $connection, PlatformCapabilitiesInterface $platform) {
			$this->connection = $connection;
			$this->platform = $platform;
			$this->compiler = new QuelToSQLCreateIndex($platform);
			$this->ddlRunner = new DdlRunner($connection);
		}

		/**
		 * Compile and execute an `index [unique|fulltext] on Table is
		 * index_name (...)` statement.
		 * @param AstStatement $statement
		 * @param ExecutionContext $context
		 * @return void
		 * @throws QuelException On DDL failure, or if a fulltext index's
		 *         required schema prerequisite (sqlsrv: an existing
		 *         unique/primary index; sqlite: a primary key) is missing
		 */
		public function execute(AstStatement $statement, ExecutionContext $context): void {
			assert($statement instanceof AstCreateIndex);

			$this->ddlRunner->run(
				$this->compileSql($statement),
				"Failed to create index '{$statement->getIndexName()}' on '{$statement->getTableName()}'",
				'index_creation_error'
			);
		}

		/**
		 * Compiles an `index [unique|fulltext] on Table is index_name (...)`
		 * statement to SQL, used by execute() before running it. Still
		 * performs the fulltext prerequisite lookup (schema introspection —
		 * read-only) since that determines the SQL itself.
		 * @param AstCreateIndex $statement
		 * @return list<string>
		 * @throws QuelException If a fulltext index's required schema
		 *         prerequisite (sqlsrv: an existing unique/primary index;
		 *         sqlite: a primary key) is missing
		 */
		public function compileSql(AstCreateIndex $statement): array {
			$prerequisites = $this->resolveFulltextPrerequisites($statement);

			return $this->compiler->convertToSQL(
				$statement,
				$prerequisites->getPrimaryKeyColumn(),
				$prerequisites->getSqlServerKeyIndexName()
			);
		}

		/**
		 * Resolves the schema information a fulltext index needs on sqlsrv
		 * (an existing unique/primary index name) and sqlite (the base
		 * table's primary key column). Both null for every other case
		 * (plain/unique indexes, and fulltext on mysql/mariadb/pgsql, none
		 * of which need this).
		 * @param AstCreateIndex $statement
		 * @return FulltextPrerequisites
		 * @throws QuelException If a fulltext index's required schema
		 *         prerequisite (sqlsrv: an existing unique/primary index;
		 *         sqlite: a primary key) is missing
		 */
		private function resolveFulltextPrerequisites(AstCreateIndex $statement): FulltextPrerequisites {
			// Plain/unique indexes never need this lookup, regardless of dialect.
			if ($statement->getType() !== 'fulltext') {
				return new FulltextPrerequisites(null, null);
			}

			$dialect = $this->platform->getDatabaseType();
			$tableName = $statement->getTableName();

			if ($this->platform->getFulltextIndexStyle() === FulltextIndexStyle::Fts5) {
				// FTS5 virtual tables need an explicit content_rowid pointing at
				// the base table's primary key to avoid duplicating its content.
				$primaryKeyColumn = $this->connection->getPrimaryKey($tableName);

				if ($primaryKeyColumn === '') {
					throw new QuelException(
						"Cannot create fulltext index '{$statement->getIndexName()}': table '{$tableName}' has no primary key " .
						"(required for SQLite's FTS5 content_rowid)",
						'index_creation_error'
					);
				}

				return new FulltextPrerequisites($primaryKeyColumn, null);
			}

			if ($dialect === 'sqlsrv') {
				// sqlsrv's CREATE FULLTEXT INDEX syntax mandates a KEY INDEX
				// clause naming an existing unique/primary index on the table.
				return new FulltextPrerequisites(null, $this->resolveSqlServerKeyIndexName($statement));
			}

			// mysql/mariadb/pgsql fulltext indexes need no extra schema lookup.
			return new FulltextPrerequisites(null, null);
		}

		/**
		 * Finds an existing unique or primary index on the table to serve as
		 * sqlsrv's `KEY INDEX` — a fulltext index requires one, and the
		 * compiler has no connection of its own to look it up. Primary key
		 * index preferred; falls back to any unique index.
		 * @param AstCreateIndex $statement
		 * @return string
		 * @throws QuelException If the table has neither
		 */
		private function resolveSqlServerKeyIndexName(AstCreateIndex $statement): string {
			$indexes = $this->connection->getIndexes($statement->getTableName());
			$fallbackUniqueIndexName = null;

			foreach ($indexes as $indexName => $index) {
				if ($index['type'] === 'primary') {
					return $indexName;
				}

				if ($fallbackUniqueIndexName === null && $index['type'] === 'unique') {
					$fallbackUniqueIndexName = $indexName;
				}
			}

			if ($fallbackUniqueIndexName !== null) {
				return $fallbackUniqueIndexName;
			}

			throw new QuelException(
				"Cannot create fulltext index '{$statement->getIndexName()}': table '{$statement->getTableName()}' has no " .
				"primary key or unique index (required for SQL Server's KEY INDEX clause)",
				'index_creation_error'
			);
		}
	}