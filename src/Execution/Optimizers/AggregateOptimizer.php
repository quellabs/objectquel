<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\Capabilities\NullPlatformCapabilities;
	use Quellabs\ObjectQuel\Execution\Support\AggregateConstants;
	use Quellabs\ObjectQuel\Execution\Support\AggregateRewriter;
	use Quellabs\ObjectQuel\Execution\Support\AstUtilities;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAggregate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\Execution\Support\ExistsRewriter;
	use Quellabs\ObjectQuel\Execution\Support\RangeRemover;
	use Quellabs\ObjectQuel\Execution\Support\RangeUtilities;
	
	/**
	 * Class AggregateOptimizer
	 *
	 * Optimizes aggregate expressions in an ObjectQuel retrieve AST by choosing one of
	 * three strategies per aggregate:
	 *  - DIRECT   : keep in the outer query (add GROUP BY if needed)
	 *  - SUBQUERY : compute in a correlated scalar subquery over the minimal ranges
	 *  - WINDOW   : compute via window function (when DB + query shape permit)
	 *
	 * Primary goal is to reduce join width and isolate heavy work without changing semantics.
	 */
	class AggregateOptimizer {
		
		/** Strategy label: keep aggregate in the main query. */
		private const string STRATEGY_DIRECT = 'DIRECT';
		
		/** Strategy label: compute aggregate in a correlated subquery. */
		private const string STRATEGY_SUBQUERY = 'SUBQUERY';
		
		/** Strategy label: compute aggregate as a window function. */
		private const string STRATEGY_WINDOW = 'WINDOW';
		
		/** @var PlatformCapabilitiesInterface Database engine capability descriptor */
		private PlatformCapabilitiesInterface $platform;
		
		/**
		 * AggregateOptimizer constructor
		 * @param PlatformCapabilitiesInterface $platform Database engine capability descriptor
		 */
		public function __construct(PlatformCapabilitiesInterface $platform = new NullPlatformCapabilities()) {
			$this->platform = $platform;
		}
		
		// ---------------------------------------------------------------------
		// CORE PIPELINE
		// ---------------------------------------------------------------------
		
		/**
		 * Optimize the provided retrieve AST in-place.
		 *
		 * The pipeline runs in a fixed order that ensures each step sees a
		 * consistent AST:
		 *   1. Pre-compute query shape (aggregate-only vs mixed) once.
		 *   2. Apply join/range simplifications.
		 *   3. Snapshot the aggregate list before any rewrites.
		 *   4. Choose and apply a strategy for each aggregate.
		 *
		 * @param AstRetrieve $root Root query node to mutate
		 * @return void
		 */
		public function optimize(AstRetrieve $root): void {
			// Compute once; passed into helpers to avoid repeated AST walks.
			$isAggregateOnly = AstUtilities::areAllSelectFieldsAggregates($root);
			
			// Structural simplifications — must happen before strategy selection
			// so that strategies see the final range layout.
			$this->simplifyJoins($root, $isAggregateOnly);
			
			// Snapshot the aggregate list BEFORE any rewrite mutates the AST.
			// Collecting inside the loop is unsafe: rewriteAggregateAsCorrelatedSubquery
			// can restructure the tree, invalidating a live traversal.
			$aggregates = AstUtilities::collectAggregateNodes($root);
			
			// Apply per-aggregate strategies.
			$this->applyAggregateStrategies($root, $aggregates, $isAggregateOnly);
		}
		
		/**
		 * Structural join/range simplifications that are independent of strategy selection.
		 * @param AstRetrieve $root
		 * @param bool $isAggregateOnly
		 * @return void
		 */
		private function simplifyJoins(AstRetrieve $root, bool $isAggregateOnly): void {
			if ($isAggregateOnly) {
				// These two passes are only safe/meaningful when no non-aggregate
				// columns are selected — removing a range would drop those columns.
				RangeRemover::removeUnusedRangesInAggregateOnlyQueries($root);
				
				// Compute the map only when it will actually be used.
				$aggregateRangeMap = $this->buildAggregateRangeMap($root);
				ExistsRewriter::rewriteFilterOnlyJoinsAsExists($root, $aggregateRangeMap);
			}
			
			// Self-join → EXISTS simplification applies to any query shape.
			ExistsRewriter::simplifySelfJoinExists($root, false);
		}
		
		/**
		 * Choose and apply a rewrite strategy for each aggregate node.
		 * @param AstRetrieve $root
		 * @param AstAggregate[] $aggregates Stable snapshot collected before any mutation
		 * @param bool $isAggregateOnly
		 * @return void
		 */
		private function applyAggregateStrategies(AstRetrieve $root, array $aggregates, bool $isAggregateOnly): void {
			// Invariant per query, not per aggregate — compute once outside the loop.
			$nonAggItems = AstUtilities::collectNonAggregateSelectItems($root);
			
			// Guard against subtle divergence between the two helper methods.
			// $nonAggItems is already computed, so this assert is free — no extra AST walk.
			// If this fires, areAllSelectFieldsAggregates() and collectNonAggregateSelectItems()
			// have drifted out of sync and will produce contradictory strategy decisions.
			assert(
				$isAggregateOnly === empty($nonAggItems),
				'areAllSelectFieldsAggregates() and collectNonAggregateSelectItems() disagree on query shape'
			);
			
			foreach ($aggregates as $agg) {
				$strategy = $this->chooseStrategy($root, $agg, $isAggregateOnly, $nonAggItems);
				
				switch ($strategy) {
					case self::STRATEGY_DIRECT:
						// Ensure proper GROUP BY when mixing aggregates with non-aggregates.
						if (!$isAggregateOnly) {
							$root->setGroupBy($nonAggItems);
						}
						break;
					
					case self::STRATEGY_SUBQUERY:
						AggregateRewriter::rewriteAggregateAsCorrelatedSubquery($root, $agg);
						break;
					
					case self::STRATEGY_WINDOW:
						AggregateRewriter::rewriteAggregateAsWindowFunction($agg);
						break;
				}
			}
		}
		
		/**
		 * Pick the best evaluation strategy for a given aggregate within the query.
		 *
		 * Priority order (highest to lowest):
		 *  1. Correctness constraints     → SUBQUERY (filtered aggregates)
		 *  2. Aggregate-only query        → DIRECT
		 *  3. Window function eligibility → WINDOW   (single-table, uniform refs)
		 *  4. Mixed query, related ranges → DIRECT + GROUP BY
		 *  5. Mixed query, disjoint ranges→ SUBQUERY
		 *  6. Fallback                    → SUBQUERY
		 *
		 * Note: WINDOW is evaluated before the mixed-query range check because
		 * a single-table mixed query (e.g. SELECT id, SUM(amount) FROM t) is a
		 * valid window function candidate and is more efficient than GROUP BY.
		 *
		 * @param AstRetrieve $root
		 * @param AstAggregate $aggregate Aggregate node to analyze
		 * @param bool $isAggregateOnly Pre-computed query shape flag
		 * @param array<int, AstAlias> $nonAggItems Pre-computed non-aggregate SELECT items (invariant per query)
		 * @return string One of self::STRATEGY_* constants
		 */
		private function chooseStrategy(AstRetrieve $root, AstAggregate $aggregate, bool $isAggregateOnly, array $nonAggItems): string {
			// 1. Filtered aggregates must use a subquery to apply WHERE before aggregation.
			if ($aggregate->getConditions() !== null) {
				return self::STRATEGY_SUBQUERY;
			}
			
			// 2. Pure aggregate query: no GROUP BY needed, execute directly.
			if ($isAggregateOnly) {
				return self::STRATEGY_DIRECT;
			}
			
			// 3. Window function check comes before the range-overlap check.
			//    A single-table mixed query (SELECT id, SUM(x) FROM t) can avoid
			//    GROUP BY entirely by using a window function — more efficient.
			if ($this->canRewriteAsWindowFunction($root, $aggregate)) {
				return self::STRATEGY_WINDOW;
			}
			
			// 4–5. Mixed query: determine whether aggregate and non-aggregate fields
			//      share enough range overlap to be grouped in a single query.
			if (!empty($nonAggItems)) {
				$aggRanges = RangeUtilities::collectRangesFromNode($aggregate);
				$nonAggRanges = RangeUtilities::collectRangesFromNodes($nonAggItems);
				
				if (RangeUtilities::rangesOverlapOrAreRelated($aggRanges, $nonAggRanges)) {
					return self::STRATEGY_DIRECT;
				}
				
				return self::STRATEGY_SUBQUERY;
			}
			
			// 6. Fallback: safer to isolate than to guess.
			return self::STRATEGY_SUBQUERY;
		}
		
		// ---------------------------------------------------------------------
		// QUERY STRUCTURE ANALYSIS
		// ---------------------------------------------------------------------
		
		/**
		 * Build a hash-set of ranges that are referenced by at least one aggregate.
		 * Used to distinguish filter-only joins from data-producing joins.
		 *
		 * @param AstRetrieve $root
		 * @return array<string,true> map: spl_object_hash(AstRange) => true
		 */
		private function buildAggregateRangeMap(AstRetrieve $root): array {
			$map = [];
			
			foreach (AstUtilities::collectAggregateNodes($root) as $agg) {
				foreach (RangeUtilities::collectRangesFromNode($agg) as $r) {
					$map[spl_object_hash($r)] = true;
				}
			}
			
			return $map;
		}
		
		// ---------------------------------------------------------------------
		// WINDOW FUNCTION VALIDATION
		// ---------------------------------------------------------------------
		
		/**
		 * Check if we can safely rewrite the aggregate to a window function.
		 *
		 * Window functions operate over a set of rows related to the current row, making them
		 * suitable replacements for aggregates in certain scenarios. However, this transformation
		 * is only valid under specific conditions to maintain query correctness.
		 *
		 * Rewriting requirements:
		 * 1. Basic compatibility - The aggregate function itself must support window syntax
		 * 2. Single table query - Window functions work on row sets from a single source
		 * 3. Consistent references - The aggregate must reference the same table as the query
		 * 4. Uniform select items - All selected expressions must reference the same table
		 *
		 * Why these restrictions matter:
		 * - Multi-table queries would require complex partitioning logic
		 * - Cross-table references in aggregates would break window semantics
		 * - Mixed table references in select items would produce inconsistent row counts
		 *
		 * @param AstRetrieve $root Query root containing the complete SELECT statement
		 * @param AstAggregate $aggregate Specific aggregate function being analyzed for rewriting
		 * @return bool True if the aggregate can be safely rewritten as a window function
		 */
		private function canRewriteAsWindowFunction(AstRetrieve $root, AstAggregate $aggregate): bool {
			// VALIDATION 1: Basic Window Function Compatibility
			// ================================================
			// Check if this aggregate function type (SUM, COUNT, etc.) can be expressed
			// as a window function. Some aggregates have no window equivalent or require
			// special handling that makes them unsuitable for automatic rewriting.
			if (!$this->passesWindowFunctionBasics($aggregate)) {
				return false;
			}
			
			// VALIDATION 2: Single Table Requirement
			// ======================================
			// Window functions work best with single-table queries. Multi-table queries
			// introduce complexity around partitioning, ordering, and result set boundaries
			// that can lead to incorrect results after rewriting.
			//
			// Example of why this matters:
			//   SELECT a.id, SUM(b.amount) FROM users a JOIN orders b ON a.id = b.user_id
			//
			// Rewriting SUM(b.amount) as a window function would change the semantics
			// because window functions don't respect JOIN boundaries the same way.
			$queryRanges = $root->getRanges();
			
			if (count($queryRanges) !== 1) {
				return false;
			}
			
			$singleRange = $queryRanges[0];
			
			// VALIDATION 3: Aggregate Table Reference Consistency
			// ===================================================
			// The aggregate function must reference exactly the same table as the main query.
			// This prevents scenarios where the aggregate operates on a different data source
			// than the rest of the query, which would break window function semantics.
			//
			// Example of invalid case:
			//   SELECT users.name, (SELECT COUNT(*) FROM orders) FROM users
			//
			// The COUNT(*) references 'orders' while the main query uses 'users'.
			// This can't be rewritten as a window function because window functions
			// operate on the same row set as their containing query.
			if (!$this->aggregateMatchesQueryRange($aggregate, $singleRange)) {
				return false;
			}
			
			// VALIDATION 4: Uniform Select Item References
			// ============================================
			// All expressions in the SELECT clause must reference the same single table.
			// Mixed references would create inconsistent row counts after window function
			// rewriting, since window functions operate row-by-row on a consistent dataset.
			//
			// Example of invalid case:
			//   SELECT users.name, departments.budget, SUM(users.salary)
			//   FROM users, departments
			//
			// After rewriting SUM(users.salary) to a window function, each row would
			// show the total salary, but departments.budget would create a Cartesian
			// product that doesn't match the intended aggregation scope.
			if (!$this->selectItemsAreUniform($root, $aggregate, $singleRange)) {
				return false;
			}
			
			// All validations passed - the aggregate can be safely rewritten as a window function
			// The rewrite will preserve query semantics while potentially improving performance
			// by eliminating grouping operations in favor of analytical window processing.
			return true;
		}
		
		/**
		 * Returns true if the aggregate references exactly the given range and no other.
		 * @param AstAggregate $aggregate
		 * @param object $singleRange Expected range (identity comparison)
		 * @return bool
		 */
		private function aggregateMatchesQueryRange(AstAggregate $aggregate, object $singleRange): bool {
			$aggregateRanges = RangeUtilities::collectRangesFromNode($aggregate);
			return count($aggregateRanges) === 1 && $aggregateRanges[0] === $singleRange;
		}
		
		/**
		 * Returns true if every non-target SELECT item references exactly $singleRange.
		 * @param AstRetrieve $root
		 * @param AstAggregate $aggregate The aggregate being evaluated (already validated above)
		 * @param object $singleRange Expected range for all select items
		 * @return bool
		 */
		private function selectItemsAreUniform(AstRetrieve $root, AstAggregate $aggregate, object $singleRange): bool {
			foreach ($root->getValues() as $selectItem) {
				// Skip the aggregate we're analyzing - we already validated it above
				if ($selectItem->getExpression() === $aggregate) {
					continue; // This is our target aggregate, already checked
				}
				
				// Check what tables/views this select item references
				$itemRanges = RangeUtilities::collectRangesFromNode($selectItem->getExpression());
				
				// Each select item must reference exactly the same single table
				if (count($itemRanges) !== 1 || $itemRanges[0] !== $singleRange) {
					return false; // Select item references different/multiple tables
				}
			}
			
			return true;
		}
		
		/**
		 * Basic window support checks: no conditions, not DISTINCT variant, DB supports,
		 * and the aggregate type is in the supported list.
		 * @param AstAggregate $aggregate Aggregate to test
		 * @return bool True if basic constraints are satisfied
		 */
		private function passesWindowFunctionBasics(AstAggregate $aggregate): bool {
			// Window functions can't have their own filters
			if ($aggregate->getConditions() !== null) {
				return false;
			}
			
			// DISTINCT variants commonly unsupported for window context
			if (in_array(get_class($aggregate), AggregateConstants::DISTINCT_AGGREGATE_TYPES, true)) {
				return false;
			}
			
			// Database capability
			if (!$this->platform->supportsWindowFunctions()) {
				return false;
			}
			
			// One of the supported aggregates (no distinct)
			return in_array(get_class($aggregate), AggregateConstants::NOT_DISTINCT_AGGREGATE_TYPES, true);
		}
	}