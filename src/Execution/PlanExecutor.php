<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Planner\ExecutionStageInterface;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Execution\Executors\ConstantQueryExecutor;
	use Quellabs\ObjectQuel\Execution\Executors\DatabaseQueryExecutor;
	use Quellabs\ObjectQuel\Execution\Executors\JsonQueryExecutor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Execution\Joins\JoinStrategyInterface;
	use Quellabs\ObjectQuel\Execution\Joins\CrossJoinStrategy;
	use Quellabs\ObjectQuel\Execution\Joins\LeftJoinStrategy;
	use Quellabs\ObjectQuel\Execution\Joins\InnerJoinStrategy;
	use Quellabs\ObjectQuel\Execution\Executors\TempTableExecutor;
	use Quellabs\ObjectQuel\Planner\ConstantStage;
	use Quellabs\ObjectQuel\Planner\ExecutionPlan;
	use Quellabs\ObjectQuel\Planner\ExecutionStage;
	use Quellabs\ObjectQuel\Planner\TempTableStage;
	
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
		 * Cache for join strategy instances to prevent duplicate creation
		 * @var array<string, JoinStrategyInterface>
		 */
		private array $joinStrategyCache = [];
		
		/**
		 * Executor responsible for regular database queries
		 * @var DatabaseQueryExecutor
		 */
		private DatabaseQueryExecutor $databaseExecutor;
		
		/**
		 * Executor responsible for materializing external-source subqueries as temp tables.
		 * @var TempTableExecutor
		 */
		private TempTableExecutor $tempTableExecutor;
		
		/**
		 * Executor responsible for executing and materializing JSON data
		 * @var JsonQueryExecutor
		 */
		private JsonQueryExecutor $jsonExecutor;
		
		/**
		 * Executor responsible for evaluating constant-only (rangeless) queries
		 * @var ConstantQueryExecutor
		 */
		private ConstantQueryExecutor $constantExecutor;
		
		/**
		 * Create a new plan executor
		 * @param QueryExecutor $queryExecutor The entity manager to use for execution
		 */
		public function __construct(QueryExecutor $queryExecutor) {
			$this->queryExecutor = $queryExecutor;
			$this->databaseExecutor = $queryExecutor->getDatabaseExecutor();
			$this->jsonExecutor = $queryExecutor->getJsonExecutor();
			$this->constantExecutor = new ConstantQueryExecutor();
			$this->tempTableExecutor = new TempTableExecutor($queryExecutor->getConnection());
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
		 * @return list<array<string, mixed>> Results from executing the plan
		 * @throws QuelException|EntityResolutionException When any stage execution fails
		 */
		public function execute(ExecutionPlan $plan): array {
			// Get stages in execution order (respecting dependencies)
			$stagesInOrder = $plan->getStagesInOrder();
			
			// Multi-stage execution: execute each stage in the correct order and combine the results
			/** @var array<string, list<array<string, mixed>>> $intermediateResults */
			$intermediateResults = [];
			
			try {
				// Execute each stage sequentially, maintaining dependency order
				foreach ($stagesInOrder as $stage) {
					if ($stage instanceof TempTableStage) {
						// Materialize the inner query into a temp table before the outer
						// database stage runs. This mutates the stage's AstRangeDatabase
						// so QuelToSQL emits a plain table reference in subsequent stages.
						// TempTableStages contribute no rows to intermediate results.
						$this->tempTableExecutor->execute(
							$stage,
							fn(ExecutionPlan $innerPlan) => $this->execute($innerPlan)
						);
					} elseif ($stage instanceof ConstantStage) {
						$intermediateResults[$stage->getName()] = $this->constantExecutor->execute($stage);
					} else {
						$intermediateResults[$stage->getName()] = $this->executeStage($stage);
					}
				}
				
				// Optimisation: skip the join machinery when only one result-producing stage ran
				if (count($intermediateResults) === 1) {
					return current($intermediateResults);
				}
				
				// Combine all intermediate results into a single final result
				return $this->combineResults($plan, $intermediateResults);
			} finally {
				// Always clean up temp tables, whether execution succeeded or failed.
				$this->tempTableExecutor->cleanup();
			}
		}
		
		/**
		 * Dispatches a single result-producing stage to the appropriate executor.
		 * JSON source ranges are handled in-memory; everything else goes to the database.
		 * @param ExecutionStageInterface $stage
		 * @return list<array<string, mixed>>
		 * @throws QuelException|EntityResolutionException
		 */
		private function executeStage(ExecutionStageInterface $stage): array {
			try {
				if ($stage->getRange() instanceof AstRangeJsonSource) {
					return $this->jsonExecutor->execute($stage, $stage->getStaticParams());
				} else {
					return $this->databaseExecutor->execute($stage, $stage->getStaticParams());
				}
			} catch (QuelException $e) {
				throw new QuelException("Stage '{$stage->getName()}' failed: {$e->getMessage()}", 'stage_error', 0, $e);
			}
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
				'cross' => new CrossJoinStrategy(), // Cartesian product join
				'left' => new LeftJoinStrategy(),   // Left outer join
				'inner' => new InnerJoinStrategy(), // Inner join
				default => throw new QuelException("Unsupported join type: {$joinType}")
			};
			
			$this->joinStrategyCache[$joinType] = $strategy;
			return $strategy;
		}
		
		/**
		 * This method performs the complex task of joining results from different stages
		 * based on their join types and conditions. It starts with the main stage result
		 * and progressively joins other stage results to build the final combined result.
		 * @param ExecutionPlan $plan The execution plan with stage information
		 * @param array<string, list<array<string, mixed>>> $intermediateResults Results from all stages, indexed by stage name
		 * @return list<array<string, mixed>> The combined result after performing all necessary joins
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
			
			// Build a name → stage index once (O(n)) to avoid O(n²) lookups in the join loop
			$stageIndex = [];
			
			foreach ($plan->getStagesInOrder() as $stage) {
				$stageIndex[$stage->getName()] = $stage;
			}
			
			// Start with the main result as our base for all subsequent joins
			$combinedResult = $intermediateResults[$mainStageName];
			
			// Iterate through all stage results to perform joins
			foreach ($intermediateResults as $stageName => $stageResult) {
				// Skip the main result itself - it's already our base
				if ($stageName === $mainStageName) {
					continue;
				}
				
				// Fetch the stage
				$stage = $stageIndex[$stageName] ?? null;
				
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
		 * @param list<array<string, mixed>> $leftResult The left result set (typically the accumulated result)
		 * @param list<array<string, mixed>> $rightResult The right result set (current stage result to join)
		 * @param string $joinType The type of join to perform (cross, left, inner)
		 * @param AstInterface|null $joinConditions The join conditions (if applicable)
		 * @return list<array<string, mixed>> The joined result set
		 * @throws QuelException When join type is unsupported or join fails
		 */
		private function performJoin(array $leftResult, array $rightResult, string $joinType, ?AstInterface $joinConditions = null): array {
			try {
				// Create the join strategy on-demand for the specific join type
				$strategy = $this->createJoinStrategy($joinType);
				
				// Execute the join using the selected strategy
				return $strategy->join($leftResult, $rightResult, $joinConditions);
			} catch (QuelException $e) {
				// Wrap any join errors with additional context
				throw new QuelException("Join failed ({$joinType}): {$e->getMessage()}", 'join_error', 0, $e);
			}
		}
	}