<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstHideIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstShowIndex;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLIndexVisibility;

	/**
	 * Executes an AstHideIndex/AstShowIndex statement: compiles it via
	 * QuelToSQLIndexVisibility and runs the resulting single DDL statement
	 * against the connection. Mirrors CreateIndexExecutor/DestroyIndexExecutor,
	 * one class covering both statements for the same reason
	 * QuelToSQLIndexVisibility does (see that class's docblock).
	 *
	 * Bypasses the `retrieve` pipeline entirely — none of it applies to a DDL
	 * statement with no rows to return.
	 */
	class IndexVisibilityExecutor {

		private QuelToSQLIndexVisibility $compiler;

		private DdlRunner $ddlRunner;

		public function __construct(DatabaseAdapter $connection, PlatformCapabilitiesInterface $platform) {
			$this->compiler = new QuelToSQLIndexVisibility($platform);
			$this->ddlRunner = new DdlRunner($connection);
		}

		/**
		 * Compile and execute a `hide Name on Table` statement.
		 * @throws QuelException On DDL failure, or if the connected engine doesn't support invisible indexes
		 */
		public function executeHide(AstHideIndex $statement): void {
			$this->ddlRunner->run(
				[$this->compiler->convertHideToSQL($statement)],
				"Failed to hide index '{$statement->getIndexName()}' on '{$statement->getTableName()}'",
				'index_visibility_error'
			);
		}

		/**
		 * Compile and execute a `show Name on Table` statement.
		 * @throws QuelException On DDL failure, or if the connected engine doesn't support invisible indexes
		 */
		public function executeShow(AstShowIndex $statement): void {
			$this->ddlRunner->run(
				[$this->compiler->convertShowToSQL($statement)],
				"Failed to show index '{$statement->getIndexName()}' on '{$statement->getTableName()}'",
				'index_visibility_error'
			);
		}
	}
