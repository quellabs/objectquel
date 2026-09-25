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
	use Quellabs\ObjectQuel\Serialization\Serializers\Serializer;

	/**
	 * Executes the `name(args)` statement. The catalog says whether the routine is a procedure (run, yields null)
	 * or a function (selected, yields one row converted to its return type).
	 */
	class CallExecutor {

		private DatabaseAdapter $connection;
		private EntityStore $entityStore;
		private PlatformCapabilitiesInterface $platform;
		private Serializer $serializer;
		private ?QuelToSQLCall $compiler = null;

		/**
		 * Creates an executor for stored routine calls.
		 * @param DatabaseAdapter $connection Connection the call runs on
		 * @param EntityStore $entityStore Needed by the SQL builder for literals
		 * @param PlatformCapabilitiesInterface $platform Connected engine
		 * @return void
		 */
		public function __construct(DatabaseAdapter $connection, EntityStore $entityStore, PlatformCapabilitiesInterface $platform) {
			$this->connection = $connection;
			$this->entityStore = $entityStore;
			$this->platform = $platform;
			$this->serializer = new Serializer($entityStore);
		}

		/**
		 * Returns the call compiler. Built on first use, so SQL Server reads the routine schema only when a statement needs compiling.
		 * @return QuelToSQLCall
		 */
		private function compiler(): QuelToSQLCall {
			return $this->compiler ??= new QuelToSQLCall($this->entityStore, $this->platform, $this->connection->getRoutineSchema());
		}

		/**
		 * Looks up and executes a routine, converting a function's result.
		 * @param AstCall $statement The call
		 * @param ExecutionContext $context Bound parameters
		 * @return QuelResult|null The function's value as a one-row result, or null for a procedure
		 * @throws QuelException When the routine is missing or ambiguous, or the call fails
		 * @throws SemanticException When an argument isn't a literal or a parameter
		 */
		public function execute(AstCall $statement, ExecutionContext $context): ?QuelResult {
			$name = $statement->getCall()->getName();
			$parameters = $context->getParameters();
			$signature = $this->connection->getRoutineSignature($name);
			$sql = $this->compiler()->convertToSQL($statement, $signature->isProcedure, $parameters);
			$result = $this->connection->execute($sql, $parameters);

			if ($result === null) {
				throw new QuelException("Failed to call routine '{$name}': {$this->connection->getLastErrorMessage()}", 'routine_call_error');
			}

			if ($signature->isProcedure) {
				// Frees the connection; MySQL leaves a status result after CALL
				$result->closeCursor();
				return null;
			}

			$row = $result->fetch('num');
			$result->closeCursor();

			if (!is_array($row) || !array_key_exists(0, $row)) {
				throw new QuelException("Failed to call routine '{$name}': the function returned no row.", 'routine_call_error');
			}

			return QuelResult::fromRow([$name => $this->convert($row[0], $signature->returnType)]);
		}

		/**
		 * @param mixed $value The function's raw value
		 * @param string|null $returnType Abstract column type, or null when unknown
		 * @return mixed The value as its return type's PHP type; unchanged when the type is unknown or the value is null
		 */
		private function convert(mixed $value, ?string $returnType): mixed {
			if ($value === null || $returnType === null) {
				return $value;
			}

			return $this->serializer->normalizeValueOfType($returnType, $value);
		}
	}
