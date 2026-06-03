<?php
	
	namespace Quellabs\ObjectQuel\Planner\Optimizers;
	
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\Capabilities\NullPlatformCapabilities;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAggregate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\Planner\Helpers\AggregateConstants;
	use Quellabs\ObjectQuel\Planner\Helpers\AggregateRewriter;
	use Quellabs\ObjectQuel\Planner\Helpers\AstUtilities;
	use Quellabs\ObjectQuel\Planner\Helpers\ExistsRewriter;
	use Quellabs\ObjectQuel\Planner\Helpers\RangeRemover;
	use Quellabs\ObjectQuel\Planner\Helpers\RangeUtilities;
	use Quellabs\ObjectQuel\Planner\QueryPlan\PlanLogInterface;
	use Quellabs\ObjectQuel\Planner\QueryPlan\NullPlanLog;
	
	/**
	 * Optimizes aggregate expressions in an ObjectQuel retrieve AST by choosing one of
	 * four strategies per aggregate:
	 *  - DIRECT   : keep in the outer query (add GROUP BY if needed)
	 *  - SUBQUERY : compute in a correlated scalar subquery over the minimal ranges
	 *  - WINDOW   : compute via window function (when DB + query shape permit)
	 *  - MEMORY   : evaluate in-memory via ConditionEvaluator (non-database ranges)
	 *
	 * Primary goal is to reduce join width and isolate heavy work without changing semantics.
	 */
	class AggregateOptimizer {
		
		private const string STRATEGY_DIRECT   = 'DIRECT';
		private const string STRATEGY_SUBQUERY = 'SUBQUERY';
		private const string STRATEGY_WINDOW   = 'WINDOW';
		private const string STRATEGY_MEMORY   = 'MEMORY';
		
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
		 * Pipeline order:
		 *   1. Pre-compute query shape (aggregate-only vs mixed) once.
		 *   2. Apply join/range simplifications.
		 *   3. Snapshot the aggregate list before any rewrites.
		 *   4. Choose and apply a strategy for each aggregate.
		 *
		 * @param AstRetrieve $root Root query node to mutate
		 * @param PlanLogInterface $log Collects planning decisions; use NullPlanLog to disable
		 */
		public function optimize(AstRetrieve $root, PlanLogInterface $log = new NullPlanLog()): void {
			// Compute once and pass into helpers to avoid repeated AST walks.
			$isAggregateOnly = AstUtilities::areAllSelectFieldsAggregates($root);
			
			// Structural simplifications must happen before strategy selection
			// so that strategies see the final range layout.
			$this->simplifyJoins($root, $isAggregateOnly);
			
			// Snapshot BEFORE any rewrite mutates the AST — collecting inside the
			// loop is unsafe because rewriteAggregateAsCorrelatedSubquery can
			// restructure the tree and invalidate a live traversal.
			$aggregates = AstUtilities::collectAggregateNodes($root);
			
			// Apply the strategies
			$this->applyAggregateStrategies($root, $aggregates, $isAggregateOnly, $log);
		}
		
		/**
		 * Structural join/range simplifications, independent of strategy selection.
		 *
		 * Range removal and filter-join rewriting are gated on $isAggregateOnly because
		 * removing a range from a mixed query would silently drop the non-aggregate
		 * columns that reference it.
		 * @param AstRetrieve $root
		 * @param bool $isAggregateOnly True when every SELECT projection is aggregate-equivalent
		 */
		private function simplifyJoins(AstRetrieve $root, bool $isAggregateOnly): void {
			if ($isAggregateOnly) {
				RangeRemover::removeUnusedRangesInAggregateOnlyQueries($root);
				ExistsRewriter::rewriteFilterOnlyJoinsAsExists($root, $this->buildAggregateRangeMap($root));
			}
			
			// Self-join → EXISTS simplification applies to any query shape.
			ExistsRewriter::simplifySelfJoinExists($root, false);
		}
		
		/**
		 * Choose and apply a rewrite strategy for each aggregate node.
		 *
		 * @param AstRetrieve $root
		 * @param AstAggregate[] $aggregates Stable snapshot collected before any mutation
		 * @param bool $isAggregateOnly Pre-computed query shape flag
		 * @param PlanLogInterface $log
		 */
		private function applyAggregateStrategies(AstRetrieve $root, array $aggregates, bool $isAggregateOnly, PlanLogInterface $log): void {
			// Non-aggregate items are invariant per query — compute once outside the loop.
			$nonAggItems = AstUtilities::collectNonAggregateSelectItems($root);
			
			// Guard against areAllSelectFieldsAggregates() and collectNonAggregateSelectItems()
			// drifting out of sync — if this fires, their definitions have diverged.
			assert(
				$isAggregateOnly === empty($nonAggItems),
				'areAllSelectFieldsAggregates() and collectNonAggregateSelectItems() disagree on query shape'
			);
			
			foreach ($aggregates as $agg) {
				$strategy = $this->chooseStrategy($root, $agg, $isAggregateOnly, $nonAggItems);
				$this->applyStrategy($root, $agg, $strategy, $isAggregateOnly, $nonAggItems, $log);
			}
		}
		
		/**
		 * Apply a chosen strategy to a single aggregate node and emit a plan log entry.
		 *
		 * @param AstRetrieve $root
		 * @param AstAggregate $agg The aggregate node to rewrite
		 * @param string $strategy One of self::STRATEGY_*
		 * @param bool $isAggregateOnly Pre-computed query shape flag
		 * @param AstAlias[] $nonAggItems Non-aggregate SELECT items (used for GROUP BY)
		 * @param PlanLogInterface $log
		 */
		private function applyStrategy(AstRetrieve $root, AstAggregate $agg, string $strategy, bool $isAggregateOnly, array $nonAggItems, PlanLogInterface $log): void {
			$label = $agg->getType();
			
			switch ($strategy) {
				case self::STRATEGY_DIRECT:
					// GROUP BY is only needed when mixing aggregates with non-aggregates.
					// Aggregate-only queries collapse to one row without it.
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
				
				case self::STRATEGY_MEMORY:
					// Non-database aggregate — evaluated in memory by ConditionEvaluator.
					// No SQL rewrite applies; leave the AST node untouched.
					break;
			}
			
			$log->note('optimizer', 'aggregate', $strategy, "Aggregate {$label} → {$strategy}", $label);
		}
		
		// ---------------------------------------------------------------------
		// STRATEGY SELECTION
		// ---------------------------------------------------------------------
		
		/**
		 * Pick the best evaluation strategy for a given aggregate within the query.
		 *
		 * Priority order:
		 *  1. Non-database source     → MEMORY   (JSON ranges, evaluated in PHP)
		 *  2. Filtered aggregate      → SUBQUERY (WHERE must run inside the aggregate)
		 *  3. Aggregate-only query    → DIRECT   (no GROUP BY needed)
		 *  4. Window-eligible         → WINDOW   (single-table mixed query, avoids GROUP BY)
		 *  5. Ranges overlap          → DIRECT + GROUP BY
		 *  6. Fallback                → SUBQUERY
		 *
		 * WINDOW is checked before the range-overlap test (step 5) because a
		 * single-table mixed query is a valid window candidate and avoids the
		 * GROUP BY entirely — more efficient than DIRECT for that shape.
		 *
		 * @param AstRetrieve $root
		 * @param AstAggregate $aggregate Aggregate node to analyze
		 * @param bool $isAggregateOnly Pre-computed query shape flag
		 * @param AstAlias[] $nonAggItems Pre-computed non-aggregate SELECT items
		 * @return string One of self::STRATEGY_*
		 */
		private function chooseStrategy(AstRetrieve $root, AstAggregate $aggregate, bool $isAggregateOnly, array $nonAggItems): string {
			$aggRanges = RangeUtilities::collectRangesFromNode($aggregate);
			
			// 1. All ranges are non-database (e.g. JSON) — evaluate in memory.
			if ($this->allRangesAreNonDatabase($aggRanges)) {
				return self::STRATEGY_MEMORY;
			}
			
			// 2. Filtered aggregate — subquery applies WHERE before aggregation.
			if ($aggregate->getConditions() !== null) {
				return self::STRATEGY_SUBQUERY;
			}
			
			// 3. Aggregate-only query — execute directly, no GROUP BY required.
			if ($isAggregateOnly) {
				return self::STRATEGY_DIRECT;
			}
			
			// 4. Window function — avoids GROUP BY for single-table mixed queries.
			if ($this->canRewriteAsWindowFunction($root, $aggregate)) {
				return self::STRATEGY_WINDOW;
			}
			
			// 5. Mixed query — use GROUP BY if ranges overlap, otherwise isolate
			//    the aggregate in a subquery to avoid a cross-product.
			$nonAggRanges = RangeUtilities::collectRangesFromNodes($nonAggItems);
			
			return RangeUtilities::rangesOverlapOrAreRelated($aggRanges, $nonAggRanges)
				? self::STRATEGY_DIRECT
				: self::STRATEGY_SUBQUERY;
		}
		
		/**
		 * Returns true when every range in $aggRanges is a non-database source.
		 *
		 * An empty range list returns false — an aggregate over no ranges at all
		 * is not a valid non-database aggregate; it indicates a malformed node
		 * and should fall through to the normal strategy checks.
		 *
		 * @param array<int, mixed> $aggRanges
		 * @return bool
		 */
		private function allRangesAreNonDatabase(array $aggRanges): bool {
			return !empty($aggRanges) && array_reduce(
					$aggRanges,
					fn(bool $carry, $range) => $carry && $range instanceof AstRangeJsonSource,
					true
				);
		}
		
		// ---------------------------------------------------------------------
		// QUERY STRUCTURE ANALYSIS
		// ---------------------------------------------------------------------
		
		/**
		 * Build a hash-set of ranges referenced by at least one aggregate.
		 * Used to distinguish filter-only joins from data-producing joins.
		 *
		 * @param AstRetrieve $root
		 * @return array<string, true> spl_object_hash(AstRange) => true
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
		// WINDOW FUNCTION ELIGIBILITY
		// ---------------------------------------------------------------------
		
		/**
		 * Returns true if the aggregate can be safely rewritten as a window function.
		 *
		 * All four conditions must hold:
		 *  - The aggregate type supports window syntax and the platform allows it.
		 *  - The query has exactly one range — multi-table partitioning is not supported.
		 *  - The aggregate references that same single range — cross-range window
		 *    semantics would produce incorrect results.
		 *  - Every other SELECT item references the same single range — mixed
		 *    references would create inconsistent row counts after the rewrite.
		 * @param AstRetrieve $root
		 * @param AstAggregate $aggregate
		 * @return bool
		 */
		private function canRewriteAsWindowFunction(AstRetrieve $root, AstAggregate $aggregate): bool {
			if (!$this->passesWindowFunctionBasics($aggregate)) {
				return false;
			}
			
			$queryRanges = $root->getRanges();
			
			if (count($queryRanges) !== 1) {
				return false;
			}
			
			$singleRange = $queryRanges[0];
			
			return $this->aggregateMatchesQueryRange($aggregate, $singleRange)
				&& $this->selectItemsAreUniform($root, $aggregate, $singleRange);
		}
		
		/**
		 * Returns true if the aggregate references exactly the given range and no other.
		 */
		/**
		 * @param AstAggregate $aggregate
		 * @param object $singleRange The single range the query is expected to use
		 * @return bool
		 */
		private function aggregateMatchesQueryRange(AstAggregate $aggregate, object $singleRange): bool {
			$ranges = RangeUtilities::collectRangesFromNode($aggregate);
			return count($ranges) === 1 && $ranges[0] === $singleRange;
		}
		
		/**
		 * Returns true if every SELECT item other than $aggregate references exactly $singleRange.
		 */
		/**
		 * @param AstRetrieve $root
		 * @param AstAggregate $aggregate The aggregate being evaluated (excluded from the check)
		 * @param object $singleRange Expected range for all other select items
		 * @return bool
		 */
		private function selectItemsAreUniform(AstRetrieve $root, AstAggregate $aggregate, object $singleRange): bool {
			foreach ($root->getValues() as $selectItem) {
				if ($selectItem->getExpression() === $aggregate) {
					continue;
				}
				
				$itemRanges = RangeUtilities::collectRangesFromNode($selectItem->getExpression());
				
				if (count($itemRanges) !== 1 || $itemRanges[0] !== $singleRange) {
					return false;
				}
			}
			
			return true;
		}
		
		/**
		 * Returns true if the aggregate passes basic window function prerequisites:
		 * no filter conditions, not a DISTINCT variant, and the platform supports windows.
		 *
		 * Conditions are checked cheapest-first: filter check is a simple null test,
		 * DISTINCT check is an array lookup, platform capability is last since it may
		 * involve a capability query.
		 * @param AstAggregate $aggregate
		 * @return bool
		 */
		private function passesWindowFunctionBasics(AstAggregate $aggregate): bool {
			// Window functions cannot have their own filter conditions.
			if ($aggregate->getConditions() !== null) {
				return false;
			}
			
			// DISTINCT variants are commonly unsupported in window context.
			if (in_array(get_class($aggregate), AggregateConstants::DISTINCT_AGGREGATE_TYPES, true)) {
				return false;
			}
			
			if (!$this->platform->supportsWindowFunctions()) {
				return false;
			}
			
			return in_array(get_class($aggregate), AggregateConstants::NOT_DISTINCT_AGGREGATE_TYPES, true);
		}
	}