<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Execution\ExecutionContext;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstStatement;
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
	class DeleteExecutor implements WriteVerbExecutorInterface {

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
		 * @param AstStatement $statement
		 * @param ExecutionContext $context
		 * @return QuelResult
		 * @throws QuelException On compile or execution failure
		 */
		public function execute(AstStatement $statement, ExecutionContext $context): QuelResult {
			assert($statement instanceof AstDelete);
			$parameters = $context->getParameters();

			// compileSql() takes $parameters by reference — see
			// ReplaceExecutor::execute()'s equivalent comment. convertToSQL()
			// doesn't currently mutate $parameters for `delete` (no
			// assignments, no version columns), but this keeps the same
			// compile-then-execute wiring correct across all three write
			// verbs rather than delete being the odd one out by coincidence.
			$sql = $this->compileSql($statement, $parameters);

			// execute() swallows the exception and returns null on failure
			// rather than throwing — a try/catch here would never fire.
			$rs = $this->connection->execute($sql, $parameters);

			if ($rs === null) {
				throw new QuelException(
					"Failed to delete via range '{$statement->getRange()->getName()}': {$this->connection->getLastErrorMessage()}",
					'delete_error'
				);
			}

			return QuelResult::fromWriteStatement($rs->rowCount());
		}

		/**
		 * Compiles a `delete <range> where ...` statement to SQL, used by
		 * execute() before running it.
		 * @param AstDelete $statement
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return string
		 * @throws QuelException|SemanticException On compile failure
		 */
		public function compileSql(AstDelete $statement, array &$parameters): string {
			return $this->compiler->convertToSQL($statement, $parameters);
		}
	}
