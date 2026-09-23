<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Execution\ExecutionContext;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCall;
	use Quellabs\ObjectQuel\ObjectQuel\QuelResult;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLCall;

	/**
	 * Executes `call name(args)`. Calls are untyped, so the catalog says whether the routine
	 * is a procedure (run, yields null) or a function (selected, yields one row).
	 */
	class CallExecutor {

		private DatabaseAdapter $connection;
		private EntityStore $entityStore;
		private PlatformCapabilitiesInterface $platform;
		private ?QuelToSQLCall $compiler = null;

		/**
		 * @param DatabaseAdapter $connection Connection the call runs on
		 * @param EntityStore $entityStore Needed by the SQL builder for literals
		 * @param PlatformCapabilitiesInterface $platform Connected engine
		 */
		public function __construct(DatabaseAdapter $connection, EntityStore $entityStore, PlatformCapabilitiesInterface $platform) {
			$this->connection = $connection;
			$this->entityStore = $entityStore;
			$this->platform = $platform;
		}

		/**
		 * Returns the call compiler. Built on first use, so SQL Server reads the routine schema only when a statement needs compiling.
		 * @return QuelToSQLCall
		 */
		private function compiler(): QuelToSQLCall {
			return $this->compiler ??= new QuelToSQLCall($this->entityStore, $this->platform, $this->connection->getRoutineSchema());
		}

		/**
		 * @param AstCall $statement The call
		 * @param ExecutionContext $context Bound parameters
		 * @return QuelResult|null The function's value as a one-row result, or null for a procedure
		 * @throws QuelException When the routine is missing or ambiguous, or the call fails
		 * @throws SemanticException When an argument isn't a literal or a parameter
		 */
		public function execute(AstCall $statement, ExecutionContext $context): ?QuelResult {
			$name = $statement->getCall()->getName();
			$parameters = $context->getParameters();
			$isProcedure = $this->isProcedure($statement);
			$sql = $this->compiler()->convertToSQL($statement, $isProcedure, $parameters);
			$result = $this->connection->execute($sql, $parameters);

			if ($result === null) {
				throw new QuelException("Failed to call routine '{$name}': {$this->connection->getLastErrorMessage()}", 'routine_call_error');
			}

			if ($isProcedure) {
				// Frees the connection; MySQL leaves a status result after CALL
				$result->closeCursor();
				return null;
			}

			$row = $result->fetch('num');
			$result->closeCursor();

			if (!is_array($row) || !array_key_exists(0, $row)) {
				throw new QuelException("Failed to call routine '{$name}': the function returned no row.", 'routine_call_error');
			}

			return QuelResult::fromRow([$name => $row[0]]);
		}

		/**
		 * @param AstCall $statement The call
		 * @return bool True when the routine is a procedure, false when it's a function
		 * @throws QuelException When no routine or more than one kind of routine has the name, or the lookup fails
		 */
		private function isProcedure(AstCall $statement): bool {
			$name = $statement->getCall()->getName();
			[$sql, $parameters] = $this->compiler()->kindQuery($statement);
			$result = $this->connection->execute($sql, $parameters);

			if ($result === null) {
				throw new QuelException("Failed to look up routine '{$name}': {$this->connection->getLastErrorMessage()}", 'routine_call_error');
			}

			$kinds = [];

			foreach ($result->fetchAll('assoc') as $row) {
				$kinds[(int)$row['is_procedure']] = true;
			}

			if ($kinds === []) {
				throw new QuelException("Can't call '{$name}': no routine by that name exists.", 'routine_call_error');
			}

			if (count($kinds) > 1) {
				throw new QuelException("Can't call '{$name}': both a function and a procedure have that name.", 'routine_call_error');
			}

			return isset($kinds[1]);
		}
	}
