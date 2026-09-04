<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroy;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLDestroy;

	/**
	 * Executes an AstDestroy statement: compiles it via QuelToSQLDestroy (one
	 * `DROP TABLE` per target) and runs each statement directly against the
	 * connection, in order, stopping at the first failure.
	 *
	 * Table-only — index destroy needs a name→owning-table registry that
	 * doesn't exist yet (see objectquel-destroy-plan.md).
	 *
	 * Bypasses the retrieve pipeline entirely — none of it applies to a DDL
	 * statement with no rows to return.
	 */
	class DestroyExecutor {

		/**
		 * Database connection used to execute the generated DDL
		 * @var DatabaseAdapter
		 */
		private DatabaseAdapter $connection;

		/**
		 * Compiles the AstDestroy statement to dialect-correct SQL.
		 * @var QuelToSQLDestroy
		 */
		private QuelToSQLDestroy $compiler;

		/**
		 * DestroyExecutor constructor
		 * @param DatabaseAdapter $connection
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(DatabaseAdapter $connection, PlatformCapabilitiesInterface $platform) {
			$this->connection = $connection;
			$this->compiler = new QuelToSQLDestroy($platform);
		}

		/**
		 * Compile and execute a `destroy Name {, Name}` statement.
		 * @param AstDestroy $statement
		 * @return void
		 * @throws QuelException On the first DDL failure
		 */
		public function execute(AstDestroy $statement): void {
			$names = $statement->getNames();

			foreach ($this->compiler->convertToSQL($statement) as $index => $sql) {
				// execute() swallows the exception and returns null on failure
				// rather than throwing (same as CreateTableExecutor).
				if ($this->connection->execute($sql) === null) {
					throw new QuelException(
						"Failed to destroy '{$names[$index]}': {$this->connection->getLastErrorMessage()}",
						'table_destruction_error'
					);
				}
			}
		}
	}
