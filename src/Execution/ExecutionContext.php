<?php

	namespace Quellabs\ObjectQuel\Execution;

	use Quellabs\ObjectQuel\Planner\ExecutionPlan;

	/**
	 * Execution-time context passed to every statement and stage executor
	 * (see DdlStatementExecutorInterface/WriteVerbExecutorInterface/
	 * StageExecutorInterface in Execution\Executors).
	 *
	 * Carries the query's bound parameters, plus — only for
	 * TempTableExecutor — the callable that re-enters PlanExecutor::execute()
	 * to run a temp-table stage's inner plan.
	 */
	final class ExecutionContext {

		/** @var array<string, mixed> */
		private array $parameters;

		/** @var (\Closure(ExecutionPlan): list<array<string, mixed>>)|null */
		private ?\Closure $stageRunner;

		/**
		 * Builds a context carrying the given bound parameters and, optionally, a stage runner.
		 * @param array<string, mixed> $parameters
		 * @param (\Closure(ExecutionPlan): list<array<string, mixed>>)|null $stageRunner
		 */
		public function __construct(array $parameters, ?\Closure $stageRunner = null) {
			$this->parameters = $parameters;
			$this->stageRunner = $stageRunner;
		}

		/**
		 * Returns the query's bound parameters.
		 * @return array<string, mixed>
		 */
		public function getParameters(): array {
			return $this->parameters;
		}

		/**
		 * Returns the stage runner, or null when none was provided (every
		 * executor except TempTableExecutor).
		 * @return (\Closure(ExecutionPlan): list<array<string, mixed>>)|null
		 */
		public function getStageRunner(): ?\Closure {
			return $this->stageRunner;
		}

		/**
		 * Returns the stage runner, throwing when none was provided. Used by
		 * TempTableExecutor, the only executor for which a missing runner is
		 * a programming error rather than an expected case.
		 * @return \Closure(ExecutionPlan): list<array<string, mixed>>
		 */
		public function getStageRunnerOrFail(): \Closure {
			if ($this->stageRunner === null) {
				throw new \LogicException('ExecutionContext has no stage runner');
			}

			return $this->stageRunner;
		}
	}
