<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Execution\Support\AstExpressionFactory;
	use Quellabs\ObjectQuel\Execution\Support\AstFactory;
	use Quellabs\ObjectQuel\Execution\Support\AstNodeReplacer;
	use Quellabs\ObjectQuel\Execution\Support\AstUtilities;
	use Quellabs\ObjectQuel\Execution\Support\JoinPredicateProcessor;
	use Quellabs\ObjectQuel\Execution\Support\QueryAnalysisResult;
	use Quellabs\ObjectQuel\Execution\Support\RangePartitioner;
	use Quellabs\ObjectQuel\Execution\Support\RangeUsageAnalyzer;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
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
		 *
		 * This method walks the entire AST tree to locate ANY(...) nodes and applies
		 * context-specific optimizations based on where the ANY appears:
		 * - SELECT clause: generates CASE WHEN subquery for value context
		 * - WHERE clause: generates EXISTS subquery for boolean context
		 * - ORDER BY clause: currently no optimization (could be future enhancement)
		 *
		 * @param AstRetrieve $ast Root query AST.
		 * @return void
		 */
		public function optimize(AstRetrieve $ast): void {
			// Find all ANY nodes throughout the entire query tree
			foreach (AstUtilities::findAllAnyNodes($ast) as $node) {
				// The context of the ANY node decides the subquery shape we emit.
				// This is critical because SQL semantics differ between value and boolean contexts.
				switch ($ast->getLocationOfChild($node)) {
					case 'select':
						// ANY(...) in SELECT becomes a CASE WHEN subquery (value context).
						// Example: SELECT ANY(u.id WHERE u.active) FROM users u
						// Becomes: SELECT (CASE WHEN EXISTS(...) THEN 1 ELSE 0 END)
						$this->optimizeAnyNode($ast, $node, AstSubquery::TYPE_CASE_WHEN);
						break;
					
					case 'conditions':
						// ANY(...) in WHERE becomes an EXISTS subquery (boolean context).
						// Example: WHERE ANY(p.id WHERE p.status = 'active')
						// Becomes: WHERE EXISTS(SELECT 1 FROM products p WHERE p.status = 'active')
						$this->optimizeAnyNode($ast, $node, AstSubquery::TYPE_EXISTS);
						break;
					
					case 'order_by':
						// We currently do not perform ANY-specific rewrites in ORDER BY.
						// This could be enhanced in the future to support ordering by ANY expressions.
						break;
				}
			}
		}
		
		/**
		 * Perform the full ANY(...) rewrite for a single node.
		 * @param AstRetrieve $ast Root AST.
		 * @param AstAny $node ANY node to rewrite.
		 * @param string $subQueryType Subquery type (EXISTS | CASE WHEN).
		 * @return void
		 * @throws QuelException
		 */
		private function optimizeAnyNode(AstRetrieve $ast, AstAny $node, string $subQueryType): void {
			// Step 0: Work on cloned ranges to avoid mutating the original tree
			// This ensures we can safely experiment with optimizations without side effects
			$ranges = $this->cloneQueryRanges($ast);
			
			// Step 1: Usage analysis
			// The analyzer determines which tables are actually used and how they're used,
			// which is critical for deciding what can be safely optimized
			$analysis = $this->analyzer->analyze($node, $ranges);
			
			// Steps 2: Build optimized subquery using clean pipeline
			// This encapsulates all the complex logic for range partitioning,
			// JOIN predicate processing, and anchor configuration
			$optimizedSubquery = $this->buildOptimizedSubquery($ranges, $node, $analysis);
			
			// Step 3: Apply the optimization to the original AST
			$this->applyOptimization($ast, $node, $subQueryType, $optimizedSubquery);
		}
		
		/**
		 * Build the optimized subquery structure through a series of transformation steps.
		 *
		 * This method orchestrates the complex process of:
		 * 1. Understanding which ranges are actually needed (live vs correlation-only)
		 * 2. Processing JOIN predicates to move correlation conditions to WHERE
		 * 3. Ensuring proper anchor configuration for subquery validity
		 *
		 * Each step is delegated to specialized classes to maintain separation of concerns.
		 *
		 * @param array $ranges Cloned query ranges to work with
		 * @param AstAny $node The ANY node being optimized
		 * @param QueryAnalysisResult $analysis Structured usage analysis
		 * @return array [optimized_ranges, final_where_clause]
		 * @throws QuelException
		 */
		private function buildOptimizedSubquery(array $ranges, AstAny $node, QueryAnalysisResult $analysis): array {
			// Step 2: Compute JOIN cross-references
			// This creates a map of which JOIN conditions reference which ranges,
			// which is essential for understanding correlation dependencies
			$joinReferences = RangePartitioner::buildJoinReferenceMap($ranges);
			
			// Step 3: Partition ranges into live and correlation-only
			// Live ranges: directly referenced by the ANY expression or conditions
			// Correlation-only: only referenced indirectly through JOIN predicates
			$liveRanges = RangePartitioner::computeLiveRanges($ranges, $analysis);
			$correlationOnlyRanges = RangePartitioner::computeCorrelationOnlyRanges($ranges, $joinReferences, $analysis);
			
			// Step 4: Ensure at least one live range
			// If no ranges are determined to be live (edge case), we need a fallback
			// to ensure the subquery has at least one table to query from
			if (empty($liveRanges) && !empty($ranges)) {
				$liveRanges = RangePartitioner::selectFallbackLiveRanges($ranges, $node);
			}
			
			// Step 5: Process JOIN predicates - now using object-oriented approach
			// This is where we split JOIN conditions: parts referencing only live ranges
			// stay in JOINs, parts referencing correlation-only ranges move to WHERE
			$joinProcessor = new JoinPredicateProcessor($liveRanges, $correlationOnlyRanges);
			$updatedRanges = $joinProcessor->buildUpdatedRanges($ranges);
			$promotedPredicates = $joinProcessor->gatherPromotedPredicates($ranges);
			
			// Step 6: Build final WHERE clause
			// Combine the original ANY conditions with any predicates promoted from JOINs
			$finalWhere = AstUtilities::combinePredicatesWithAnd([
				$node->getConditions(),
				AstUtilities::combinePredicatesWithAnd($promotedPredicates)
			]);
			
			// Step 7: Keep only live ranges
			// Remove correlation-only ranges since their conditions are now in WHERE
			$keptRanges = RangePartitioner::filterToLiveRangesOnly($updatedRanges, $liveRanges);
			
			// Step 8: Configure anchor
			// Ensure exactly one range has joinProperty == null (the subquery root)
			// This is required for valid SQL subquery structure
			$keptRanges = AnchorOptimizer::configureRangeAnchors($keptRanges, $finalWhere, $analysis);
			
			// Return the optimized subquery components
			return [$keptRanges, $finalWhere];
		}
		
		/**
		 * Apply the optimization by replacing the ANY node with the optimized form.
		 *
		 * This method determines whether to use a simple inlined optimization
		 * (for trivial cases) or a full subquery transformation. The decision
		 * is based on the complexity of the resulting query structure.
		 *
		 * @param AstRetrieve $ast Root query AST
		 * @param AstAny $node ANY node to replace
		 * @param string $subQueryType Type of subquery to generate
		 * @param array $optimized [ranges, where_clause] from buildOptimizedSubquery
		 * @return void
		 */
		private function applyOptimization(AstRetrieve $ast, AstAny $node, string $subQueryType, array $optimized): void {
			[$keptRanges, $finalWhere] = $optimized;
			
			// Check if we can use the simple inlined optimization
			// This is a special case where ANY(...) can be replaced with a literal value
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
		 *
		 * The inlined optimization is a special case where ANY(...) can be replaced
		 * with the literal value 1, provided several conditions are met:
		 *
		 * 1. We're in a CASE WHEN context (SELECT clause)
		 * 2. There are no WHERE conditions in the ANY
		 * 3. There's exactly one range (table) involved
		 * 4. The ANY is a direct SELECT clause projection
		 *
		 * This optimization turns "SELECT ANY(u.id) FROM users u" into "SELECT 1 FROM users u LIMIT 1"
		 *
		 * @param string $subQueryType Type of subquery (EXISTS or CASE WHEN)
		 * @param AstInterface|null $finalWhere Final WHERE clause
		 * @param AstRange[] $keptRanges Ranges that will be kept
		 * @param AstRetrieve $ast Root query AST
		 * @param AstAny $node ANY node being optimized
		 * @return bool True if inlining optimization can be applied
		 */
		private function canUseInlinedAnyOptimization(string $subQueryType, ?AstInterface $finalWhere, array $keptRanges, AstRetrieve $ast, AstAny $node): bool {
			return
				$subQueryType === AstSubquery::TYPE_CASE_WHEN &&   // Must be in SELECT context
				$finalWhere === null &&                            // No WHERE conditions
				count($keptRanges) === 1 &&                        // Single table only
				$this->isAnyNodeInSelectClause($ast, $node);       // Direct SELECT projection
		}
		
		/**
		 * Apply the optimized inlining: ANY(...) → 1, and cap to a single row.
		 *
		 * This is the simplest possible optimization: replace ANY(...) with the literal 1
		 * and add a LIMIT 1 to ensure we only return one row (since ANY means "at least one").
		 *
		 * This optimization is semantically equivalent but much more efficient than
		 * generating a subquery for trivial cases.
		 *
		 * @param AstRetrieve $ast Root query AST
		 * @param AstAny $node ANY node to replace
		 * @return void
		 */
		private function replaceAnyWithInlinedValue(AstRetrieve $ast, AstAny $node): void {
			// Replace ANY(...) with literal 1
			AstNodeReplacer::replaceChild(
				$node->getParent(),
				$node,
				AstFactory::createNumber(1)
			);
			
			// Add LIMIT 1 if no window is already set
			// This ensures we only return one row, which is correct for ANY semantics
			if ($ast->getWindow() === null) {
				$ast->setWindow(0);         // Start at window 0
				$ast->setWindowSize(1);  // Return only 1 row
			}
		}
		
		/**
		 * Replace ANY node with a regular subquery form.
		 *
		 * This generates the full subquery structure based on the subquery type:
		 *
		 * For CASE WHEN (SELECT context):
		 *   CASE WHEN EXISTS(SELECT 1 FROM ... WHERE ...) THEN 1 ELSE 0 END
		 *
		 * For EXISTS (WHERE context):
		 *   EXISTS(SELECT 1 FROM ... WHERE ...)
		 *
		 * The subquery uses the optimized ranges and WHERE clause from the optimization pipeline.
		 *
		 * @param string $subQueryType Type of subquery (EXISTS or CASE WHEN)
		 * @param AstRange[] $correlatedRanges Ranges to include in subquery
		 * @param AstInterface|null $conditions WHERE clause for subquery
		 * @param AstAny $node ANY node to replace
		 * @return void
		 */
		private function replaceAnyWithSubquery(string $subQueryType, array $correlatedRanges, ?AstInterface $conditions, AstAny $node): void {
			// Replace with literal true/1 since anchor existence is guaranteed
			if ($this->isAnyOnAnchorWithNoConditions($correlatedRanges, $conditions, $node)) {
				$subQuery = AstFactory::createNumber(1);
				AstNodeReplacer::replaceChild($node->getParent(), $node, $subQuery);
				return;
			}
			
			// For SELECT context: CASE WHEN EXISTS(...) THEN 1 ELSE 0 END
			if ($subQueryType === AstSubquery::TYPE_CASE_WHEN) {
				$subQuery = AstExpressionFactory::createCaseWhen($correlatedRanges, $conditions, "ANY");
				AstNodeReplacer::replaceChild($node->getParent(), $node, $subQuery);
				return;
			}
			
			// For WHERE context: EXISTS(SELECT 1 FROM ... WHERE ...)
			$subQuery = AstExpressionFactory::createExists(AstFactory::createNumber(1), $correlatedRanges, $conditions, "ANY");
			AstNodeReplacer::replaceChild($node->getParent(), $node, $subQuery);
		}
		
		/**
		 * Detects if an ANY() subquery references only the anchor table with no filtering conditions.
		 *
		 * This optimization check identifies cases where ANY(subquery) can be simplified because:
		 * - The subquery only references the main (anchor) table
		 * - No WHERE conditions filter the results
		 * - No JOINs introduce additional tables
		 *
		 * In such cases, ANY() becomes redundant since it's just checking if the anchor table
		 * has any rows, which is already guaranteed by the outer query context.
		 *
		 * @param array $correlatedRanges Available table ranges in the subquery scope
		 * @param ?AstInterface $conditions WHERE clause conditions (null if none)
		 * @param AstAny $node The ANY() AST node being analyzed
		 * @return bool True if this is a simplifiable ANY() on anchor with no conditions
		 */
		private function isAnyOnAnchorWithNoConditions(array $correlatedRanges, ?AstInterface $conditions, AstAny $node): bool {
			// Must have no WHERE conditions
			if ($conditions !== null) {
				return false;
			}
			
			// Must have exactly one range (the anchor)
			if (count($correlatedRanges) !== 1) {
				return false;
			}
			
			// Anchor must not have JOIN conditions
			$anchorRange = $correlatedRanges[0];
			
			if ($anchorRange->getJoinProperty() !== null) {
				return false;
			}
			
			// If we got here: single anchor range, no conditions, no JOINs
			// This means ANY() must be referencing the anchor table
			// (because that's the only table available in the subquery)
			return true;
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