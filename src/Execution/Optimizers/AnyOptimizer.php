<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	
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
		
		/** @var RangePartitioner Handles range partitioning and filtering based on analysis results. */
		private RangePartitioner $rangePartitioner;
		
		/** @var JoinPredicateProcessor Processes JOIN predicates and extracts correlation parts. */
		private JoinPredicateProcessor $joinPredicateProcessor;
		
		/** @var AnchorManager Manages anchor range selection and ensures single anchor. */
		private AnchorManager $anchorManager;
		
		/** @var Support\AstUtilities Utility methods for AST operations. */
		private Support\AstUtilities $astUtilities;
		
		/** @var Support\AstNodeReplacer AST replacement */
		private Support\AstNodeReplacer $nodeReplacer;
		
		/**
		 * AnyOptimizer constructor
		 * @param EntityManager $entityManager Provides entity metadata access.
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityStore = $entityManager->getEntityStore();
			$this->analyzer = new RangeUsageAnalyzer($this->entityStore);
			$this->astUtilities = new Support\AstUtilities();
			$this->nodeReplacer = new Support\AstNodeReplacer();
			$this->rangePartitioner = new RangePartitioner($this->astUtilities);
			$this->joinPredicateProcessor = new JoinPredicateProcessor($this->astUtilities);
			$this->anchorManager = new AnchorManager($this->astUtilities);
		}
		
		/**
		 * Entry point: find all ANY nodes in the retrieve AST and optimize them.
		 * @param AstRetrieve $ast Root query AST.
		 * @return void
		 */
		public function optimize(AstRetrieve $ast): void {
			foreach ($this->findAllAnyNodes($ast) as $node) {
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
			//     This allows us to tell if a range is used only via other JOINs (correlation-only).
			$joinReferences = $this->rangePartitioner->buildJoinReferenceMap($ranges);
			
			// ── Step 3: Partition the ranges into "live" and "correlation-only".
			//     Live ranges are those directly used in expr/cond; correlation-only appear only inside others' JOINs.
			[$liveRanges, $correlationOnlyRanges] = $this->rangePartitioner->separateLiveAndCorrelationRanges(
				$ranges, $usedInExpr, $usedInCond, $joinReferences
			);
			
			// ── Step 4: Ensure there's at least one live range.
			//     If analyzer yielded none, we fallback to expr ranges or the first range.
			if (empty($liveRanges) && !empty($ranges)) {
				$liveRanges = $this->rangePartitioner->selectFallbackLiveRanges($ranges, $node);
			}
			
			// ── Step 5: Promote correlation-only pieces of JOIN predicates into WHERE.
			//     Only process JOINs of "live" ranges; others will be dropped anyway.
			$liveRangeNames = array_keys($liveRanges);
			$correlationRangeNames = array_keys($correlationOnlyRanges);
			[$updatedRanges, $promotedPredicates] = $this->joinPredicateProcessor->extractCorrelationPredicatesFromJoins(
				$ranges, $liveRanges, $liveRangeNames, $correlationRangeNames
			);
			
			// ── Step 6: Build final WHERE: original ANY WHERE AND all promoted correlation predicates.
			$finalWhere = $this->astUtilities->combinePredicatesWithAnd([
				$node->getConditions(),
				$this->astUtilities->combinePredicatesWithAnd($promotedPredicates)
			]);
			
			// ── Step 7: Drop non-live ranges (keeping order of the survivors).
			$keptRanges = $this->rangePartitioner->filterToLiveRangesOnly($updatedRanges, $liveRanges);
			
			// ── Step 8: Ensure exactly one anchor (joinProperty == null).
			//     We try to anchor a range used in expr, else any INNER, else collapse a safe LEFT.
			$keptRanges = $this->anchorManager->ensureSingleAnchorRange(
				$keptRanges,
				$node,
				$finalWhere,
				$usedInExpr,
				$usedInCond,
				$hasIsNullInCond,
				$nonNullableUse
			);
			
			// ── Step 9: Replace ANY(...) with the chosen subquery form.
			$this->replaceAnyNode($subQueryType, $keptRanges, $finalWhere, $ast, $node);
		}
		
		/**
		 * Collect all ANY nodes under the retrieve AST in one pass.
		 * @param AstRetrieve $ast Root query AST
		 * @return AstAny[] Array of ANY nodes found
		 */
		private function findAllAnyNodes(AstRetrieve $ast): array {
			$visitor = new CollectNodes([AstAny::class]);
			$ast->accept($visitor);
			return $visitor->getCollectedNodes();
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
		 * Replace an ANY node with either an inlined value or a subquery, depending on optimization conditions.
		 * @param string $subQueryType Type of subquery (EXISTS or CASE WHEN)
		 * @param AstRange[] $keptRanges Ranges to include in subquery
		 * @param AstInterface|null $finalWhere WHERE clause for subquery
		 * @param AstRetrieve $ast Root query AST
		 * @param AstAny $node ANY node to replace
		 * @return void
		 */
		private function replaceAnyNode(string $subQueryType, array $keptRanges, ?AstInterface $finalWhere, AstRetrieve $ast, AstAny $node): void {
			if ($this->canUseInlinedAnyOptimization($subQueryType, $finalWhere, $keptRanges, $ast, $node)) {
				$this->replaceAnyWithInlinedValue($ast, $node);
			} else {
				$this->replaceAnyWithSubquery($subQueryType, $keptRanges, $finalWhere, $node);
			}
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
			$this->nodeReplacer->replaceChild(
				$node->getParent(),
				$node,
				new AstNumber(1)
			);
			
			if ($ast->getWindow() === null) {
				$ast->setWindow(0);
				$ast->setWindowSize(1);
			}
		}
		
		/**
		 * Replace ANY node with a regular subquery form.
		 * @param string $subQueryType Type of subquery (EXISTS or CASE WHEN)
		 * @param AstRange[] $keptRanges Ranges to include in subquery
		 * @param AstInterface|null $finalWhere WHERE clause for subquery
		 * @param AstAny $node ANY node to replace
		 * @return void
		 */
		private function replaceAnyWithSubquery($subQueryType, array $keptRanges, $finalWhere, $node): void {
			$subQuery = new AstSubquery($subQueryType, null, $keptRanges, $finalWhere);
			$this->nodeReplacer->replaceChild($node->getParent(), $node, $subQuery);
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