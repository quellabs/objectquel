<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Execution\ExecutionContext;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstStatement;

	/**
	 * Implemented by every DDL executor QueryExecutor dispatches a parsed
	 * AstStatement to directly: create/alter/destroy table, create/destroy
	 * index, and hide/show index.
	 *
	 * A DDL statement has no rows, and no affected-row count, to report — it
	 * either fully succeeds or throws — so execute() returns void, unlike
	 * WriteVerbExecutorInterface's QuelResult.
	 */
	interface DdlStatementExecutorInterface {

		/**
		 * Compiles and executes the DDL statement against the connection.
		 * @param AstStatement $statement The parsed DDL statement to execute
		 * @param ExecutionContext $context
		 * @return void
		 * @throws QuelException On compile or execution failure
		 */
		public function execute(AstStatement $statement, ExecutionContext $context): void;
	}
