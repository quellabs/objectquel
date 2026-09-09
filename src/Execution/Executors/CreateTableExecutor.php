<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateTable;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateTableIndex;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLCreate;

	/**
	 * Executes an AstCreateTable statement: compiles it via QuelToSQLCreate
	 * (the CREATE TABLE statement itself) plus CreateIndexExecutor (any
	 * embedded index entries, reused as sugar rather than reimplemented —
	 * see objectquel-index-clause-design.md, "Sugar, not reimplementation")
	 * and runs the resulting DDL statements directly against the
	 * connection, in order, stopping at the first failure.
	 *
	 * Bypasses the `retrieve` pipeline entirely — none of it applies to a DDL
	 * statement with no rows to return. Mirrors TempTableExecutor's existing
	 * precedent of building and running DDL directly rather than through
	 * BuildSqlFromAst, which is a retrieve-pipeline expression visitor, not a
	 * top-level statement compiler.
	 *
	 * The table is created first, then embedded indexes run in declaration
	 * order (see objectquel-index-clause-design.md, decision 3) — there's
	 * nothing to index before the table exists.
	 */
	class CreateTableExecutor {

		/**
		 * Database connection used to execute the generated DDL
		 * @var DatabaseAdapter
		 */
		private DatabaseAdapter $connection;

		/**
		 * Compiles the AstCreateTable statement to dialect-correct SQL.
		 * @var QuelToSQLCreate
		 */
		private QuelToSQLCreate $compiler;

		private CreateIndexExecutor $createIndexExecutor;

		/**
		 * CreateTableExecutor constructor
		 * @param DatabaseAdapter $connection
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(DatabaseAdapter $connection, PlatformCapabilitiesInterface $platform) {
			$this->connection = $connection;
			$this->compiler = new QuelToSQLCreate($platform);
			$this->createIndexExecutor = new CreateIndexExecutor($connection, $platform);
		}

		/**
		 * Compile and execute a `create [temporary] Name (...)` statement.
		 * @param AstCreateTable $statement
		 * @return void
		 * @throws QuelException On DDL failure
		 */
		public function execute(AstCreateTable $statement): void {
			foreach ($this->compileSql($statement) as $sql) {
				// execute() swallows the exception and returns null on failure
				// rather than throwing — a try/catch here would never fire.
				if ($this->connection->execute($sql) === null) {
					throw new QuelException(
						"Failed to create table '{$statement->getTableName()}': {$this->connection->getLastErrorMessage()}",
						'table_creation_error'
					);
				}
			}
		}

		/**
		 * Compiles a `create [temporary] Name (...)` statement to SQL, used
		 * by execute() before running it. Embedded index entries delegate to
		 * CreateIndexExecutor's own compileSql(), which does the same for
		 * their own prerequisites (sqlsrv/sqlite fulltext).
		 * @param AstCreateTable $statement
		 * @return list<string>
		 * @throws QuelException If an embedded index isn't representable on
		 *         the connected engine
		 */
		public function compileSql(AstCreateTable $statement): array {
			$statements = [$this->compiler->convertToSQL($statement)];

			if ($statement->getIndexes() !== []) {
				$physicalTableName = $this->compiler->getPhysicalTableName($statement);

				foreach ($statement->getIndexes() as $index) {
					$statements = [
						...$statements,
						...$this->createIndexExecutor->compileSql($this->toCreateIndex($physicalTableName, $index)),
					];
				}
			}

			return $statements;
		}

		private function toCreateIndex(string $tableName, AstCreateTableIndex $index): AstCreateIndex {
			return new AstCreateIndex($tableName, $index->getIndexName(), $index->getColumns(), $index->isUnique(), $index->getType());
		}
	}
