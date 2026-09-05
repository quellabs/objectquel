<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroy;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLDestroy;

	/**
	 * Executes an AstDestroy statement: compiles it via QuelToSQLDestroy (a
	 * single `DROP TABLE`, `IF EXISTS` only when the statement's `if
	 * exists` qualifier is present) and runs it directly against the
	 * connection.
	 *
	 * Table-only — see Execution\Executors\DestroyIndexExecutor for the
	 * `destroy Name on Table` index form.
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
		 * Compile and execute a `destroy [temporary] Name [if exists]` statement.
		 * @param AstDestroy $statement
		 * @return void
		 * @throws QuelException On DDL failure
		 */
		public function execute(AstDestroy $statement): void {
			foreach ($this->compiler->convertToSQL($statement) as $sql) {
				// execute() swallows the exception and returns null on failure
				// rather than throwing (same as CreateTableExecutor).
				if ($this->connection->execute($sql) === null) {
					throw new QuelException(
						"Failed to destroy '{$statement->getName()}': {$this->connection->getLastErrorMessage()}",
						'table_destruction_error'
					);
				}
			}
		}
	}
