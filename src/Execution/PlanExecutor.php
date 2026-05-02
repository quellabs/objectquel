<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	use Quellabs\ObjectQuel\Execution\Joins\JoinStrategyInterface;
	use Quellabs\ObjectQuel\Execution\Joins\CrossJoinStrategy;
	use Quellabs\ObjectQuel\Execution\Joins\LeftJoinStrategy;
	use Quellabs\ObjectQuel\Execution\Joins\InnerJoinStrategy;
	use Quellabs\ObjectQuel\Execution\Executors\TempTableExecutor;
	
	/**
	 * The PlanExecutor handles the actual execution of ExecutionStages within an ExecutionPlan,
	 * respecting the dependencies between stages and combining their results into a final output.
	 * It manages parameter passing between stages and handles error conditions during execution.
	 *
	 * TempTableStage handling:
	 *   TempTableStages are executed as a side-effect before result-producing stages run.
	 *   They do not produce result rows and are excluded from the result-combination step.
	 *   After all stages complete (success or failure), TempTableExecutor::cleanup() is
	 *   called in a finally block to DROP all temporary tables created during execution.
	 */
	class PlanExecutor {
		
		/**
		 * Entity manager instance used to execute the actual queries
		 * @var QueryExecutor
		 */
		private QueryExecutor $queryExecutor;
		
		/**
		 * Condition evaluator for join strategies that need to evaluate join conditions
		 * @var ConditionEvaluator
		 */
		private ConditionEvaluator $conditionEvaluator;
		
		/**
		 * Cache for join strategy instances to prevent duplicate creation
		 * @var array<string, JoinStrategyInterface>
		 */
		private array $joinStrategyCache = [];
		
		/**
		 * Executor responsible for materialising external-source subqueries as temp tables.
		 * Created lazily on first use and reused for the lifetime of this PlanExecutor.
		 * @var TempTableExecutor|null
		 */
		private ?TempTableExecutor $tempTableExecutor = null;
		
		/**
		 * Create a new plan executor
		 * @param QueryExecutor $queryExecutor The entity manager to use for execution
		 * @param ConditionEvaluator $conditionEvaluator The evaluator for conditions
		 */
		public function __construct(QueryExecutor $queryExecutor, ConditionEvaluator $conditionEvaluator) {
			$this->queryExecutor = $queryExecutor;
			$this->conditionEvaluator = $conditionEvaluator;
		}
		
		/**
		 * Returns the EntityStore object
		 * @return EntityStore
		 */
		public function getEntityStore(): EntityStore {
			return $this->queryExecutor->getEntityManager()->getEntityStore();
		}
		
		/**
		 * Execute a complete execution plan
		 * @param ExecutionPlan $plan The plan containing stages to execute
		 * @return array<string|int, mixed> Results from executing the plan
		 * @throws QuelException When any stage execution fails
		 */
		public function execute(ExecutionPlan $plan): array {
			// Get stages in execution order (respecting dependencies)
			$stagesInOrder = $plan->getStagesInOrder();
			
			// Check whether any TempTableStages are present. If so, we cannot use the
			// single-stage fast path, and we must run cleanup in a finally block.
			$hasTempTableStages = false;
			
			foreach ($stagesInOrder as $stage) {
				if ($stage instanceof TempTableStage) {
					$hasTempTableStages = true;
					break;
				}
			}
			
			// Optimization: If there's only one stage and no temp tables, perform a
			// simple query execution. This avoids unnecessary overhead of the multi-stage
			// execution process.
			if (!$hasTempTableStages && count($stagesInOrder) === 1) {
				return $this->queryExecutor->executeStage($stagesInOrder[0], $stagesInOrder[0]->getStaticParams());
			}
			
			// Multi-stage execution: execute each stage in the correct order and combine the results
			$intermediateResults = [];
			
			try {
				// Execute each stage sequentially, maintaining dependency order
				foreach ($stagesInOrder as $stage) {
					if ($stage instanceof TempTableStage) {
						// Materialise the inner query into a temp table before the outer
						// database stage runs. This mutates the stage's AstRangeDatabase
						// so QuelToSQL emits a plain table reference in subsequent stages.
						// TempTableStages contribute no rows to intermediate results.
						$this->getTempTableExecutor()->execute(
							$stage,
							$this->buildInnerQueryRunner(),
							$stage->getStaticParams()
						);
					} else {
						try {
							$intermediateResults[$stage->getName()] = $this->executeStage($stage);
						} catch (QuelException $e) {
							// Wrap any execution errors with stage context information
							throw new QuelException("Stage '{$stage->getName()}' failed: {$e->getMessage()}", 0, $e);
						}
					}
				}
				
				// Optimisation: skip the join machinery when only one result-producing stage ran
				if (count($intermediateResults) === 1) {
					return reset($intermediateResults);
				}
				
				// Combine all intermediate results into a single final result
				return $this->combineResults($plan, $intermediateResults);
			} finally {
				// Always clean up temp tables, whether execution succeeded or failed.
				if ($hasTempTableStages) {
					$this->getTempTableExecutor()->cleanup();
				}
			}
		}
		
		/**
		 * Builds the callable that TempTableExecutor uses to run an inner query.
		 *
		 * The callable receives an AstRetrieve and a params array, re-decomposes the
		 * inner query into its own ExecutionPlan, and executes it through a fresh
		 * PlanExecutor. Using a fresh PlanExecutor ensures that inner temp tables
		 * get their own independent cleanup scope and do not interfere with the outer
		 * execution's finally block.
		 *
		 * @return callable(AstRetrieve, array<string|int, mixed>): array<string|int, mixed>
		 */
		private function buildInnerQueryRunner(): callable {
			return function (AstRetrieve $innerQuery, array $params): array {
				// Decompose and execute the inner query as a self-contained plan.
				// This reuses the full pipeline: QueryDecomposer → ExecutionPlan →
				// PlanExecutor → DatabaseQueryExecutor / JsonQueryExecutor.
				$decomposer = new QueryDecomposer();
				$innerPlan = $decomposer->buildExecutionPlan($innerQuery, $params);
				
				// Fresh PlanExecutor so inner temp tables have their own cleanup scope
				$innerPlanExecutor = new self($this->queryExecutor, $this->conditionEvaluator);
				return $innerPlanExecutor->execute($innerPlan);
			};
		}
		
		/**
		 * Returns the TempTableExecutor instance, creating it lazily on first use.
		 * @return TempTableExecutor
		 */
		private function getTempTableExecutor(): TempTableExecutor {
			if ($this->tempTableExecutor === null) {
				$this->tempTableExecutor = new TempTableExecutor(
					$this->queryExecutor->getConnection()
				);
			}
			
			return $this->tempTableExecutor;
		}
		
		/**
		 * Creates a join strategy instance for the specified join type.
		 * Each join strategy implements JoinStrategyInterface and handles a specific type of join operation.
		 * Uses caching to prevent duplicate creation of strategy instances.
		 * @param string $joinType The type of join strategy to create
		 * @return JoinStrategyInterface The join strategy instance
		 * @throws QuelException When join type is unsupported
		 */
		private function createJoinStrategy(string $joinType): JoinStrategyInterface {
			// Check if strategy is already cached
			if (isset($this->joinStrategyCache[$joinType])) {
				return $this->joinStrategyCache[$joinType];
			}
			
			// Create and cache the strategy
			$strategy = match ($joinType) {
				'cross' => new CrossJoinStrategy(),                          // Cartesian product join
				'left' => new LeftJoinStrategy($this->conditionEvaluator),   // Left outer join
				'inner' => new InnerJoinStrategy($this->conditionEvaluator), // Inner join
				default => throw new QuelException("Unsupported join type: {$joinType}")
			};
			
			$this->joinStrategyCache[$joinType] = $strategy;
			return $strategy;
		}
		
		/**
		 * Process an individual stage with dependencies
		 * @param ExecutionStageInterface $stage The stage to execute
		 * @return array<string|int, mixed> The result of this stage's execution
		 * @throws QuelException When dependencies cannot be satisfied or execution fails
		 */
		private function executeStage(ExecutionStageInterface $stage): array {
			// Execute the query with static parameters defined in the stage
			$result = $this->queryExecutor->executeStage($stage, $stage->getStaticParams());
			
			// Apply post-processing if the stage has a result processor defined
			// This allows for custom transformations or filtering after query execution
			if ($result && $stage->hasResultProcessor()) {
				// Fetch the results processor to modify the result
				$processor = $stage->getResultProcessor();
				
				// Execute the processor function, passing the result by reference for modification
				$processor($result);
			}
			
			return $result;
		}
		
		/**
		 * This method performs the complex task of joining results from different stages
		 * based on their join types and conditions. It starts with the main stage result
		 * and progressively joins other stage results to build the final combined result.
		 * @param ExecutionPlan $plan The execution plan with stage information
		 * @param array<string, array<string|int, mixed>> $intermediateResults Results from all stages, indexed by stage name
		 * @return array<string|int, mixed> The combined result after performing all necessary joins
		 * @throws QuelException
		 */
		private function combineResults(ExecutionPlan $plan, array $intermediateResults): array {
			// Get the main stage name from the plan - this serves as the base for all joins
			$mainStageName = $plan->getMainStageName();
			
			// Validate that the main stage result exists
			// If no main result, return empty array (no data to join against)
			if (!isset($intermediateResults[$mainStageName])) {
				return [];
			}
			
			// Start with the main result as our base for all subsequent joins
			$combinedResult = $intermediateResults[$mainStageName];
			
			// Get all stages from the plan to access their join conditions and join types
			$allStages = $plan->getStagesInOrder();
			
			// Iterate through all stage results to perform joins
			foreach ($intermediateResults as $stageName => $stageResult) {
				// Skip the main result itself - it's already our base
				if ($stageName === $mainStageName) {
					continue;
				}
				
				// Find the stage object to get join configuration
				$stage = $this->findStageByName($allStages, $stageName);
				
				// Skip stages that can't be found (shouldn't happen in normal operation)
				if ($stage === null) {
					continue;
				}
				
				// Skip everything that's not an ExecutionStage.
				// TempTableStages are not in $intermediateResults so this is defensive,
				// but it also keeps PhpStan happy.
				if (!($stage instanceof ExecutionStage)) {
					continue;
				}
				
				// Perform the join using the appropriate strategy based on the stage's join type
				$combinedResult = $this->performJoin(
					$combinedResult,             // Current combined result (left side of join)
					$stageResult,                // Current stage result (right side of join)
					$stage->getJoinType(),       // Type of join to perform (inner, left, cross, etc.)
					$stage->getJoinConditions()  // Conditions for the join operation
				);
			}
			
			return $combinedResult;
		}
		
		/**
		 * Perform a join using the appropriate strategy
		 * @param array<string|int, mixed> $leftResult The left result set (typically the accumulated result)
		 * @param array<string|int, mixed> $rightResult The right result set (current stage result to join)
		 * @param string $joinType The type of join to perform (cross, left, inner)
		 * @param mixed|null $joinConditions The join conditions (if applicable)
		 * @return array<string|int, mixed> The joined result set
		 * @throws QuelException When join type is unsupported or join fails
		 */
		private function performJoin(array $leftResult, array $rightResult, string $joinType, mixed $joinConditions = null): array {
			try {
				// Create the join strategy on-demand for the specific join type
				$strategy = $this->createJoinStrategy($joinType);
				
				// Execute the join using the selected strategy
				return $strategy->join($leftResult, $rightResult, $joinConditions);
			} catch (QuelException $e) {
				// Wrap any join errors with additional context
				throw new QuelException("Join failed ({$joinType}): {$e->getMessage()}", 0, $e);
			}
		}
		
		/**
		 * Find a stage by name from the stages array
		 * @param array<int, ExecutionStageInterface> $stages Array of ExecutionStage objects
		 * @param string $stageName Name of the stage to find
		 * @return ExecutionStageInterface|null The found stage or null if not found
		 */
		private function findStageByName(array $stages, string $stageName): ?ExecutionStageInterface {
			// Linear search through stages array
			foreach ($stages as $stage) {
				if ($stage->getName() === $stageName) {
					return $stage;
				}
			}
			
			// Return null if stage not found
			return null;
		}
	}