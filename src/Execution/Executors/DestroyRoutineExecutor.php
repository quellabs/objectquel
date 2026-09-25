<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Execution\ExecutionContext;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyRoutine;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstStatement;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLDestroyRoutine;

	/**
	 * Executes `destroy function name [if exists]` through QuelToSQLDestroyRoutine.
	 */
	class DestroyRoutineExecutor implements DdlStatementExecutorInterface {

		private DatabaseAdapter $connection;
		private PlatformCapabilitiesInterface $platform;
		private ?QuelToSQLDestroyRoutine $compiler = null;
		private DdlRunner $ddlRunner;

		/**
		 * @param DatabaseAdapter $connection Connection the DROP runs on
		 * @param PlatformCapabilitiesInterface $platform Connected engine
		 */
		public function __construct(DatabaseAdapter $connection, PlatformCapabilitiesInterface $platform) {
			$this->connection = $connection;
			$this->platform = $platform;
			$this->ddlRunner = new DdlRunner($connection);
		}

		/**
		 * Returns the DROP compiler. Built on first use, so SQL Server reads the routine schema only when a statement needs compiling.
		 * @return QuelToSQLDestroyRoutine
		 */
		private function compiler(): QuelToSQLDestroyRoutine {
			return $this->compiler ??= new QuelToSQLDestroyRoutine($this->platform, $this->connection->getRoutineSchema());
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

			$statements = $this->compiler()->convertToSQL($statement);

			// MySQL's DROP runs with IF EXISTS on both kinds, so a missing routine is caught here.
			if (
				in_array($this->platform->getDatabaseType(), ['mysql', 'mariadb'], true) &&
				!$statement->isIfExists() &&
				!$this->connection->routineExists($statement->getName())
			) {
				throw new QuelException("Failed to destroy routine '{$statement->getName()}': it doesn't exist", 'routine_destruction_error');
			}

			$this->ddlRunner->run($statements, "Failed to destroy routine '{$statement->getName()}'", 'routine_destruction_error');
		}

	}
