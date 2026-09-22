<?php
	
	namespace Quellabs\ObjectQuel\Planner\Optimizers;
	
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\Capabilities\NullPlatformCapabilities;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAggregate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
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
		
		// Strategy constants — each encodes both the rewrite action and the reason
		// for the decision, so plan log messages are self-explanatory.
		private const string STRATEGY_MEMORY = 'MEMORY:non-database range, evaluated in memory';
		private const string STRATEGY_SUBQUERY_FILTERED = 'SUBQUERY:has WHERE condition, isolated in subquery';
		private const string STRATEGY_DIRECT_AGG_ONLY = 'DIRECT:aggregate-only query, no GROUP BY needed';
		private const string STRATEGY_WINDOW = 'WINDOW:single-table mixed query, rewritten as window function';
		private const string STRATEGY_DIRECT_OVERLAP = 'DIRECT:ranges overlap, kept inline with GROUP BY';
		private const string STRATEGY_SUBQUERY_DISJOINT = 'SUBQUERY:disjoint ranges, isolated in correlated subquery';
		private const string STRATEGY_DIRECT_EXPLICIT_GROUP = 'DIRECT:explicit `by` list used as GROUP BY';
		
		/** @var EntityStore Provides entity metadata, used to exclude primary key columns from partition inference */
		private EntityStore $entityStore;

		/** @var PlatformCapabilitiesInterface Database engine capability descriptor */
		private PlatformCapabilitiesInterface $platform;

		/**
		 * AggregateOptimizer constructor
		 * @param EntityStore $entityStore Provides entity metadata for primary key lookups
		 * @param PlatformCapabilitiesInterface $platform Database engine capability descriptor
		 */
		public function __construct(EntityStore $entityStore, PlatformCapabilitiesInterface $platform = new NullPlatformCapabilities()) {
			$this->entityStore = $entityStore;
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
		 * @param AstRetrieve $root
		 * @param AstAggregate[] $aggregates Stable snapshot collected before any mutation
		 * @param bool $isAggregateOnly Pre-computed query shape flag
		 * @param PlanLogInterface $log
		 */
		private function applyAggregateStrategies(AstRetrieve $root, array $aggregates, bool $isAggregateOnly, PlanLogInterface $log): void {
			// Non-aggregate items are invariant per query — compute once outside the loop.
			$nonAggItems = AstUtilities::collectNonAggregateSelectItems($root);

			// An aggregate-only query normally collapses every aggregate into one summary
			// row (STRATEGY_DIRECT_AGG_ONLY). A sequence function or running aggregate
			// can't collapse — it emits one row per input row — so once any aggregate in
			// the query requires the window strategy, every other true aggregate must use
			// it too, or the result set's row count would be ambiguous. Treating the query
			// as "not aggregate-only" for strategy selection routes every aggregate through
			// the window-eligibility check instead of the collapsing shortcut.
			$forceWindow = $isAggregateOnly && $this->anyAggregateRequiresWindow($aggregates);

			// An explicit inline `by` on a plain aggregate is a query-wide GROUP BY
			// override — collected once and validated for consistency across every
			// aggregate that specifies one (see collectExplicitGroupByOverride()).
			$explicitGroupBy = $this->collectExplicitGroupByOverride($aggregates);

			if ($forceWindow && $explicitGroupBy !== null) {
				throw new QuelException(
					'Cannot mix a windowed sequence function or running aggregate with a plain aggregate ' .
					'using an explicit `by` group in the same aggregate-only query — their row cardinalities ' .
					'are incompatible.'
				);
			}

			// An explicit `by` group forces real GROUP BY behavior even in an otherwise
			// aggregate-only query shape, since it can group by a column not otherwise
			// selected at all — the collapsing "no GROUP BY needed" shortcut no longer applies.
			$effectiveAggregateOnly = $isAggregateOnly && !$forceWindow && $explicitGroupBy === null;

			foreach ($aggregates as $agg) {
				$strategy = $this->chooseStrategy($root, $agg, $effectiveAggregateOnly, $nonAggItems, $explicitGroupBy !== null);

				if ($forceWindow && $strategy !== self::STRATEGY_WINDOW) {
					throw new QuelException(
						'Cannot mix ' . $agg->getType() . '() with a sequence function or running aggregate ' .
						'in the same aggregate-only query — every aggregate must independently qualify for ' .
						'the window-function strategy (single range, no WHERE, no DISTINCT).'
					);
				}

				$this->applyStrategy($root, $agg, $strategy, $effectiveAggregateOnly, $nonAggItems, $explicitGroupBy, $log);
			}
		}

		/**
		 * Collects a query-wide GROUP BY override from every non-window aggregate's
		 * explicit inline `by` list, validating that every aggregate which specifies
		 * one specifies the *same* columns — GROUP BY is a single query-wide clause,
		 * so conflicting lists across aggregates in the same query are ambiguous.
		 * @param AstAggregate[] $aggregates
		 * @return AstInterface[]|null Shared `by` list, or null if none was specified
		 * @throws QuelException on conflicting `by` lists
		 */
		private function collectExplicitGroupByOverride(array $aggregates): ?array {
			$selected = null;
			$selectedNames = null;

			foreach ($aggregates as $aggregate) {
				// Sequence functions / running aggregates use `by` for PARTITION BY instead.
				if ($this->requiresWindowFunction($aggregate)) {
					continue;
				}

				$partitionBy = $aggregate->getPartitionBy();

				if ($partitionBy === null) {
					continue;
				}

				// Compare by column name where possible; a computed expression falls back
				// to object identity, so mixing one across multiple aggregates always
				// reports a conflict rather than risking a false match.
				$names = array_map(
					static fn(AstInterface $expression): string => $expression instanceof AstIdentifier
						? $expression->getCompleteName()
						: spl_object_hash($expression),
					$partitionBy
				);

				if ($selectedNames === null) {
					$selected = $partitionBy;
					$selectedNames = $names;
				} elseif ($names !== $selectedNames) {
					throw new QuelException(
						'Conflicting explicit `by` lists across aggregates in the same query — ' .
						'every aggregate\'s `by` list must specify the same columns.'
					);
				}
			}

			return $selected;
		}

		/**
		 * Returns true if any aggregate in the list has no non-windowed SQL form —
		 * a sequence function (rank, lag, ...) or any aggregate using an inline `sort by`.
		 * @param AstAggregate[] $aggregates
		 * @return bool
		 */
		private function anyAggregateRequiresWindow(array $aggregates): bool {
			foreach ($aggregates as $aggregate) {
				if ($this->requiresWindowFunction($aggregate)) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Returns true if the aggregate has no non-windowed SQL form: either it's one
		 * of the sequence function types (rank, dense_rank, row_number, ntile, lag, lead),
		 * or it's a plain aggregate (sum, count, avg, min, max) using an inline `sort by`
		 * to compute a running value. A bare inline `by` with no `sort by` does NOT
		 * require a window — it's an explicit GROUP BY override instead (see
		 * collectExplicitGroupByOverride()).
		 * @param AstAggregate $aggregate
		 * @return bool
		 */
		private function requiresWindowFunction(AstAggregate $aggregate): bool {
			return
				$aggregate->getOrder() !== null ||
				in_array(get_class($aggregate), AggregateConstants::SEQUENCE_AGGREGATE_TYPES, true);
		}
		
		/**
		 * Apply a chosen strategy to a single aggregate node and emit a plan log entry.
		 * @param AstRetrieve $root
		 * @param AstAggregate $agg The aggregate node to rewrite
		 * @param string $strategy One of self::STRATEGY_*
		 * @param bool $isAggregateOnly Pre-computed query shape flag (already accounts for an explicit `by` group)
		 * @param AstAlias[] $nonAggItems Non-aggregate SELECT items (used for GROUP BY inference)
		 * @param AstInterface[]|null $explicitGroupBy Query-wide `by` override, if any
		 * @param PlanLogInterface $log
		 */
		private function applyStrategy(AstRetrieve $root, AstAggregate $agg, string $strategy, bool $isAggregateOnly, array $nonAggItems, ?array $explicitGroupBy, PlanLogInterface $log): void {
			$label = $agg->getType();

			switch ($strategy) {
				case self::STRATEGY_DIRECT_AGG_ONLY:
				case self::STRATEGY_DIRECT_OVERLAP:
				case self::STRATEGY_DIRECT_EXPLICIT_GROUP:
					// GROUP BY is only needed when mixing aggregates with non-aggregates,
					// or when an explicit `by` group forces it even in an aggregate-only shape.
					// An explicit group always wins over inference from the SELECT list.
					if (!$isAggregateOnly) {
						$root->setGroupBy($explicitGroupBy ?? $nonAggItems);
					}
					break;
				
				case self::STRATEGY_SUBQUERY_FILTERED:
				case self::STRATEGY_SUBQUERY_DISJOINT:
					AggregateRewriter::rewriteAggregateAsCorrelatedSubquery($root, $agg);
					break;
				
				case self::STRATEGY_WINDOW:
					// An explicit inline `by` list always wins. Otherwise, non-aggregate
					// SELECT items become the window's PARTITION BY — the same "other
					// columns imply grouping" rule ObjectQuel already uses to infer GROUP
					// BY for the DIRECT strategies above — except:
					//  - the range's own primary key, which is virtually always displayed
					//    for row identity alongside a sequence function (rank, lag, ...),
					//    where including it would put every row in its own partition;
					//  - any column the aggregate itself orders by, which is virtually
					//    always displayed too (e.g. `rank(sort by o.published)` while also
					//    selecting o.published) — including it would fold every distinct
					//    value of that column into its own partition, making the rank
					//    trivially 1 for every row.
					$partitionColumns = AstUtilities::buildPartitionItemsFromExplicitBy($agg)
						?? AstUtilities::excludeAggregateOrderColumns($agg, $this->excludePrimaryKeyItems($nonAggItems));
					AggregateRewriter::rewriteAggregateAsWindowFunction($agg, $partitionColumns);
					break;
				
				case self::STRATEGY_MEMORY:
					// Non-database aggregate — evaluated in memory by ConditionEvaluator.
					// No SQL rewrite applies; leave the AST node untouched.
					break;
			}
			
			$strategyExploded = explode(':', $strategy, 2);
			$log->note('optimizer', 'aggregate', $strategyExploded[0], $this->describeAggregate($agg) . ': ' . $strategyExploded[1], $label);
		}
		
		/**
		 * Returns a short human-readable description of an aggregate for plan log messages.
		 * Examples: "SUM(o.id)", "COUNT(1)", "ANY(o.title)"
		 * @param AstAggregate $aggregate
		 * @return string
		 */
		private function describeAggregate(AstAggregate $aggregate): string {
			$identifier = $aggregate->getIdentifier();
			
			if ($identifier instanceof AstIdentifier) {
				$arg = $identifier->getCompleteName();
			} elseif ($identifier instanceof AstNumber) {
				$arg = $identifier->getValue();
			} else {
				$arg = '*';
			}
			
			return $aggregate->getType() . '(' . $arg . ')';
		}
		
		// ---------------------------------------------------------------------
		// STRATEGY SELECTION
		// ---------------------------------------------------------------------
		
		/**
		 * Pick the best evaluation strategy for a given aggregate within the query.
		 *
		 * Priority order:
		 *  1. Non-database source     → MEMORY   (JSON ranges, evaluated in PHP)
		 *  2. Window-required         → WINDOW   (sequence function or running aggregate)
		 *  3. Explicit `by` group     → DIRECT + explicit GROUP BY (wins over everything below)
		 *  4. Filtered aggregate      → SUBQUERY (WHERE must run inside the aggregate)
		 *  5. Aggregate-only query    → DIRECT   (no GROUP BY needed)
		 *  6. Window-eligible         → WINDOW   (single-table mixed query, avoids GROUP BY)
		 *  7. Ranges overlap          → DIRECT + GROUP BY
		 *  8. Fallback                → SUBQUERY
		 *
		 * Step 3 comes before step 4 so that an aggregate with both an explicit `by`
		 * and its own `where` (e.g. `avg(e.age by e.dept where e.job=1023)`) is routed
		 * to the plain DIRECT strategy, whose SQL generation already renders the
		 * aggregate's own conditions as a CASE WHEN — not to the correlated-subquery
		 * strategy, which assumes a per-row correlation this shape doesn't have.
		 *
		 * Step 6 is checked before the range-overlap test (step 7) because a
		 * single-table mixed query is a valid window candidate and avoids the
		 * GROUP BY entirely — more efficient than DIRECT for that shape.
		 *
		 * @param AstRetrieve $root
		 * @param AstAggregate $aggregate Aggregate node to analyze
		 * @param bool $isAggregateOnly Pre-computed query shape flag
		 * @param AstAlias[] $nonAggItems Pre-computed non-aggregate SELECT items
		 * @param bool $hasExplicitGroupBy True when some aggregate in the query specified an inline `by`
		 * @return string One of self::STRATEGY_*
		 */
		private function chooseStrategy(AstRetrieve $root, AstAggregate $aggregate, bool $isAggregateOnly, array $nonAggItems, bool $hasExplicitGroupBy): string {
			$aggRanges = RangeUtilities::collectRangesFromNode($aggregate);
			$needsWindow = $this->requiresWindowFunction($aggregate);

			// 1. All ranges are non-database (e.g. JSON) — evaluate in memory.
			//    Sequence functions / running aggregates have no in-memory equivalent —
			//    they require a real SQL window function.
			if ($this->isNonDatabaseAggregate($aggRanges)) {
				if ($needsWindow) {
					throw new QuelException(
						$aggregate->getType() . '() requires SQL window function support; it cannot run against a non-database range.'
					);
				}

				return self::STRATEGY_MEMORY;
			}

			// 2. Sequence functions / running aggregates have no non-windowed SQL form at
			//    all — this must be decided before every other strategy, including the
			//    aggregate-only shortcut below, or the node would be left unrewritten.
			if ($needsWindow) {
				if (!$this->canUseWindowFunction($root, $aggregate)) {
					throw new QuelException(
						$aggregate->getType() . '() could not be planned as a window function — this requires ' .
						'a single-range query where every SELECT item references that range, and a database ' .
						'engine with window function support.'
					);
				}

				return self::STRATEGY_WINDOW;
			}

			// 3. An explicit `by` group (on this or a sibling aggregate) always wins —
			//    guard against grouping across genuinely unrelated ranges, which would
			//    otherwise risk a silent cross-product once forced inline.
			if ($hasExplicitGroupBy) {
				$nonAggRanges = RangeUtilities::collectRangesFromNodes($nonAggItems);

				if ($nonAggRanges !== [] && !RangeUtilities::rangesOverlapOrAreRelated($aggRanges, $nonAggRanges)) {
					throw new QuelException(
						$aggregate->getType() . '() with an explicit `by` group over unrelated ranges is not yet supported.'
					);
				}

				return self::STRATEGY_DIRECT_EXPLICIT_GROUP;
			}

			// 4. Filtered aggregate — subquery applies WHERE before aggregation.
			if ($aggregate->getConditions() !== null) {
				return self::STRATEGY_SUBQUERY_FILTERED;
			}

			// 5. Aggregate-only query — execute directly, no GROUP BY required.
			if ($isAggregateOnly) {
				return self::STRATEGY_DIRECT_AGG_ONLY;
			}

			// 6. Window function — avoids GROUP BY for single-table mixed queries.
			if ($this->canUseWindowFunction($root, $aggregate)) {
				return self::STRATEGY_WINDOW;
			}

			// 7. Mixed query — use GROUP BY if ranges overlap, otherwise isolate
			//    the aggregate in a subquery to avoid a cross-product.
			$nonAggRanges = RangeUtilities::collectRangesFromNodes($nonAggItems);

			return RangeUtilities::rangesOverlapOrAreRelated($aggRanges, $nonAggRanges)
				? self::STRATEGY_DIRECT_OVERLAP
				: self::STRATEGY_SUBQUERY_DISJOINT;
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
		private function isNonDatabaseAggregate(array $aggRanges): bool {
			if (empty($aggRanges)) {
				return false;
			}

			foreach ($aggRanges as $range) {
				if (!$range instanceof AstRangeJsonSource) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Filters out non-aggregate SELECT items that are bare references to their
		 * range's declared primary key, before they're used as a window's PARTITION BY.
		 * Shared with WindowChainRewriter, which propagates the same exclusion into
		 * a helper query's own partition columns.
		 * @param AstAlias[] $nonAggItems
		 * @return AstAlias[]
		 */
		private function excludePrimaryKeyItems(array $nonAggItems): array {
			return AstUtilities::excludePrimaryKeyItems($this->entityStore, $nonAggItems);
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
		private function canUseWindowFunction(AstRetrieve $root, AstAggregate $aggregate): bool {
			if (!$this->isWindowFunctionEligible($aggregate)) {
				return false;
			}

			// WindowChainRewriter-added helper ranges are derived tables joined 1:1
			// on primary key back to the query's real range — they never change row
			// cardinality, so they're excluded from the "single range" count that
			// guards against genuine multi-table partitioning.
			$queryRanges = $this->excludeHelperRanges($root, $root->getRanges());

			if (count($queryRanges) !== 1) {
				return false;
			}

			$singleRange = $queryRanges[0];

			return
				$this->aggregateUsesRange($root, $aggregate, $singleRange) &&
				$this->allSelectItemsUseRange($root, $aggregate, $singleRange);
		}

		/**
		 * Filters out ranges that WindowChainRewriter added as sequence-function
		 * helper ranges (see AstRetrieve::$window_chain_helper_ranges).
		 * @param AstRetrieve $root
		 * @param AstRange[] $ranges
		 * @return AstRange[]
		 */
		private function excludeHelperRanges(AstRetrieve $root, array $ranges): array {
			return array_values(array_filter(
				$ranges,
				fn($range) => !$root->isWindowChainHelperRange($range->getName())
			));
		}

		/**
		 * Returns true if the aggregate references exactly the given range and no
		 * other, ignoring any WindowChainRewriter helper ranges it may also reference.
		 * @param AstRetrieve $root
		 * @param AstAggregate $aggregate
		 * @param object $singleRange The single range the query is expected to use
		 * @return bool
		 */
		private function aggregateUsesRange(AstRetrieve $root, AstAggregate $aggregate, object $singleRange): bool {
			$ranges = $this->excludeHelperRanges($root, RangeUtilities::collectRangesFromNode($aggregate));
			return count($ranges) === 1 && $ranges[0] === $singleRange;
		}

		/**
		 * Returns true if every SELECT item other than $aggregate references exactly
		 * $singleRange, ignoring any WindowChainRewriter helper ranges also referenced.
		 * @param AstRetrieve $root
		 * @param AstAggregate $aggregate The aggregate being evaluated (excluded from the check)
		 * @param object $singleRange Expected range for all other select items
		 * @return bool
		 */
		private function allSelectItemsUseRange(AstRetrieve $root, AstAggregate $aggregate, object $singleRange): bool {
			foreach ($root->getValues() as $selectItem) {
				if ($selectItem->getExpression() === $aggregate) {
					continue;
				}

				$itemRanges = $this->excludeHelperRanges($root, RangeUtilities::collectRangesFromNode($selectItem->getExpression()));

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
		private function isWindowFunctionEligible(AstAggregate $aggregate): bool {
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

			// Sequence functions (rank, lag, ...) and any aggregate using an inline
			// `sort by` (a running total) have no non-windowed SQL form at all.
			if ($this->requiresWindowFunction($aggregate)) {
				return true;
			}

			return in_array(get_class($aggregate), AggregateConstants::NOT_DISTINCT_AGGREGATE_TYPES, true);
		}
	}