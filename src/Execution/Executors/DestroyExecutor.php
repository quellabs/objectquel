<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroy;

	/**
	 * Executes an AstDestroy statement by running one `DROP TABLE <name>` per
	 * named target directly against the connection.
	 *
	 * Table-only: index destroy is not implemented (see
	 * objectquel-destroy-plan.md — no `create index` or index registry exists
	 * yet to resolve a bare name to its owning table).
	 *
	 * Deliberately no `IF EXISTS` and no dialect branching:
	 *   - No IF EXISTS: a name that doesn't resolve to anything must fail
	 *     loudly (see plan's Semantic analysis), and letting the engine
	 *     itself raise "unknown table" avoids ObjectQuel needing its own
	 *     existence check — which would incorrectly reject a real, live
	 *     temporary table anyway, since session-scoped temp tables are
	 *     invisible to DatabaseAdapter::getTables() on at least MySQL.
	 *   - No dialect branching: plain `DROP TABLE <name>` is correct on every
	 *     supported engine for both permanent and session-temporary tables
	 *     (unlike TempTableExecutor's internal cleanup, which deliberately
	 *     uses DROP TEMPORARY TABLE on MySQL/MariaDB for a narrower safety
	 *     reason — see that class's docblock). QUEL's `destroy` grammar has
	 *     no `temporary` keyword to distinguish the two anyway.
	 *
	 * Bypasses the retrieve pipeline entirely, same as CreateTableExecutor —
	 * none of semantic analysis/optimization/planning/hydration applies to a
	 * DDL statement with no rows to return.
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

				// DatabaseAdapter::execute() swallows the underlying exception
				// itself and returns null on failure rather than throwing — see
				// the same fix made to CreateTableExecutor. Failure (including
				// "no such table") must be detected via the null return.
				if ($this->connection->execute($sql) === null) {
					throw new QuelException(
						"Failed to destroy '{$name}': {$this->connection->getLastErrorMessage()}",
						'table_destruction_error'
					);
				}
			}
		}
	}
