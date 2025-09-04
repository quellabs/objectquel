<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Execution\Optimizers\Support\AggregateRewriter;
	use Quellabs\ObjectQuel\Execution\Optimizers\Support\AstUtilities;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\Execution\Optimizers\Support\ExistsRewriter;
	use Quellabs\ObjectQuel\Execution\Optimizers\Support\RangeRemover;
	use Quellabs\ObjectQuel\Execution\Optimizers\Support\RangeUtilities;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	
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
		
		private EntityManager $entityManager;
		
		/** @var array<class-string<AstInterface>> Supported aggregate node classes. */
		private const array AGGREGATE_NODE_TYPES = [
			AstSum::class,
			AstSumU::class,
			AstCount::class,
			AstCountU::class,
			AstAvg::class,
			AstAvgU::class,
			AstMin::class,
			AstMax::class,
		];
		
		/** @var array<class-string<AstInterface>> DISTINCT-capable aggregate classes. */
		private const array DISTINCT_AGGREGATE_TYPES = [
			AstSumU::class,
			AstAvgU::class,
			AstCountU::class,
		];
		
		/**
		 * Constructor
		 * @param EntityManager $entityManager Provides DB capabilities (e.g. window support)
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityManager = $entityManager;
		}
		
		// ---------------------------------------------------------------------
		// CORE PIPELINE
		// ---------------------------------------------------------------------
		
		/**
		 * Optimize the provided retrieve AST in-place.
		 *
		 * Pipeline:
		 *  1) Remove unused ranges when SELECT is aggregates-only
		 *  2) Rewrite filter-only joins to EXISTS
		 *  3) Simplify trivial self-join EXISTS to NOT NULL checks
		 *  4) Per aggregate: choose DIRECT/SUBQUERY/WINDOW and rewrite
		 *
		 * @param AstRetrieve $root Root query node to mutate
		 * @return void
		 */
		public function optimize(AstRetrieve $root): void {
			$isAggregateOnly = $this->selectIsAggregateOnly($root);
			$aggregateRangeMap = $this->buildAggregateRangeMap($root);
			
			if ($isAggregateOnly) {
				RangeRemover::removeUnusedRangesInAggregateOnlyQueries($root);
				ExistsRewriter::rewriteFilterOnlyJoinsAsExists($root, $aggregateRangeMap);
			}
			
			ExistsRewriter::simplifySelfJoinExists($root, false);
			
			foreach (AstUtilities::collectAggregateNodes($root) as $agg) {
				$strategy = $this->chooseStrategy($root, $agg);
				
				switch ($strategy) {
					case self::STRATEGY_DIRECT:
						if ($this->selectNeedsGroupBy($root)) {
							$this->ensureGroupByForNonAggregates($root);
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
		 * @param AstRetrieve $root Query AST
		 * @param AstInterface $aggregate Aggregate node to analyze
		 * @return string One of self::STRATEGY_* constants
		 */
		private function chooseStrategy(AstRetrieve $root, AstInterface $aggregate): string {
			if ($aggregate->getConditions() !== null) {
				return self::STRATEGY_SUBQUERY;
			}
			
			if ($this->selectIsAggregateOnly($root)) {
				return self::STRATEGY_DIRECT;
			}
			
			$nonAggItems = $this->collectNonAggregateSelectItems($root);
			
			if (!empty($nonAggItems)) {
				$aggRanges = RangeUtilities::collectRangesFromNode($aggregate);
				$nonAggRanges = RangeUtilities::collectRangesFromNodes($nonAggItems);
				
				if (RangeUtilities::rangesOverlapOrAreRelated($aggRanges, $nonAggRanges)) {
					return self::STRATEGY_DIRECT;
				} else {
					return self::STRATEGY_SUBQUERY;
				}
			}
			
			if ($this->canRewriteAsWindowFunction($root, $aggregate)) {
				return self::STRATEGY_WINDOW;
			}
			
			return self::STRATEGY_SUBQUERY;
		}
		
		// ---------------------------------------------------------------------
		// QUERY STRUCTURE ANALYSIS
		// ---------------------------------------------------------------------
		
		/**
		 * @param AstRetrieve $root
		 * @return bool True if every SELECT expression is an aggregate
		 */
		public function selectIsAggregateOnly(AstRetrieve $root): bool {
			foreach ($root->getValues() as $selectItem) {
				if (!AstUtilities::isAggregateExpression($selectItem->getExpression())) {
					return false;
				}
			}
			
			return true;
		}
		
		/**
		 * @param AstRetrieve $root Query node
		 * @return array<int,mixed> Non-aggregate SELECT value nodes
		 */
		private function collectNonAggregateSelectItems(AstRetrieve $root): array {
			$result = [];

			foreach ($root->getValues() as $selectItem) {
				$expression = $selectItem->getExpression();
				
				if (!AstUtilities::isAggregateExpression($expression)) {
					$result[] = $selectItem;
				}
			}

			return $result;
		}
		
		/**
		 * @param AstRetrieve $root
		 * @return array<string,bool> map: spl_object_hash(AstRange) => true for ranges used by aggregates
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
		
		/**
		 * @param AstRetrieve $root
		 * @return bool True if SELECT mixes aggregate and non-aggregate expressions
		 */
		private function selectNeedsGroupBy(AstRetrieve $root): bool {
			return !$this->selectIsAggregateOnly($root);
		}
		
		/**
		 * Ensure GROUP BY contains all non-aggregate SELECT items.
		 * @param AstRetrieve $root Query to mutate
		 * @return void
		 */
		private function ensureGroupByForNonAggregates(AstRetrieve $root): void {
			$root->setGroupBy($this->collectNonAggregateSelectItems($root));
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
		 * @param AstInterface $aggregate Specific aggregate function being analyzed for rewriting
		 * @return bool True if the aggregate can be safely rewritten as a window function
		 */
		private function canRewriteAsWindowFunction(AstRetrieve $root, AstInterface $aggregate): bool {
			// VALIDATION 1: Basic Window Function Compatibility
			// ================================================
			// Check if this aggregate function type (SUM, COUNT, etc.) can be expressed
			// as a window function. Some aggregates have no window equivalent or require
			// special handling that makes them unsuitable for automatic rewriting.
			if (!$this->passesWindowFunctionBasics($aggregate)) {
				return false;
			}
			
			// Extract all table/view references from the main query
			$queryRanges = $root->getRanges();
			
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
			if (count($queryRanges) !== 1) {
				return false;
			}
			
			// Store the single table reference for consistency checking
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
			$aggregateRanges = RangeUtilities::collectRangesFromNode($aggregate);
			
			if (count($aggregateRanges) !== 1 || $aggregateRanges[0] !== $singleRange) {
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
			
			// All validations passed - the aggregate can be safely rewritten as a window function
			// The rewrite will preserve query semantics while potentially improving performance
			// by eliminating grouping operations in favor of analytical window processing.
			return true;
		}
		
		/**
		 * Basic window support checks: no conditions, not DISTINCT variant, DB supports,
		 * and the aggregate type is allowed.
		 * @param AstInterface $aggregate Aggregate to test
		 * @return bool True if basic constraints are satisfied
		 */
		private function passesWindowFunctionBasics(AstInterface $aggregate): bool {
			// Window functions can't have their own filters
			if ($aggregate->getConditions() !== null) {
				return false;
			}
			
			// DISTINCT variants commonly unsupported for window context
			if (in_array(get_class($aggregate), self::DISTINCT_AGGREGATE_TYPES, true)) {
				return false;
			}
			
			// Database capability
			if (!$this->entityManager->getConnection()->supportsWindowFunctions()) {
				return false;
			}
			
			// One of the supported aggregates (no distinct)
			$supported = [AstSum::class, AstCount::class, AstAvg::class, AstMin::class, AstMax::class,];
			return in_array(get_class($aggregate), $supported, true);
		}
	}