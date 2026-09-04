<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\QuelResult;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLReplace;

	/**
	 * Executes an AstReplace statement: compiles it via QuelToSQLReplace and
	 * runs the resulting UPDATE directly against the connection.
	 *
	 * Bypasses the `retrieve` pipeline entirely — this is a bulk, set-based,
	 * direct-SQL statement that never goes through UnitOfWork or the identity
	 * map (see objectquel-write-verbs-design.md). No generated PK to report
	 * (unlike `append`) — an UPDATE never creates one.
	 */
	class ReplaceExecutor {

		private DatabaseAdapter $connection;
		private QuelToSQLReplace $compiler;

		/**
		 * ReplaceExecutor constructor
		 * @param DatabaseAdapter $connection
		 * @param EntityManager $entityManager
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(DatabaseAdapter $connection, EntityManager $entityManager, PlatformCapabilitiesInterface $platform) {
			$this->connection = $connection;
			$this->compiler = new QuelToSQLReplace(
				$entityManager->getEntityStore(),
				$platform,
				$entityManager->getUnitOfWork()->getVersionValueHandler()
			);
		}

		/**
		 * Compile and execute a `replace <range> (...) where ...` statement.
		 * @param AstReplace $statement
		 * @param array<string, mixed> $parameters
		 * @return QuelResult
		 * @throws QuelException On compile or execution failure
		 */
		public function execute(AstReplace $statement, array $parameters): QuelResult {
			$sql = $this->compiler->convertToSQL($statement, $parameters);

			// execute() swallows the exception and returns null on failure
			// rather than throwing — a try/catch here would never fire.
			$rs = $this->connection->execute($sql, $parameters);

			if ($rs === null) {
				throw new QuelException(
					"Failed to replace in '{$statement->getRange()->getEntityName()}': {$this->connection->getLastErrorMessage()}",
					'replace_error'
				);
			}

			return QuelResult::fromWriteStatement($rs->rowCount());
		}
	}
