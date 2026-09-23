<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Execution\ExecutionContext;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyRoutine;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstStatement;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLDestroyRoutine;

	/**
	 * Executes `destroy function name [if exists]` through QuelToSQLDestroyRoutine.
	 */
	class DestroyRoutineExecutor implements DdlStatementExecutorInterface {

		private DatabaseAdapter $connection;
		private PlatformCapabilitiesInterface $platform;
		private QuelToSQLDestroyRoutine $compiler;
		private DdlRunner $ddlRunner;

		/**
		 * @param DatabaseAdapter $connection Connection the DROP runs on
		 * @param PlatformCapabilitiesInterface $platform Connected engine
		 */
		public function __construct(DatabaseAdapter $connection, PlatformCapabilitiesInterface $platform) {
			$this->connection = $connection;
			$this->platform = $platform;
			$this->compiler = new QuelToSQLDestroyRoutine($platform);
			$this->ddlRunner = new DdlRunner($connection);
		}

		/**
		 * Drops the routine; a missing one is an error unless `if exists` is given.
		 * @param AstStatement $statement
		 * @param ExecutionContext $context
		 * @return void
		 * @throws QuelException When the routine is missing or the DROP fails
		 */
		public function execute(AstStatement $statement, ExecutionContext $context): void {
			assert($statement instanceof AstDestroyRoutine);

			$statements = $this->compiler->convertToSQL($statement);

			// MySQL's DROP runs with IF EXISTS on both kinds, so a missing routine is caught here.
			if (
				in_array($this->platform->getDatabaseType(), ['mysql', 'mariadb'], true) &&
				!$statement->isIfExists() &&
				!$this->mysqlRoutineExists($statement->getName())
			) {
				throw new QuelException("Failed to destroy routine '{$statement->getName()}': it doesn't exist", 'routine_destruction_error');
			}

			$this->ddlRunner->run($statements, "Failed to destroy routine '{$statement->getName()}'", 'routine_destruction_error');
		}

		/**
		 * @param string $name Routine name; matched case-insensitively, like MySQL routine names
		 * @return bool True when a function or procedure by this name exists in the current database
		 * @throws QuelException When the lookup fails
		 */
		private function mysqlRoutineExists(string $name): bool {
			$result = $this->connection->execute(
				'SELECT COUNT(*) AS routine_count FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = :name',
				['name' => $name]
			);

			if ($result === null) {
				throw new QuelException("Failed to look up routine '{$name}': {$this->connection->getLastErrorMessage()}", 'routine_destruction_error');
			}

			$row = $result->fetch('assoc');
			return is_array($row) && is_numeric($row['routine_count']) && $row['routine_count'] > 0;
		}
	}
