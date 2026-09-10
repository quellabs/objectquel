<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Execution\ExecutionContext;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstHideIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstStatement;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLIndexVisibility;

	/**
	 * Executes an AstHideIndex statement: compiles it via
	 * QuelToSQLIndexVisibility and runs the resulting single DDL statement
	 * against the connection. Mirrors CreateIndexExecutor/DestroyIndexExecutor.
	 * The reverse operation is ShowIndexExecutor.
	 *
	 * Bypasses the `retrieve` pipeline entirely — none of it applies to a DDL
	 * statement with no rows to return.
	 */
	class HideIndexExecutor implements DdlStatementExecutorInterface {

		private QuelToSQLIndexVisibility $compiler;

		private DdlRunner $ddlRunner;

		/**
		 * HideIndexExecutor constructor
		 * @param DatabaseAdapter $connection
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(DatabaseAdapter $connection, PlatformCapabilitiesInterface $platform) {
			$this->compiler = new QuelToSQLIndexVisibility($platform);
			$this->ddlRunner = new DdlRunner($connection);
		}

		/**
		 * Compile and execute a `hide Name on Table` statement.
		 * @param AstStatement $statement
		 * @param ExecutionContext $context
		 * @return void
		 * @throws QuelException On DDL failure, or if the connected engine doesn't support invisible indexes
		 */
		public function execute(AstStatement $statement, ExecutionContext $context): void {
			assert($statement instanceof AstHideIndex);

			$this->ddlRunner->run(
				[$this->compiler->convertHideToSQL($statement)],
				"Failed to hide index '{$statement->getIndexName()}' on '{$statement->getTableName()}'",
				'index_visibility_error'
			);
		}
	}
