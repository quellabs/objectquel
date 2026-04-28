<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\Execution\Decomposer\ConditionAnalyzer;
	use Quellabs\ObjectQuel\Execution\Decomposer\ConditionFilter;
	use Quellabs\ObjectQuel\Execution\Decomposer\StageFactory;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstUnaryOperation;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
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
			$conditionFilter = new ConditionFilter($this->analyzer);
			$this->stageFactory = new StageFactory($this->analyzer, $conditionFilter);
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
		 * @param array $staticParams Optional static parameters for the query
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
			
			// Sort temp ranges by their interdependencies (a temp range's inner query
			// might reference another temp range; it must come after its dependency).
			if (!empty($tempRanges)) {
				$tempRanges = $this->sortByDependency($tempRanges);
			}
			
			// Create TempTableStages for external-source ranges.
			// Track which temp range names have a corresponding TempTableStage so we
			// can register dependencies on the outer database stage.
			$tempTableStageNames = []; // rangeName → TempTableStage name
			
			foreach ($tempRanges as $tempRange) {
				if (!$this->rangeQueryContainsExternalSource($tempRange)) {
					// Pure SQL subquery — QuelToSQL handles it inline, no stage needed
					continue;
				}
				
				$tempStageName = uniqid('tmp_stage_');
				$tempStage = new TempTableStage($tempStageName, $tempRange, $staticParams);
				$plan->addStage($tempStage);
				$tempTableStageNames[$tempRange->getName()] = $tempStageName;
				
				// If this TempTableStage itself depends on another TempTableStage
				// (because its inner query references another external-source range),
				// register that dependency so ordering is preserved.
				foreach ($tempRanges as $otherTempRange) {
					if ($otherTempRange->getName() === $tempRange->getName()) {
						continue;
					}
					
					if (isset($tempTableStageNames[$otherTempRange->getName()])) {
						$innerQuery = $tempRange->getQuery();
						
						if ($innerQuery !== null && $this->innerQueryReferencesRange($innerQuery, $otherTempRange->getName())) {
							$plan->addDependency($tempStageName, $tempTableStageNames[$otherTempRange->getName()]);
						}
					}
				}
			}
			
			// Build main database query stage
			$databaseStage = $this->stageFactory->createDatabaseExecutionStage($query, $staticParams, array_keys($tempTableStageNames));
			
			if ($databaseStage) {
				$plan->addStage($databaseStage);
				
				// The main database stage depends on every TempTableStage, because
				// those must be fully materialised before the outer SQL can execute.
				foreach ($tempTableStageNames as $rangeName => $tempStageName) {
					$plan->addDependency($databaseStage->getName(), $tempStageName);
				}
			}
			
			// JSON stages
			foreach ($query->getOtherRanges() as $otherRange) {
				$plan->addStage($this->stageFactory->createRangeExecutionStage($query, $otherRange, $staticParams));
			}
			
			return $plan;
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
		 * Sorts temporary ranges by their dependencies.
		 * Since temp ranges can't reference other temp ranges in their range declarations,
		 * we only need to check if inner queries reference other temp ranges in WHERE/retrieve.
		 * @param AstRangeDatabase[] $temporaryRanges
		 * @return AstRangeDatabase[] Sorted array where dependencies come before ranges that use them
		 * @throws QuelException If circular dependency detected
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
				$rangeName = $range->getName();
				$deps = $this->findTempRangeDependenciesInConditions(
					$range->getQuery(),
					array_keys($rangesByName)
				);
				$dependencies[$rangeName] = $deps;
			}
			
			// Kahn's algorithm: compute in-degree (number of unresolved dependencies)
			// for each range. Ranges with in-degree 0 have no dependencies and can run first.
			$inDegree = [];
			
			foreach ($rangesByName as $name => $range) {
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
				// If a range's in-degree reaches 0, all its dependencies are now scheduled
				// and it is ready to run.
				foreach ($dependencies as $rangeName => $deps) {
					if (in_array($current, $deps)) {
						$inDegree[$rangeName]--;
						
						if ($inDegree[$rangeName] === 0) {
							$queue[] = $rangeName;
						}
					}
				}
			}
			
			// If not all ranges were scheduled, the dependency graph contains a cycle
			if (count($sorted) !== count($temporaryRanges)) {
				throw new QuelException('Circular dependency detected in temporary ranges');
			}
			
			return $sorted;
		}
		
		/**
		 * Finds temp range names referenced in WHERE conditions and retrieve expressions
		 * @param AstRetrieve $query
		 * @param array $tempRangeNames List of temp range names to check for
		 * @return array Temp range names this query depends on
		 */
		protected function findTempRangeDependenciesInConditions(AstRetrieve $query, array $tempRangeNames): array {
			$dependencies = [];
			
			// Check WHERE conditions
			if ($query->getConditions() !== null) {
				$deps = $this->extractRangeNamesFromAst($query->getConditions(), $tempRangeNames);
				$dependencies = array_merge($dependencies, $deps);
			}
			
			// Check retrieve expressions
			foreach ($query->getValues() as $value) {
				$deps = $this->extractRangeNamesFromAst($value, $tempRangeNames);
				$dependencies = array_merge($dependencies, $deps);
			}
			
			return array_unique($dependencies);
		}
		
		/**
		 * Recursively extracts temp range names from an AST node
		 * @param AstInterface $node
		 * @param array $tempRangeNames
		 * @return array
		 */
		protected function extractRangeNamesFromAst(AstInterface $node, array $tempRangeNames): array {
			$found = [];
			
			// An identifier is a leaf node that directly references a range (e.g. x.id).
			// If its range is one of the temp ranges we're tracking, record it.
			if ($node instanceof AstIdentifier) {
				$range = $node->getRange();
				
				if ($range !== null && in_array($range->getName(), $tempRangeNames)) {
					$found[] = $range->getName();
				}
			}
			
			// Binary nodes (comparisons, logical operators, arithmetic) have two children.
			// Recurse into both sides to find any temp range references within.
			if ($node instanceof AstBinaryOperator ||
				$node instanceof AstExpression ||
				$node instanceof AstTerm ||
				$node instanceof AstFactor) {
				$found = array_merge(
					$found,
					$this->extractRangeNamesFromAst($node->getLeft(), $tempRangeNames),
					$this->extractRangeNamesFromAst($node->getRight(), $tempRangeNames)
				);
			}
			
			// Unary nodes (NOT, IS NULL, etc.) and aliases wrap a single inner expression.
			// Recurse into that expression to continue the search.
			if ($node instanceof AstUnaryOperation || $node instanceof AstAlias) {
				$found = array_merge(
					$found,
					$this->extractRangeNamesFromAst($node->getExpression(), $tempRangeNames)
				);
			}
			
			// Add other AST node types as needed
			return $found;
		}
	}