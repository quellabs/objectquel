<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Execution\ExecutionContext;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstShowIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstStatement;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLIndexVisibility;

	/**
	 * Executes an AstShowIndex statement: compiles it via
	 * QuelToSQLIndexVisibility and runs the resulting single DDL statement
	 * against the connection. Mirrors CreateIndexExecutor/DestroyIndexExecutor.
	 * The reverse operation is HideIndexExecutor.
	 *
	 * Bypasses the `retrieve` pipeline entirely — none of it applies to a DDL
	 * statement with no rows to return.
	 */
	class ShowIndexExecutor implements DdlStatementExecutorInterface {

		private QuelToSQLIndexVisibility $compiler;

		private DdlRunner $ddlRunner;

		/**
		 * ShowIndexExecutor constructor
		 * @param DatabaseAdapter $connection
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(DatabaseAdapter $connection, PlatformCapabilitiesInterface $platform) {
			$this->compiler = new QuelToSQLIndexVisibility($platform);
			$this->ddlRunner = new DdlRunner($connection);
		}

		/**
		 * Compile and execute a `show Name on Table` statement.
		 * @param AstStatement $statement
		 * @param ExecutionContext $context
		 * @return void
		 * @throws QuelException On DDL failure, or if the connected engine doesn't support invisible indexes
		 */
		public function execute(AstStatement $statement, ExecutionContext $context): void {
			assert($statement instanceof AstShowIndex);

			$this->ddlRunner->run(
				[$this->compiler->convertShowToSQL($statement)],
				"Failed to show index '{$statement->getIndexName()}' on '{$statement->getTableName()}'",
				'index_visibility_error'
			);
		}
	}
