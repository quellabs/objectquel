<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Execution\ExecutionContext;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstStatement;
	use Quellabs\ObjectQuel\ObjectQuel\QuelResult;

	/**
	 * Implemented by every write-verb executor: append (including its
	 * JSON-source diversion, JsonAppendExecutor), replace, and delete.
	 *
	 * A write verb always produces a result to report — an affected-row
	 * count and, for append, a generated primary key — so execute() returns
	 * a non-nullable QuelResult, unlike DdlStatementExecutorInterface's void.
	 */
	interface WriteVerbExecutorInterface {

		/**
		 * Compiles and executes the write-verb statement against the connection.
		 * @param AstStatement $statement The parsed write-verb statement to execute
		 * @param ExecutionContext $context
		 * @return QuelResult
		 * @throws QuelException On compile or execution failure
		 */
		public function execute(AstStatement $statement, ExecutionContext $context): QuelResult;
	}
