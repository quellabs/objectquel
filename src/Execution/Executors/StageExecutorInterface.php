<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Execution\ExecutionContext;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Planner\ExecutionStageInterface;

	/**
	 * Implemented by every executor PlanExecutor dispatches a planner-produced
	 * ExecutionStageInterface to: database/JSON retrieve stages, constant-only
	 * stages, and temp-table materialization stages.
	 *
	 * ConstantStage and TempTableStage need their own richer stage type
	 * internally (getProjections(), getInnerPlan()); ConstantRetrieveExecutor/
	 * TempTableExecutor still declare the plain ExecutionStageInterface param
	 * and narrow it internally, the same way JsonRetrieveExecutor narrows
	 * AstRange to AstRangeJsonSource.
	 *
	 * Always returns the stage's result rows — empty for TempTableExecutor,
	 * whose job is the temp-table creation side effect, not row production.
	 */
	interface StageExecutorInterface {

		/**
		 * Executes the stage and returns its result rows.
		 * @param ExecutionStageInterface $stage The stage to execute
		 * @param ExecutionContext $context Bound query parameters and, for
		 *        TempTableExecutor, the callable that runs the stage's inner plan
		 * @return list<array<string, mixed>>
		 * @throws QuelException On compile or execution failure
		 */
		public function execute(ExecutionStageInterface $stage, ExecutionContext $context): array;
	}
