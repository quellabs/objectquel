<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	use Quellabs\ObjectQuel\QueryManagement\ConditionEvaluator;
	use Quellabs\ObjectQuel\QueryManagement\QueryExecutor;
	
	/**
	 * Responsible for executing individual stages of a decomposed query plan
	 *
	 * The PlanExecutor handles the actual execution of ExecutionStages within an ExecutionPlan,
	 * respecting the dependencies between stages and combining their results into a final output.
	 * It manages parameter passing between stages and handles error conditions during execution.
	 */
	class PlanExecutor {
		
		/**
		 * Entity manager instance used to execute the actual queries
		 * This is the underlying engine that processes individual query strings
		 * @var QueryExecutor
		 */
		private QueryExecutor $queryExecutor;
		
		/**
		 * Condition evaluator used to evaluate join conditions
		 * @var ConditionEvaluator
		 */
		private ConditionEvaluator $conditionEvaluator;
		
		/**
		 * Create a new stage executor
		 * @param QueryExecutor $queryExecutor The entity manager to use for execution
		 * @param ConditionEvaluator $conditionEvaluator The evaluator for conditions
		 */
		public function __construct(QueryExecutor $queryExecutor, ConditionEvaluator $conditionEvaluator) {
			$this->queryExecutor = $queryExecutor;
			$this->conditionEvaluator = $conditionEvaluator;
		}
		
		/**
		 * Process an individual stage with dependencies
		 * @param ExecutionStage $stage The stage to execute
		 * @return array The result of this stage's execution
		 * @throws QuelException When dependencies cannot be satisfied or execution fails
		 */
		private function executeStage(ExecutionStage $stage): array {
			// Execute the query with combined parameters
			$result = $this->queryExecutor->executeStage($stage, $stage->getStaticParams());
			
			// Apply post-processing if specified
			if ($result && $stage->hasResultProcessor()) {
				$processor = $stage->getResultProcessor();
				$processor($result);
			}
			
			return $result;
		}
		
		/**
		 * Combines results from multiple stages into a single result object
		 * This method joins the results of all stages with the main stage's result,
		 * creating a consolidated result set that represents the complete query output.
		 * @param ExecutionPlan $plan The execution plan with stage information
		 * @param array $intermediateResults Results from all stages, indexed by stage name
		 * @return array The combined result after performing all necessary joins
		 * @throws QuelException
		 */
		private function combineResults(ExecutionPlan $plan, array $intermediateResults): array {
			// Get the main stage name from the plan
			$mainStageName = $plan->getMainStageName();
			
			// Find the main result
			if (!isset($intermediateResults[$mainStageName])) {
				return [];
			}
			
			// Start with the main result as our base
			$combinedResult = $intermediateResults[$mainStageName];
			
			// Get all stages from the plan to access their join conditions and join types
			$allStages = $plan->getStagesInOrder();
			
			foreach ($intermediateResults as $stageName => $stageResult) {
				// Skip the main result itself
				if ($stageName === $mainStageName) {
					continue;
				}
				
				// Get the stage object to access join conditions and type
				$stageFiltered = array_values(array_filter($allStages, function ($e) use ($stageName) {
					return $e->getName() === $stageName;
				}));
				
				if (empty($stageFiltered)) {
					continue;
				}
				
				// Get join conditions and join type
				$stage = $stageFiltered[0];
				$joinConditions = $stage->getJoinConditions();
				
				// Perform the appropriate type of join
				$combinedResult = match ($stage->getJoinType()) {
					'cross' => $this->performCrossJoin($combinedResult, $stageResult),
					'inner' => $this->performInnerJoin($combinedResult, $stageResult, $joinConditions),
					default => $this->performLeftJoin($combinedResult, $stageResult, $joinConditions),
				};
			}
			
			// Return the joined result
			return $combinedResult;
		}
		
		/**
		 * Performs a cross join (Cartesian product) between two result sets
		 *
		 * @param array $leftResult The left result set
		 * @param array $rightResult The right result set
		 * @return array The combined result set
		 */
		private function performCrossJoin(array $leftResult, array $rightResult): array {
			$combined = [];
			
			// For each row in the left result, combine with every row in the right result
			foreach ($leftResult as $leftRow) {
				foreach ($rightResult as $rightRow) {
					// Merge the left and right rows
					$combined[] = array_merge($leftRow, $rightRow);
				}
			}
			
			// If left result is empty but right has results, return right result
			if (empty($combined) && !empty($rightResult)) {
				return $rightResult;
			}
			
			return $combined;
		}
		
		/**
		 * Performs a left join between two result sets based on join conditions
		 *
		 * @param array $leftResult The left result set
		 * @param array $rightResult The right result set
		 * @param AstInterface $joinConditions The join conditions
		 * @return array The joined result set
		 * @throws QuelException
		 */
		private function performLeftJoin(array $leftResult, array $rightResult, AstInterface $joinConditions): array {
			$combined = [];
			
			// For each row in the left result
			foreach ($leftResult as $leftRow) {
				$matched = false;
				
				// Check against each row in the right result
				foreach ($rightResult as $rightRow) {
					// Temporarily combine the rows to evaluate join condition
					$combinedRow = array_merge($leftRow, $rightRow);
					
					// Evaluate join condition against combined row using the ConditionEvaluator
					if ($this->conditionEvaluator->evaluate($joinConditions, $combinedRow)) {
						// Add the combined row to the result
						$combined[] = $combinedRow;
						$matched = true;
					}
				}
				
				// If no match found, keep the left row with nulls for right columns
				if (!$matched) {
					// Create null placeholders for all right columns
					$nullRight = array_fill_keys(array_keys(reset($rightResult) ?: []), null);
					$combined[] = array_merge($leftRow, $nullRight);
				}
			}
			
			return $combined;
		}
		
		/**
		 * Performs an inner join between two result sets based on join conditions
		 * @param array $leftResult The left result set
		 * @param array $rightResult The right result set
		 * @param AstInterface $joinConditions The join conditions
		 * @return array The joined result set
		 * @throws QuelException
		 */
		private function performInnerJoin(array $leftResult, array $rightResult, AstInterface $joinConditions): array {
			$combined = [];
			
			foreach ($leftResult as $leftRow) {
				// Check against each row in the right result
				foreach ($rightResult as $rightRow) {
					// Temporarily combine the rows to evaluate join condition
					$combinedRow = array_merge($leftRow, $rightRow);
					
					// Evaluate join condition against combined row using the ConditionEvaluator
					if ($this->conditionEvaluator->evaluate($joinConditions, $combinedRow)) {
						// Add the combined row to the result
						$combined[] = $combinedRow;
					}
				}
			}
			
			return $combined;
		}
		
		/**
		 * Execute a complete execution plan
		 * @param ExecutionPlan $plan The plan containing stages to execute
		 * @return array Results from executing the plan
		 * @throws QuelException When any stage execution fails
		 */
		public function execute(ExecutionPlan $plan): array {
			// Get stages in execution order (respecting dependencies)
			$stagesInOrder = $plan->getStagesInOrder();
			
			// If there's only one stage, perform a simple query execution
			if (count($stagesInOrder) === 1) {
				return $this->queryExecutor->executeStage($stagesInOrder[0], $stagesInOrder[0]->getStaticParams());
			}
			
			// Otherwise, execute each stage in the correct order and combine the results
			$intermediateResults = [];
			
			foreach ($stagesInOrder as $stage) {
				try {
					$intermediateResults[$stage->getName()] = $this->executeStage($stage);
				} catch (QuelException $e) {
					throw new QuelException("Stage '{$stage->getName()}' failed: {$e->getMessage()}");
				}
			}
			
			return $this->combineResults($plan, $intermediateResults);
		}
	}