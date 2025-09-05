<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Execution\Support\AstExpressionFactory;
	use Quellabs\ObjectQuel\Execution\Support\AstFactory;
	use Quellabs\ObjectQuel\Execution\Support\AstNodeReplacer;
	use Quellabs\ObjectQuel\Execution\Support\AstUtilities;
	use Quellabs\ObjectQuel\Execution\Support\JoinPredicateProcessor;
	use Quellabs\ObjectQuel\Execution\Support\AnchorManager;
	use Quellabs\ObjectQuel\Execution\Support\RangePartitioner;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
	/**
	 * ──────────────────────────────────────────────────────────────────────────────
	 * ANY OPTIMIZER – READING GUIDE
	 * ──────────────────────────────────────────────────────────────────────────────
	 *
	 * What this class does
	 * ----------------------------
	 * It rewrites ANY(...) constructs into subqueries with a minimized set of ranges,
	 * promotes correlation-only predicates from JOINs into the WHERE clause, and
	 * guarantees a single "anchor" range (a range with joinProperty == null).
	 * Depending on the location of ANY(...) we emit an EXISTS-style or CASE WHEN-style
	 * AstSubquery.
	 *
	 * Terminology
	 * -----------
	 * - Range: a FROM/JOIN source (AstRange). Each range may have a JOIN predicate
	 *   (its "joinProperty") and a "required" flag (true = INNER, false = LEFT).
	 * - Anchor: exactly one range per subquery must have joinProperty == null. It
	 *   serves as the root for the subquery. We move this anchor to the front.
	 * - Live range: referenced by the ANY expression or its WHERE conditions (directly).
	 * - Correlation-only range: not live by itself but mentioned inside some other
	 *   range's JOIN predicate. Its conditions should not remain in JOINs; we promote
	 *   them into the WHERE of the subquery so the live part stays minimal.
	 *
	 * High-level pipeline (in optimizeAnyNode)
	 * ---------------------------------------
	 * 1) Clone ranges (don't mutate the original tree yet).
	 * 2) Analyze usage (RangeUsageAnalyzer) => four boolean maps per range:
	 *      - usedInExpr, usedInCond, hasIsNullInCond, nonNullableUse
	 * 3) Compute join cross-references once (which JOIN(k) mentions which range r).
	 * 4) Partition ranges into "live" and "correlation-only".
	 * 5) If nothing is live, fallback to the expr range(s) or the first range.
	 * 6) For each live range's JOIN: split ON predicate into:
	 *      - innerPart (references only live ranges)       => stays in JOIN
	 *      - corrPart  (references only correlation ranges) => moved to WHERE
	 *    If a conjunct mixes both sides and contains OR, we keep it as innerPart
	 *    (i.e., we don't split to preserve semantics).
	 * 7) Keep only live ranges (drop others).
	 * 8) Ensure exactly one anchor (joinProperty == null); prefer:
	 *      a) expr range if INNER or safely collapsible LEFT
	 *      b) any existing INNER
	 *      c) a LEFT that is safe to collapse to INNER
	 *    Safety uses the four analyzer maps; **no single-use visitors are created**.
	 * 9) Replace the ANY(...) node with an AstSubquery (EXISTS or CASE WHEN).
	 *
	 * Key invariants
	 * --------------
	 * - We never change semantics when unsure: "unsafe to split" cases remain intact.
	 * - Exactly one anchor range is produced when possible; else we keep the original
	 *   ordering/joins (still correct, just less optimal).
	 *
	 * Implementation note
	 * -------------------
	 * All non-trivial decisions use precomputed maps from RangeUsageAnalyzer. This
	 * file does not instantiate any single-use "ContainsXxx" visitors.
	 */
	class AnyOptimizer {
		
		/** @var EntityStore Metadata store (used indirectly through the analyzer). */
		private EntityStore $entityStore;
		
		/** @var RangeUsageAnalyzer Reused analyzer: liveness / nullability maps, etc. */
		private RangeUsageAnalyzer $analyzer;
		
		/**
		 * AnyOptimizer constructor
		 * @param EntityManager $entityManager Provides entity metadata access.
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityStore = $entityManager->getEntityStore();
			$this->analyzer = new RangeUsageAnalyzer($this->entityStore);
		}
		
		/**
		 * Entry point: find all ANY nodes in the retrieve AST and optimize them.
		 * @param AstRetrieve $ast Root query AST.
		 * @return void
		 */
		public function optimize(AstRetrieve $ast): void {
			foreach (AstUtilities::findAllAnyNodes($ast) as $node) {
				// The context of the ANY node decides the subquery shape we emit.
				switch ($ast->getLocationOfChild($node)) {
					case 'select':
						// ANY(...) in SELECT becomes a CASE WHEN subquery (value context).
						$this->optimizeAnyNode($ast, $node, AstSubquery::TYPE_CASE_WHEN);
						break;
					
					case 'conditions':
						// ANY(...) in WHERE becomes an EXISTS subquery (boolean context).
						$this->optimizeAnyNode($ast, $node, AstSubquery::TYPE_EXISTS);
						break;
					
					case 'order_by':
						// We currently do not perform ANY-specific rewrites in ORDER BY.
						break;
				}
			}
		}
		
		/**
		 * Perform the full ANY(...) rewrite for a single node.
		 * The method is intentionally kept as a sequence of small, well-commented steps.
		 * @param AstRetrieve $ast Root AST.
		 * @param AstAny $node ANY node to rewrite.
		 * @param string $subQueryType Subquery type (EXISTS | CASE WHEN).
		 * @return void
		 * @throws QuelException
		 */
		private function optimizeAnyNode(AstRetrieve $ast, AstAny $node, string $subQueryType): void {
			// ── Step 0: Work on cloned ranges so we don't mutate the original AST until the end.
			$ranges = $this->cloneQueryRanges($ast);
			
			// ── Step 1: Usage analysis (one pass).
			//     The analyzer returns four boolean maps keyed by range name.
			//     We rely on these maps for decisions.
			/** @var array{usedInExpr:array<string,bool>, usedInCond:array<string,bool>, hasIsNullInCond:array<string,bool>, nonNullableUse:array<string,bool>} $usage */
			$usage = $this->analyzer->analyze($node, $ranges);
			$usedInExpr = $usage['usedInExpr'];            // Is range referenced in ANY(expr) ?
			$usedInCond = $usage['usedInCond'];            // Is range referenced in ANY(WHERE ...) ?
			$hasIsNullInCond = $usage['hasIsNullInCond'];  // Does ANY(WHERE ...) rely on IS NULL for this range ?
			$nonNullableUse = $usage['nonNullableUse'];    // Are references only on non-nullable fields ?
			
			// ── Step 2: Compute which JOIN(k) predicates reference which ranges.
			//            This allows us to tell if a range is used only via other JOINs (correlation-only).
			$joinReferences = RangePartitioner::buildJoinReferenceMap($ranges);
			
			// ── Step 3: Partition the ranges into "live" and "correlation-only".
			//            Live ranges are those directly used in expr/cond; correlation-only
			//            appear only inside others' JOINs.
			$liveRanges = RangePartitioner::computeLiveRanges($ranges, $usedInExpr, $usedInCond);
			$correlationOnlyRanges = RangePartitioner::computeCorrelationOnlyRanges($ranges, $joinReferences, $usedInCond, $usedInCond);
			
			// ── Step 4: Ensure there's at least one live range.
			//     If analyzer yielded none, we fallback to expr ranges or the first range.
			if (empty($liveRanges) && !empty($ranges)) {
				$liveRanges = RangePartitioner::selectFallbackLiveRanges($ranges, $node);
			}
			
			// ── Step 5: Promote correlation-only pieces of JOIN predicates into WHERE.
			//     Only process JOINs of "live" ranges; others will be dropped anyway.
			$liveRangeNames = array_keys($liveRanges);
			$correlationRangeNames = array_keys($correlationOnlyRanges);
			$updatedRanges = JoinPredicateProcessor::buildUpdatedRangesWithInnerJoinsOnly($ranges, $liveRanges, $liveRangeNames, $correlationRangeNames);
			$promotedPredicates = JoinPredicateProcessor::gatherCorrelationOnlyPredicatesFromJoins($ranges, $liveRanges, $liveRangeNames, $correlationRangeNames);
			
			// ── Step 6: Build final WHERE: original ANY WHERE AND all promoted correlation predicates.
			$finalWhere = AstUtilities::combinePredicatesWithAnd([
				$node->getConditions(),
				AstUtilities::combinePredicatesWithAnd($promotedPredicates)
			]);
			
			// ── Step 7: Drop non-live ranges (keeping order of the survivors).
			$keptRanges = RangePartitioner::filterToLiveRangesOnly($updatedRanges, $liveRanges);
			
			// ── Step 8: Ensure exactly one anchor (joinProperty == null).
			//     We try to anchor a range used in expr, else any INNER, else collapse a safe LEFT.
			$keptRanges = AnchorManager::configureRangeAnchors(
				$keptRanges,
				$finalWhere,
				$usedInExpr,
				$usedInCond,
				$hasIsNullInCond,
				$nonNullableUse
			);
			
			// ── Step 9: Replace ANY(...) with the chosen subquery form.
			if ($this->canUseInlinedAnyOptimization($subQueryType, $finalWhere, $keptRanges, $ast, $node)) {
				$this->replaceAnyWithInlinedValue($ast, $node);
			} else {
				$this->replaceAnyWithSubquery($subQueryType, $keptRanges, $finalWhere, $node);
			}
		}
		
		/**
		 * Deep clone of the query ranges for safe, local mutations.
		 * @param AstRetrieve $ast Root query AST
		 * @return AstRange[] Cloned ranges array
		 */
		private function cloneQueryRanges(AstRetrieve $ast): array {
			$result = [];
			
			foreach ($ast->getRanges() as $range) {
				$result[] = $range->deepClone();
			}
			
			return $result;
		}
		
		/**
		 * Decide if we can inline ANY(...) as a literal without a subselect.
		 * @param string $subQueryType Type of subquery (EXISTS or CASE WHEN)
		 * @param AstInterface|null $finalWhere Final WHERE clause
		 * @param AstRange[] $keptRanges Ranges that will be kept
		 * @param AstRetrieve $ast Root query AST
		 * @param AstAny $node ANY node being optimized
		 * @return bool True if inlining optimization can be applied
		 */
		private function canUseInlinedAnyOptimization(string $subQueryType, ?AstInterface $finalWhere, array $keptRanges, AstRetrieve $ast, AstAny $node): bool {
			return
				$subQueryType === AstSubquery::TYPE_CASE_WHEN
				&& $finalWhere === null
				&& count($keptRanges) === 1
				&& $this->isAnyNodeInSelectClause($ast, $node);
		}
		
		/**
		 * Apply the optimized inlining: ANY(...) → 1, and cap to a single row.
		 * @param AstRetrieve $ast Root query AST
		 * @param AstAny $node ANY node to replace
		 * @return void
		 */
		private function replaceAnyWithInlinedValue(AstRetrieve $ast, AstAny $node): void {
			AstNodeReplacer::replaceChild(
				$node->getParent(),
				$node,
				AstFactory::createNumber(1)
			);
			
			if ($ast->getWindow() === null) {
				$ast->setWindow(0);
				$ast->setWindowSize(1);
			}
		}
		
		/**
		 * Replace ANY node with a regular subquery form.
		 * @param string $subQueryType Type of subquery (EXISTS or CASE WHEN)
		 * @param AstRange[] $correlatedRanges Ranges to include in subquery
		 * @param AstInterface|null $conditions WHERE clause for subquery
		 * @param AstAny $node ANY node to replace
		 * @return void
		 */
		private function replaceAnyWithSubquery(string $subQueryType, array $correlatedRanges, ?AstInterface $conditions, AstAny $node): void {
			if ($subQueryType === AstSubquery::TYPE_CASE_WHEN) {
				$subQuery = AstExpressionFactory::createCaseWhen($correlatedRanges, $conditions,"ANY");
			} else {
				$subQuery = AstExpressionFactory::createExists(AstFactory::createNumber(1), $correlatedRanges, $conditions,"ANY");
			}

			AstNodeReplacer::replaceChild($node->getParent(), $node, $subQuery);
		}
		
		/**
		 * Check whether the given ANY(...) node is a simple top-level projection.
		 * @param AstRetrieve $ast Root query AST
		 * @param AstAny $node ANY node to check
		 * @return bool True if ANY is in SELECT clause
		 */
		private function isAnyNodeInSelectClause(AstRetrieve $ast, AstAny $node): bool {
			return $ast->getLocationOfChild($node) === 'select';
		}
	}