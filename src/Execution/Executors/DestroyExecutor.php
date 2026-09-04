<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroy;

	/**
	 * Executes an AstDestroy statement: one `DROP TABLE <name>` per target,
	 * run directly against the connection. Table-only — index destroy needs
	 * a name→owning-table registry that doesn't exist yet (see
	 * objectquel-destroy-plan.md).
	 *
	 * No `IF EXISTS`: an unknown name must fail loudly, and a self-built
	 * existence check would wrongly reject a real session-temp table, since
	 * those are invisible to DatabaseAdapter::getTables() on MySQL.
	 *
	 * No dialect branching: plain `DROP TABLE` is correct everywhere for
	 * both permanent and temp tables, and `destroy` has no `temporary`
	 * keyword to distinguish them anyway (unlike TempTableExecutor's own
	 * cleanup, which uses DROP TEMPORARY TABLE for a narrower reason — see
	 * that class).
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
		 * Quotes table identifiers correctly for whichever engine is connected.
		 * @var SqlIdentifierQuoter
		 */
		private SqlIdentifierQuoter $identifierQuoter;

		/**
		 * DestroyExecutor constructor
		 * @param DatabaseAdapter $connection
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(DatabaseAdapter $connection, PlatformCapabilitiesInterface $platform) {
			$this->connection = $connection;
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
		}

		/**
		 * Compile and execute a `destroy Name {, Name}` statement — one
		 * `DROP TABLE` per name, in order, stopping at the first failure.
		 * @param AstDestroy $statement
		 * @return void
		 * @throws QuelException On the first DDL failure
		 */
		public function execute(AstDestroy $statement): void {
			foreach ($statement->getNames() as $name) {
				$sql = 'DROP TABLE ' . $this->identifierQuoter->quoteIdentifier($name);

				// execute() swallows the exception and returns null on failure
				// rather than throwing (same as CreateTableExecutor).
				if ($this->connection->execute($sql) === null) {
					throw new QuelException(
						"Failed to destroy '{$name}': {$this->connection->getLastErrorMessage()}",
						'table_destruction_error'
					);
				}
			}
		}
	}
