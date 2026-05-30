<?php
	
	namespace Quellabs\ObjectQuel\Planner;
	
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseTempTable;
	use Quellabs\ObjectQuel\Planner\Helpers\ConditionAnalyzer;
	use Quellabs\ObjectQuel\Planner\Helpers\ConditionFilter;
	use Quellabs\ObjectQuel\Planner\Helpers\StageFactory;
	use Quellabs\ObjectQuel\Planner\Visitors\SearchStrategyResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Planner\QueryPlan\PlanLogInterface;
	use Quellabs\ObjectQuel\Planner\QueryPlan\NullPlanLog;
	
	/**
	 * Orchestrates the decomposition of a query into an ExecutionPlan.
	 *
	 * Delegates the three distinct sub-responsibilities to specialized classes:
	 *   - ConditionAnalyzer: answers questions about which ranges a condition references
	 *   - ConditionFilter:   extracts and filters condition subtrees from the WHERE clause
	 *   - StageFactory:      builds ExecutionStage objects from query ASTs
	 *
	 * ExecutionPlanBuilder itself is responsible for:
	 *   - Detecting which subquery ranges require temp-table materialization
	 *   - Topologically sorting those ranges by their interdependencies
	 *   - Wiring TempTableStages and their dependency edges into the ExecutionPlan
	 *   - Adding JSON/non-database range stages
	 */
	class ExecutionPlanBuilder  {
		
		/** @var ConditionAnalyzer */
		private ConditionAnalyzer $analyzer;
		
		/** @var StageFactory */
		private StageFactory $stageFactory;
		
		/**
		 * Constructor
		 */
		public function __construct() {
			$this->analyzer = new ConditionAnalyzer();
			$this->stageFactory = new StageFactory($this->analyzer, new ConditionFilter($this->analyzer));
		}
		
		/**
		 * Decomposes a query into separate execution stages for different data sources.
		 * This function takes a mixed-source query and creates an execution plan with
		 * appropriate stages for database and JSON sources.
		 *
		 * Stage construction order:
		 *   1. For each temporary range (AstRangeDatabase with embedded subquery):
		 *      a. If it contains external sources → add a TempTableStage first, then
		 *         register a dependency so the outer database stage runs after it.
		 *      b. If it is pure SQL → leave as-is (inline subquery handled by QuelToSQL).
		 *   2. Add the main database stage (which references temp tables as plain tables
		 *      by the time it executes, because all TempTableStages will have run first).
		 *   3. Add JSON/other non-database range stages (existing behavior).
		 *
		 * @param AstRetrieve $query The ObjectQuel query to decompose
		 * @param array<string, mixed> $staticParams Optional static parameters for the query
		 * @return ExecutionPlan The execution plan containing all stages
		 * @throws QuelException|EntityResolutionException If the query cannot be properly decomposed
		 */
		public function build(AstRetrieve $query, array $staticParams = [], PlanLogInterface $log = new NullPlanLog()): ExecutionPlan {
			$this->analyzer->clearCache();
			
			// Create a new plan to populate
			$plan = new ExecutionPlan();
			
			// Shortcut: no ranges means the projection contains only constant expressions
			// (literals, parameters, arithmetic). There is no data source to query, so
			// emit a single ConstantStage and return immediately. PlanExecutor will evaluate
			// the projections directly via ConditionEvaluator and return one synthetic row.
			if (empty($query->getRanges())) {
				$plan->addStage(new ConstantStage(uniqid('const_'), $query->getValues(), $staticParams));
				return $plan;
			}
			
			// Detect which temporary ranges need materialization as real temp tables.
			// We must process these BEFORE creating the main database stage so that
			// dependency edges can be registered correctly.
			$tempRanges = $this->stageFactory->extractTemporaryRanges($query);
			
			// Sort temp ranges by their interdependencies (a temp range's inner query
			// might reference another temp range; it must come after its dependency).
			if (!empty($tempRanges)) {
				$tempRanges = $this->sortByDependency($tempRanges);
			}
			
			// Build TempTableStages and register inter-stage dependencies
			$tempTableStageNames = $this->buildTempTableStages($plan, $tempRanges, $staticParams, $log);
			
			// Build the main database stage and wire its dependencies on all TempTableStages
			$this->buildDatabaseStage($plan, $query, $staticParams, $tempTableStageNames, $log);
			
			// JSON stages
			foreach ($query->getOtherRanges() as $otherRange) {
				// Create the stage
				$stage = $this->stageFactory->createRangeExecutionStage($query, $otherRange, $staticParams);
				
				// Add stage to the plan
				$plan->addStage($stage);
				
				// Log the in-memory join type chosen for this external source range.
				// Suppressed when this is the only range in the query — a single-range
				// query has nothing to join against, so the join type is meaningless.
				if (count($query->getRanges()) === 1) {
					continue;
				}
				
				// Log the in-memory join type chosen for this external source range.
				// The reason mirrors the logic in ExecutionStage::getJoinType() so the
				// plan log accurately reflects what the executor will do at runtime.
				$joinType = $stage->getJoinType();
				
				/** @noinspection PhpSwitchCanBeReplacedWithMatchExpressionInspection */
				switch ($joinType) {
					case 'cross':
						$joinReason = 'no join condition — cartesian product';
						break;
						
					case 'inner':
						if ($stage->getQuery()->getConditions() !== null) {
							$joinReason = 'scalar filter on JSON field requires a match';
						} else {
							$joinReason = 'IS NOT NULL on JSON field requires a match';
						}
						
						break;
						
					case 'left':
						$joinReason = 'join condition only — unmatched DB rows kept with null JSON fields';
						break;
						
					default:
						$joinReason = $joinType;
						break;
				}

				$log->note('planner', 'join', 'JSON_JOIN_TYPE',
					"Non database range '{$otherRange->getName()}' uses {$joinType} join: {$joinReason}",
					$otherRange->getName()
				);
			}
			
			// Return the plan
			return $plan;
		}
		
		// =========================================================================
		// Plan construction helpers
		// =========================================================================
		
		/**
		 * Creates TempTableStages for all external-source temporary ranges and registers
		 * their interdependencies in the plan. Returns a map of rangeName → TempTableStage
		 * name so the database stage can declare its own dependencies on them.
		 * @param ExecutionPlan $plan
		 * @param AstRangeDatabaseSubquery[] $tempRanges Already dependency-sorted temp ranges
		 * @param array<string, mixed> $staticParams
		 * @return string[] Map of rangeName → TempTableStage name
		 * @throws QuelException|EntityResolutionException
		 */
		private function buildTempTableStages(ExecutionPlan $plan, array $tempRanges, array $staticParams, PlanLogInterface $log): array {
			// rangeName → TempTableStage name, built up as stages are added so inter-stage
			// dependency checks can refer to stages that were created earlier in the loop.
			$tempTableStageNames = [];
			
			foreach ($tempRanges as $tempRange) {
				// Only handle temporary tables
				if (!$tempRange instanceof AstRangeDatabaseTempTable) {
					continue;
				}
				
				// Create a stage name
				$tempStageName = uniqid('tmp_stage_');
				
				// Build the plan, threading the log through so inner queries are also recorded
				$innerPlan = $this->build($tempRange->getQuery(), $staticParams, $log);
				
				// Add the stage to the plan
				$plan->addStage(new TempTableStage($tempStageName, $tempRange, $innerPlan, $staticParams));
				
				// Add note for the temp table materialization
				$log->note('planner', 'stage', 'TEMP_TABLE',
					"Range '{$tempRange->getName()}' requires temp table materialization (contains external/JSON source)",
					$tempRange->getName()
				);
				
				// Store in list
				$tempTableStageNames[$tempRange->getName()] = $tempStageName;
				
				// If this TempTableStage itself depends on another TempTableStage
				// (because its inner query references another external-source range),
				// register that dependency so ordering is preserved.
				$innerQuery = $tempRange->getQuery();
				
				foreach ($tempTableStageNames as $otherRangeName => $otherStageName) {
					if ($otherRangeName === $tempRange->getName()) {
						continue;
					}
					
					if ($this->innerQueryReferencesRange($innerQuery, $otherRangeName)) {
						$plan->addDependency($tempStageName, $otherStageName);
					}
				}
			}
			
			return $tempTableStageNames;
		}
		
		/**
		 * Creates the main database ExecutionStage and registers its dependencies on
		 * every TempTableStage that must be materialized before it runs.
		 * @param ExecutionPlan $plan
		 * @param AstRetrieve $query
		 * @param array<string, mixed> $staticParams
		 * @param string[] $tempTableStageNames Map of rangeName → TempTableStage name
		 */
		private function buildDatabaseStage(ExecutionPlan $plan, AstRetrieve $query, array $staticParams, array $tempTableStageNames, PlanLogInterface $log): void {
			// Create the stage
			$databaseStage = $this->stageFactory->createDatabaseExecutionStage($query, $staticParams);
			
			// If that failed, return
			if ($databaseStage === null) {
				return;
			}
			
			// Add the stage to the plan
			$plan->addStage($databaseStage);
			
			// The main database stage depends on every TempTableStage, because
			// those must be fully materialized before the outer SQL can execute.
			foreach ($tempTableStageNames as $tempStageName) {
				$plan->addDependency($databaseStage->getName(), $tempStageName);
			}
			
			// Add note to the log
			if (!empty($tempTableStageNames)) {
				$order = implode(' -> ', array_values($tempTableStageNames)) . ' -> ' . $databaseStage->getName();
				$log->note('planner', 'stage', 'EXECUTION_ORDER',
					"Stage execution order: {$order}"
				);
			}
		}
		
		// =========================================================================
		// External-source detection
		// =========================================================================
		
		/**
		 * Checks whether an inner query's ranges include a reference to a specific
		 * range name. Used to detect inter-TempTableStage dependencies when one
		 * external-source subquery depends on another.
		 * @param AstRetrieve $innerQuery
		 * @param string $rangeName The range name to look for
		 * @return bool
		 */
		protected function innerQueryReferencesRange(AstRetrieve $innerQuery, string $rangeName): bool {
			foreach ($innerQuery->getRanges() as $range) {
				if ($range->getName() === $rangeName) {
					return true;
				}
			}
			
			return false;
		}
		
		// =========================================================================
		// Temp-range dependency sorting
		// =========================================================================
		
		/**
		 * Topologically sorts temporary ranges so that each range appears after all
		 * ranges it depends on, using Kahn's algorithm (BFS-based).
		 *
		 * Assumptions:
		 *   - The dependency graph is acyclic. A QuelException is thrown if a cycle is
		 *     detected, which indicates a query construction error rather than user input.
		 *   - Temp ranges cannot reference other temp ranges in their range declarations;
		 *     dependencies are detected only in WHERE conditions and retrieve expressions.
		 *
		 * Ordering guarantees:
		 *   - All dependencies of a range appear before it in the output.
		 *   - Among ranges at the same dependency depth (no ordering constraint between
		 *     them), output order follows insertion order of the input array. This is
		 *     deterministic but arbitrary — any valid topological order is correct here.
		 *
		 * @param AstRangeDatabaseSubquery[] $temporaryRanges
		 * @return AstRangeDatabaseSubquery[] Sorted array where dependencies come before ranges that use them
		 * @throws QuelException If a circular dependency is detected
		 */
		protected function sortByDependency(array $temporaryRanges): array {
			// Build a name → range lookup so we can retrieve ranges by name during sorting
			$rangesByName = [];
			
			foreach ($temporaryRanges as $range) {
				$rangesByName[$range->getName()] = $range;
			}
			
			// For each temp range, inspect its inner query's WHERE clause and projections
			// to find which other temp ranges it references. That gives us the dependency graph.
			$dependencies = [];
			
			foreach ($temporaryRanges as $range) {
				$dependencies[$range->getName()] = $this->analyzer->findTempRangeDependencies(
					$range->getQuery(),
					array_keys($rangesByName)
				);
			}
			
			// Precompute a reverse adjacency list: dependents[$dep] = list of ranges that depend on $dep.
			// This lets the main loop decrement in-degrees in O(k) per step rather than O(n) per step,
			// reducing the overall sort from O(n²) to O(n + e) where e is the number of dependency edges.
			$dependents = [];
			
			foreach ($dependencies as $rangeName => $deps) {
				foreach ($deps as $dep) {
					$dependents[$dep][] = $rangeName;
				}
			}
			
			// Kahn's algorithm: compute in-degree (number of unresolved dependencies)
			// for each range. Ranges with in-degree 0 have no dependencies and can run first.
			$inDegree = [];
			
			foreach ($rangesByName as $name => $_) {
				$inDegree[$name] = count($dependencies[$name]);
			}
			
			// Seed the queue with all ranges that have no dependencies
			$queue = [];
			
			foreach ($inDegree as $name => $degree) {
				if ($degree === 0) {
					$queue[] = $name;
				}
			}
			
			$sorted = [];
			
			while (!empty($queue)) {
				// Take the next dependency-free range and add it to the sorted output
				$current = array_shift($queue);
				$sorted[] = $rangesByName[$current];
				
				// Decrement the in-degree of every range that depended on $current.
				// Using the precomputed reverse adjacency list avoids scanning all dependencies.
				foreach ($dependents[$current] ?? [] as $dependent) {
					$inDegree[$dependent]--;
					
					if ($inDegree[$dependent] === 0) {
						$queue[] = $dependent;
					}
				}
			}
			
			// If not all ranges were scheduled, the dependency graph contains a cycle
			if (count($sorted) !== count($temporaryRanges)) {
				throw new QuelException('Circular dependency detected in temporary ranges');
			}
			
			return $sorted;
		}
	}