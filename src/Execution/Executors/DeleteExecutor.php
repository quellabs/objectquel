<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\QuelResult;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLDelete;

	/**
	 * Executes an AstDelete statement: compiles it via QuelToSQLDelete and
	 * runs the resulting DELETE directly against the connection.
	 *
	 * Bypasses the `retrieve` pipeline entirely — this is a bulk, set-based,
	 * direct-SQL statement that never goes through UnitOfWork or the identity
	 * map (see objectquel-write-verbs-design.md). No generated PK to report
	 * (unlike `append`) — a DELETE never creates one.
	 */
	class DeleteExecutor {

		private DatabaseAdapter $connection;
		private QuelToSQLDelete $compiler;

		/**
		 * DeleteExecutor constructor
		 * @param DatabaseAdapter $connection
		 * @param EntityStore $entityStore
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(DatabaseAdapter $connection, EntityStore $entityStore, PlatformCapabilitiesInterface $platform) {
			$this->connection = $connection;
			$this->compiler = new QuelToSQLDelete($entityStore, $platform);
		}

		/**
		 * Compile and execute a `delete <range> where ...` statement.
		 * @param AstDelete $statement
		 * @param array<string, mixed> $parameters
		 * @return QuelResult
		 * @throws QuelException On compile or execution failure
		 */
		public function execute(AstDelete $statement, array $parameters): QuelResult {
			$sql = $this->compiler->convertToSQL($statement, $parameters);

			// execute() swallows the exception and returns null on failure
			// rather than throwing — a try/catch here would never fire.
			$rs = $this->connection->execute($sql, $parameters);

			if ($rs === null) {
				throw new QuelException(
					"Failed to delete from '{$statement->getRange()->getEntityName()}': {$this->connection->getLastErrorMessage()}",
					'delete_error'
				);
			}

			return QuelResult::fromWriteStatement($rs->rowCount());
		}
	}
