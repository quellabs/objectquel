<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Execution\ExecutionContext;
	use Quellabs\ObjectQuel\Execution\Helpers\ConditionEvaluator;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Planner\ConstantStage;
	use Quellabs\ObjectQuel\Planner\ExecutionStageInterface;

	/**
	 * Evaluates a ConstantStage by computing each projection expression against an
	 * empty row and returning a single synthetic result row.
	 *
	 * A constant-only query has no ranges and therefore no data source to fetch from.
	 * Every expression in the projection must be a literal, parameter reference, or
	 * pure arithmetic — nothing that requires a field lookup. ConditionEvaluator
	 * already handles all of these node types, so this executor simply drives it
	 * once per alias and assembles the result.
	 */
	class ConstantRetrieveExecutor implements StageExecutorInterface {

		/**
		 * Evaluates all projections in the stage and returns a one-row result.
		 * $stage is narrowed to ConstantStage via assert() — PlanExecutor only
		 * ever dispatches one here.
		 * @param ExecutionStageInterface $stage The constant stage to evaluate
		 * @param ExecutionContext $context Bound query parameters
		 * @return list<array<string, mixed>> Always a single-element array containing the evaluated row
		 * @throws QuelException When an unhandled AST node is encountered during evaluation
		 */
		public function execute(ExecutionStageInterface $stage, ExecutionContext $context): array {
			assert($stage instanceof ConstantStage);

			$row = [];

			foreach ($stage->getProjections() as $alias) {
				$row[$alias->getName()] = ConditionEvaluator::evaluate(
					$alias->getExpression(),
					[],   // no dataset (no rows to aggregate over)
					[],   // no field values (no ranges)
					$context->getParameters()
				);
			}

			return [$row];
		}
	}
