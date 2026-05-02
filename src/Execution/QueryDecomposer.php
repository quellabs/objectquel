<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\Execution\Decomposer\ConditionAnalyzer;
	use Quellabs\ObjectQuel\Execution\Decomposer\ConditionFilter;
	use Quellabs\ObjectQuel\Execution\Decomposer\StageFactory;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
	/**
	 * Orchestrates the decomposition of a query into an ExecutionPlan.
	 *
	 * Delegates the three distinct sub-responsibilities to specialised classes:
	 *   - ConditionAnalyzer: answers questions about which ranges a condition references
	 *   - ConditionFilter:   extracts and filters condition subtrees from the WHERE clause
	 *   - StageFactory:      builds ExecutionStage objects from query ASTs
	 *
	 * QueryDecomposer itself is responsible for:
	 *   - Detecting which subquery ranges require temp-table materialisation
	 *   - Topologically sorting those ranges by their inter-dependencies
	 *   - Wiring TempTableStages and their dependency edges into the ExecutionPlan
	 *   - Adding JSON/non-database range stages
	 */
	class QueryDecomposer {
		
		/**
		 * @var ConditionAnalyzer
		 */
		private ConditionAnalyzer $analyzer;
		
		/**
		 * @var StageFactory
		 */
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
		 *   3. Add JSON/other non-database range stages (existing behaviour).
		 *
		 * @param AstRetrieve $query The ObjectQuel query to decompose
		 * @param array<int|string, mixed> $staticParams Optional static parameters for the query
		 * @return ExecutionPlan The execution plan containing all stages
		 * @throws QuelException If the query cannot be properly decomposed
		 */
		public function buildExecutionPlan(AstRetrieve $query, array $staticParams = []): ExecutionPlan {
			$this->analyzer->clearCache();
			$plan = new ExecutionPlan();
			
			// Detect which temporary ranges need materialisation as real temp tables.
			// We must process these BEFORE creating the main database stage so that
			// dependency edges can be registered correctly.
			$tempRanges = $this->stageFactory->extractTemporaryRanges($query);
			
			// Sort temp ranges by their inter-dependencies (a temp range's inner query
			// might reference another temp range; it must come after its dependency).
			if (!empty($tempRanges)) {
				$tempRanges = $this->sortByDependency($tempRanges);
			}
			
			// Build TempTableStages and register inter-stage dependencies
			$tempTableStageNames = $this->buildTempTableStages($plan, $tempRanges, $staticParams);
			
			// Build the main database stage and wire its dependencies on all TempTableStages
			$this->buildDatabaseStage($plan, $query, $staticParams, $tempTableStageNames);
			
			// JSON stages
			foreach ($query->getOtherRanges() as $otherRange) {
				$plan->addStage($this->stageFactory->createRangeExecutionStage($query, $otherRange, $staticParams));
			}
			
			return $plan;
		}
		
		// =========================================================================
		// Plan construction helpers
		// =========================================================================
		
		/**
		 * Creates TempTableStages for all external-source temporary ranges and registers
		 * their inter-dependencies in the plan.
		 *
		 * Returns a map of rangeName → TempTableStage name so the database stage can
		 * declare its own dependencies on them.
		 *
		 * @param ExecutionPlan $plan
		 * @param AstRangeDatabase[] $tempRanges Already dependency-sorted temp ranges
		 * @param array<int|string, mixed> $staticParams
		 * @return string[] Map of rangeName → TempTableStage name
		 */
		private function buildTempTableStages(ExecutionPlan $plan, array $tempRanges, array $staticParams): array {
			// rangeName → TempTableStage name, built up as stages are added so inter-stage
			// dependency checks can refer to stages that were created earlier in the loop.
			$tempTableStageNames = [];
			
			foreach ($tempRanges as $tempRange) {
				if (!$this->rangeQueryContainsExternalSource($tempRange)) {
					// Pure SQL subquery — QuelToSQL handles it inline, no stage needed
					continue;
				}
				
				$tempStageName = uniqid('tmp_stage_');
				$plan->addStage(new TempTableStage($tempStageName, $tempRange, $staticParams));
				$tempTableStageNames[$tempRange->getName()] = $tempStageName;
				
				// If this TempTableStage itself depends on another TempTableStage
				// (because its inner query references another external-source range),
				// register that dependency so ordering is preserved.
				$innerQuery = $tempRange->getQuery();
				
				if ($innerQuery !== null) {
					foreach ($tempTableStageNames as $otherRangeName => $otherStageName) {
						if ($otherRangeName === $tempRange->getName()) {
							continue;
						}
						
						if ($this->innerQueryReferencesRange($innerQuery, $otherRangeName)) {
							$plan->addDependency($tempStageName, $otherStageName);
						}
					}
				}
			}
			
			return $tempTableStageNames;
		}
		
		/**
		 * Creates the main database ExecutionStage and registers its dependencies on
		 * every TempTableStage that must be materialised before it runs.
		 *
		 * @param ExecutionPlan $plan
		 * @param AstRetrieve $query
		 * @param array<int|string, mixed> $staticParams
		 * @param string[] $tempTableStageNames Map of rangeName → TempTableStage name
		 */
		private function buildDatabaseStage(ExecutionPlan $plan, AstRetrieve $query, array $staticParams, array $tempTableStageNames): void {
			$databaseStage = $this->stageFactory->createDatabaseExecutionStage($query, $staticParams, array_keys($tempTableStageNames));
			
			if ($databaseStage === null) {
				return;
			}
			
			$plan->addStage($databaseStage);
			
			// The main database stage depends on every TempTableStage, because
			// those must be fully materialised before the outer SQL can execute.
			foreach ($tempTableStageNames as $rangeName => $tempStageName) {
				$plan->addDependency($databaseStage->getName(), $tempStageName);
			}
		}
		
		// =========================================================================
		// External-source detection
		// =========================================================================
		
		/**
		 * Recursively determines whether a temporary range's embedded query contains
		 * any external (non-database) data source, such as AstRangeJsonSource.
		 *
		 * This is the primary gate for the temp-table materialisation path. A range
		 * that returns false here is a pure SQL subquery and is left to QuelToSQL to
		 * handle as an inline derived table.
		 *
		 * Extensibility: add new external source types (e.g. AstRangeCsvSource) to
		 * the instanceof check inside the loop.
		 *
		 * Recursion safety: the AST is guaranteed acyclic by the parser, so no depth
		 * guard is needed.
		 *
		 * @param AstRangeDatabase $range The range whose embedded query is to be checked
		 * @return bool True if any range in the inner query (recursively) is external
		 */
		protected function rangeQueryContainsExternalSource(AstRangeDatabase $range): bool {
			$innerQuery = $range->getQuery();
			
			if ($innerQuery === null) {
				return false;
			}
			
			foreach ($innerQuery->getRanges() as $innerRange) {
				// Direct external source — JSON today, others in the future
				if ($innerRange instanceof AstRangeJsonSource) {
					return true;
				}
				
				// Nested subquery: recurse to check its ranges as well
				if ($innerRange instanceof AstRangeDatabase && $innerRange->getQuery() !== null) {
					if ($this->rangeQueryContainsExternalSource($innerRange)) {
						return true;
					}
				}
			}
			
			return false;
		}
		
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
		 * @param AstRangeDatabase[] $temporaryRanges
		 * @return AstRangeDatabase[] Sorted array where dependencies come before ranges that use them
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