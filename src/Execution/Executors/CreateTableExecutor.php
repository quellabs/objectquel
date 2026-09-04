<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateTable;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLCreate;

	/**
	 * Executes an AstCreateTable statement: compiles it via QuelToSQLCreate
	 * and runs the resulting DDL directly against the connection.
	 *
	 * Bypasses the `retrieve` pipeline entirely — none of it applies to a DDL
	 * statement with no rows to return. Mirrors TempTableExecutor's existing
	 * precedent of building and running DDL directly rather than through
	 * BuildSqlFromAst, which is a retrieve-pipeline expression visitor, not a
	 * top-level statement compiler.
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

		/**
		 * CreateTableExecutor constructor
		 * @param DatabaseAdapter $connection
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(DatabaseAdapter $connection, PlatformCapabilitiesInterface $platform) {
			$this->connection = $connection;
			$this->compiler = new QuelToSQLCreate($platform);
		}

		/**
		 * Compile and execute a `create [temporary] Name (...)` statement.
		 * @param AstCreateTable $statement
		 * @return void
		 * @throws QuelException On DDL failure
		 */
		public function execute(AstCreateTable $statement): void {
			$sql = $this->compiler->convertToSQL($statement);

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
