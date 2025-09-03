<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectIdentifiers;
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
		
		/** @var AstNodeReplacer Utility to surgically replace nodes in the AST. */
		private AstNodeReplacer $nodeReplacer;
		
		/** @var RangeUsageAnalyzer Reused analyzer: liveness / nullability maps, etc. */
		private RangeUsageAnalyzer $analyzer;
		
		/**
		 * AnyOptimizer constructor
		 * @param EntityManager $entityManager Provides entity metadata access.
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityStore = $entityManager->getEntityStore();
			$this->nodeReplacer = new AstNodeReplacer();
			$this->analyzer = new RangeUsageAnalyzer($this->entityStore);
		}
		
		/**
		 * Entry point: find all ANY nodes in the retrieve AST and optimize them.
		 * @param AstRetrieve $ast Root query AST.
		 * @return void
		 */
		public function optimize(AstRetrieve $ast): void {
			foreach ($this->getAllAnyNodes($ast) as $node) {
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
			$ranges = $this->cloneRanges($ast);
			
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
			$usedInJoinOf = $this->computeJoinReferences($ranges);
			
			// ── Step 3: Partition the ranges into "live" and "correlation-only".
			//     Live ranges are those directly used in expr/cond; correlation-only appear only inside others' JOINs.
			[$liveMap, $corrOnlyMap] = $this->partitionRanges($ranges, $usedInExpr, $usedInCond, $usedInJoinOf);
			
			// ── Step 4: Ensure there's at least one live range.
			//     If analyzer yielded none, we fallback to expr ranges or the first range.
			if (empty($liveMap) && !empty($ranges)) {
				$liveMap = $this->fallbackLiveRanges($ranges, $node);
			}
			
			// ── Step 5: Promote correlation-only pieces of JOIN predicates into WHERE.
			//     Only process JOINs of "live" ranges; others will be dropped anyway.
			$innerNames = array_keys($liveMap);
			$corrNames = array_keys($corrOnlyMap);
			[$updatedRanges, $corrPredicates] = $this->promoteCorrelationPredicates($ranges, $liveMap, $innerNames, $corrNames);
			
			// ── Step 6: Build final WHERE: original ANY WHERE AND all promoted correlation predicates.
			$finalWhere = $this->andAll([$node->getConditions(), $this->andAll($corrPredicates)]);
			
			// ── Step 7: Drop non-live ranges (keeping order of the survivors).
			$kept = $this->keepLiveRanges($updatedRanges, $liveMap);
			
			// ── Step 8: Ensure exactly one anchor (joinProperty == null).
			//     We try to anchor a range used in expr, else any INNER, else collapse a safe LEFT.
			$kept = $this->anchorRanges(
				$kept,
				$node,
				$finalWhere,
				$usedInExpr,
				$usedInCond,
				$hasIsNullInCond,
				$nonNullableUse
			);
			
			// ── Step 9: Replace ANY(...) with the chosen subquery form.
			if ($this->shouldInlineAnyFastPath($subQueryType, $finalWhere, $kept, $ast, $node)) {
				$this->applyInlineAnyFastPath($ast, $node);
			} else {
				$this->applyRegularAnyPath($subQueryType, $kept, $finalWhere, $node);
			}
		}
		
		// ──────────────────────────────────────────────────────────────────────────
		// Stage helpers (each one is small and has a single, well-defined purpose)
		// ──────────────────────────────────────────────────────────────────────────
		
		/**
		 * Decide which ranges are "live" vs "correlation-only".
		 *
		 * Live:     directly referenced in ANY(expr) or ANY(WHERE ...).
		 * CorrOnly: not live, but referenced inside someone else's JOIN predicate.
		 *
		 * @param AstRange[] $ranges All cloned ranges (in original order).
		 * @param array<string,bool> $usedInExpr Analyzer map.
		 * @param array<string,bool> $usedInCond Analyzer map.
		 * @param array<string,array<string,bool>> $usedInJoinOf Map: [K][R] = true if JOIN(K) mentions R (K != R).
		 * @return array{0: array<string,AstRange>, 1: array<string,AstRange>} [liveMap, corrOnlyMap]
		 */
		private function partitionRanges(
			array $ranges,
			array $usedInExpr,
			array $usedInCond,
			array $usedInJoinOf
		): array {
			$liveRanges = [];
			$correlationOnlyRanges = [];
			
			foreach ($ranges as $range) {
				// Fetch range name
				$rangeName = $range->getName();
				
				// Criterion 1: Range is directly used in expressions or conditions
				$isDirectlyUsed = ($usedInExpr[$rangeName] ?? false) || ($usedInCond[$rangeName] ?? false);
				
				if ($isDirectlyUsed) {
					$liveRanges[$rangeName] = $range;
					continue;
				}
				
				// Criterion 2: Range is only referenced through other ranges' JOIN predicates
				$isReferencedInJoins = $this->isRangeReferencedInJoins($rangeName, $ranges, $usedInJoinOf);
				
				if ($isReferencedInJoins) {
					$correlationOnlyRanges[$rangeName] = $range;
				}
				
				// Note: Ranges that are neither live nor correlation-only are implicitly excluded
			}
			
			return [$liveRanges, $correlationOnlyRanges];
		}
		
		/**
		 * Check if a range is referenced in any other range's JOIN predicates.
		 * @param string $targetRangeName The range to check for references
		 * @param AstRange[] $allRanges All available ranges
		 * @param array<string,array<string,bool>> $usedInJoinOf JOIN reference map
		 * @return bool True if the target range is referenced in any JOIN predicate
		 */
		private function isRangeReferencedInJoins(
			string $targetRangeName,
			array  $allRanges,
			array  $usedInJoinOf
		): bool {
			foreach ($allRanges as $otherRange) {
				$otherRangeName = $otherRange->getName();
				
				// Skip self-references
				if ($otherRangeName === $targetRangeName) {
					continue;
				}
				
				// Check if this other range's JOIN mentions our target range
				if (!empty($usedInJoinOf[$otherRangeName][$targetRangeName])) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Provide a fallback set of live ranges when analyzer finds none.
		 * Preference order:
		 *   1) Ranges referenced by ANY(expr)
		 *   2) The first range in the list (stable fallback)
		 *
		 * @param AstRange[] $ranges All cloned ranges.
		 * @param AstAny $node ANY node whose expr we inspect.
		 * @return array<string,AstRange> Live map keyed by range name.
		 */
		private function fallbackLiveRanges(array $ranges, AstAny $node): array {
			if (empty($ranges)) {
				return [];
			}
			
			$rangesByName = $this->indexRangesByName($ranges);
			$liveRanges = $this->getLiveRangesFromExpression($node, $rangesByName);
			
			// If no ranges found in expression, use first range as stable fallback
			if (empty($liveRanges)) {
				$firstRange = $ranges[0];
				$liveRanges[$firstRange->getName()] = $firstRange;
			}
			
			return $liveRanges;
		}
		
		/**
		 * Create a lookup map of ranges indexed by their names.
		 * @param AstRange[] $ranges Array of range objects
		 * @return array<string,AstRange> Map from range name to range object
		 */
		private function indexRangesByName(array $ranges): array {
			$rangesByName = [];
			
			foreach ($ranges as $range) {
				$rangesByName[$range->getName()] = $range;
			}
			
			return $rangesByName;
		}
		
		/**
		 * Extract live ranges from identifiers in the ANY expression.
		 * @param AstAny $node ANY node to analyze
		 * @param array<string,AstRange> $rangesByName Available ranges indexed by name
		 * @return array<string,AstRange> Live ranges found in the expression
		 */
		private function getLiveRangesFromExpression(AstAny $node, array $rangesByName): array {
			$liveRanges = [];
			$identifiers = $this->getIdentifiers($node->getIdentifier());
			
			foreach ($identifiers as $identifier) {
				$rangeName = $identifier->getRange()->getName();
				
				if (isset($rangesByName[$rangeName])) {
					$liveRanges[$rangeName] = $rangesByName[$rangeName];
				}
			}
			
			return $liveRanges;
		}
		
		/**
		 * For each live range, split its JOIN predicate into INNER vs CORR parts and
		 * move the correlation-only parts into WHERE (returned as a list of predicates).
		 *
		 * Why: correlation terms referencing only "corrNames" do not belong in JOINs of
		 * live ranges. Promoting them reduces join complexity and can enable better
		 * anchor choices later.
		 *
		 * @param AstRange[] $allRanges All cloned ranges (we'll return an updated copy).
		 * @param array<string,AstRange> $liveMap Ranges considered live (by name => range).
		 * @param string[] $innerNames Names of live ranges.
		 * @param string[] $corrNames Names of correlation-only ranges.
		 * @return array{0: AstRange[], 1: AstInterface[]} [updatedRanges, promotedCorrelationPredicates]
		 */
		private function promoteCorrelationPredicates(
			array $allRanges,
			array $liveMap,
			array $innerNames,
			array $corrNames
		): array {
			$promotedCorrelationPredicates = [];
			$updatedRanges = $allRanges;
			
			foreach ($updatedRanges as $rangeIndex => $range) {
				if (!$this->isLiveRange($range, $liveMap)) {
					continue; // Only adjust JOINs of live ranges; others will be dropped anyway
				}
				
				$joinPredicate = $range->getJoinProperty();
				
				if ($joinPredicate === null) {
					continue; // No JOIN predicate to split/promote
				}
				
				$splitResult = $this->splitPredicateByRangeRefs($joinPredicate, $innerNames, $corrNames);
				
				// Update the JOIN to keep only the inner-related part
				$range->setJoinProperty($splitResult['innerPart']);
				
				// Collect correlation-only parts for promotion to WHERE clause
				if ($splitResult['corrPart'] !== null) {
					$promotedCorrelationPredicates[] = $splitResult['corrPart'];
				}
			}
			
			return [$updatedRanges, $promotedCorrelationPredicates];
		}
		
		/**
		 * Check if a range is considered live (actively used in the query).
		 *
		 * @param AstRange $range The range to check
		 * @param array<string,AstRange> $liveMap Map of live range names to ranges
		 * @return bool True if the range is live
		 */
		private function isLiveRange(AstRange $range, array $liveMap): bool {
			return isset($liveMap[$range->getName()]);
		}
		
		/**
		 * Keep only the ranges that are live; preserve their original order.
		 * @param AstRange[] $ranges All cloned ranges (post-promotion).
		 * @param array<string,AstRange> $liveMap Live ranges keyed by name.
		 * @return AstRange[] The subset of $ranges that are live.
		 */
		private function keepLiveRanges(array $ranges, array $liveMap): array {
			// Early exit if no live ranges
			if (empty($liveMap)) {
				return [];
			}
			
			// Use array_filter with isset for O(1) lookup per element
			return array_filter($ranges, fn($r) => isset($liveMap[$r->getName()]));
		}
		
		/**
		 * Ensures exactly one anchor exists (range with joinProperty == null) and places it first.
		 *
		 * Anchor selection priority:
		 *   1. Range referenced in ANY(expr) that is INNER or can safely collapse from LEFT
		 *   2. Any existing INNER range
		 *   3. LEFT range that can safely collapse to INNER (based on analyzer maps)
		 *
		 * Safety rule for LEFT → INNER collapse:
		 *   Range is safely collapsible if:
		 *   - It's actually used: (usedInExpr OR usedInCond OR nonNullableUse)
		 *   - WHERE logic doesn't depend on NULL values: NOT hasIsNullInCond
		 *
		 * If no safe anchor can be created, preserves original layout to maintain semantics.
		 */
		private function anchorRanges(
			array         $ranges,
			AstAny        $any,
			?AstInterface &$where,
			array         $usedInExpr,
			array         $usedInCond,
			array         $hasIsNullInCond,
			array         $nonNullableUse
		): array {
			if (empty($ranges)) {
				return $ranges;
			}
			
			// Fast path: anchor already exists
			if ($this->hasExistingAnchor($ranges)) {
				return $ranges;
			}
			
			// Priority 1: Range used in ANY(expr) that's INNER or safely collapsible
			$makeAnchor = $this->createAnchorMaker($ranges, $where);
			$canCollapse = $this->createCollapseTester($usedInExpr, $usedInCond, $hasIsNullInCond, $nonNullableUse);
			$anchorIndex = $this->findExpressionBasedAnchor($ranges, $any, $canCollapse);
			
			if ($anchorIndex !== null) {
				if (!$ranges[$anchorIndex]->isRequired()) {
					$ranges[$anchorIndex]->setRequired(true); // Safe LEFT → INNER
				}
				
				return $makeAnchor($anchorIndex);
			}
			
			// Priority 2: Any existing INNER range
			$anchorIndex = $this->findInnerRangeAnchor($ranges);
			
			if ($anchorIndex !== null) {
				return $makeAnchor($anchorIndex);
			}
			
			// Priority 3: LEFT range that can safely become INNER
			$anchorIndex = $this->findCollapsibleLeftAnchor($ranges, $canCollapse);
			
			if ($anchorIndex !== null) {
				$ranges[$anchorIndex]->setRequired(true);
				return $makeAnchor($anchorIndex);
			}
			
			// No safe transformation possible - preserve semantics
			return $ranges;
		}
		
		private function hasExistingAnchor(array $ranges): bool {
			foreach ($ranges as $range) {
				if ($range->getJoinProperty() === null) {
					return true;
				}
			}
			
			return false;
		}
		
		private function createAnchorMaker(array &$ranges, ?AstInterface &$where): callable {
			return function (int $index) use (&$ranges, &$where): array {
				$range = $ranges[$index];
				
				// Move JOIN predicate to WHERE clause
				if ($range->getJoinProperty() !== null) {
					$where = $this->andAll([$where, $range->getJoinProperty()]);
					$range->setJoinProperty(null);
				}
				
				// Move anchor to front for downstream code
				if ($index !== 0) {
					array_splice($ranges, $index, 1);
					array_unshift($ranges, $range);
				}
				
				return $ranges;
			};
		}
		
		private function createCollapseTester(
			array $usedInExpr,
			array $usedInCond,
			array $hasIsNullInCond,
			array $nonNullableUse
		): callable {
			return function (AstRange $range) use ($usedInExpr, $usedInCond, $hasIsNullInCond, $nonNullableUse): bool {
				$name = $range->getName();
				
				$isUsed =
					($usedInExpr[$name] ?? false) ||
					($usedInCond[$name] ?? false) ||
					($nonNullableUse[$name] ?? false);
				
				$dependsOnNull = ($hasIsNullInCond[$name] ?? false);
				
				return $isUsed && !$dependsOnNull;
			};
		}
		
		private function findExpressionBasedAnchor(array $ranges, AstAny $any, callable $canCollapse): ?int {
			$expressionRangeNames = $this->rangeNamesUsedInExpr($any);
			
			foreach ($ranges as $index => $range) {
				$isInExpression = in_array($range->getName(), $expressionRangeNames, true);
				$canBeAnchor = $range->isRequired() || $canCollapse($range);
				
				if ($isInExpression && $canBeAnchor) {
					return $index;
				}
			}
			
			return null;
		}
		
		private function findInnerRangeAnchor(array $ranges): ?int {
			foreach ($ranges as $index => $range) {
				if ($range->isRequired()) {
					return $index;
				}
			}
			
			return null;
		}
		
		private function findCollapsibleLeftAnchor(array $ranges, callable $canCollapse): ?int {
			foreach ($ranges as $index => $range) {
				if (!$range->isRequired() && $canCollapse($range)) {
					return $index;
				}
			}
			
			return null;
		}
		
		/**
		 * Convenience: list the names of ranges referenced by ANY(expr).
		 * @param AstAny $any The ANY(...) node.
		 * @return string[] Range names used in the ANY expression.
		 */
		private function rangeNamesUsedInExpr(AstAny $any): array {
			$exprIds = $this->getIdentifiers($any->getIdentifier());
			return array_map(static fn($id) => $id->getRange()->getName(), $exprIds);
		}
		
		// ──────────────────────────────────────────────────────────────────────────
		// Lower-level expression / AST utilities
		// ──────────────────────────────────────────────────────────────────────────
		
		/**
		 * Build a map of cross-join references:
		 *   usedInJoinOf[K][R] = true  ⇔  JOIN(K).ON mentions range R (and R != K).
		 *
		 * Why we need this: a range that only appears inside other JOINs is
		 * "correlation-only" and shouldn't stay as a joined input in the subquery.
		 *
		 * @param AstRange[] $ranges All cloned ranges.
		 * @return array<string,array<string,bool>> Map of JOIN cross-refs.
		 */
		private function computeJoinReferences(array $ranges): array {
			$usedInJoinOf = [];
			
			foreach ($ranges as $k) {
				$kName = $k->getName();
				$join = $k->getJoinProperty();
				
				if ($join === null) {
					continue;
				}
				
				// Identify which ranges are referenced by this join predicate.
				foreach ($this->getIdentifiers($join) as $id) {
					$rName = $id->getRange()->getName();
					
					if ($rName === $kName) {
						// Self-reference is not correlation; it stays "inner".
						continue;
					}
					
					$usedInJoinOf[$kName][$rName] = true;
				}
			}
			
			return $usedInJoinOf;
		}
		
		/**
		 * Split a predicate into:
		 *   - innerPart: references only $innerNames
		 *   - corrPart : references only $corrNames
		 *
		 * If a conjunct mixes inner & corr refs and contains OR, we treat it as
		 * "unsafe to split" and keep the whole predicate as innerPart.
		 *
		 * @param AstInterface|null $predicate Original JOIN predicate.
		 * @param string[] $innerNames Names considered "inner".
		 * @param string[] $corrNames Names considered "correlation".
		 * @return array
		 */
		private function splitPredicateByRangeRefs(
			?AstInterface $predicate,
			array         $innerNames,
			array         $corrNames
		): array {
			// When no predicate passed, return empty values
			if ($predicate === null) {
				return ['innerPart' => null, 'corrPart' => null];
			}
			
			// If it's an AND tree, we can classify each leaf conjunct independently.
			if ($this->isAndNode($predicate)) {
				$queue = [$predicate];
				$andLeaves = [];
				
				// Flatten the AND tree to a list of leaves.
				while ($queue) {
					$n = array_pop($queue);
					
					if ($this->isAndNode($n)) {
						$queue[] = $n->getLeft();
						$queue[] = $n->getRight();
					} else {
						$andLeaves[] = $n;
					}
				}
				
				$innerParts = [];
				$corrParts = [];
				
				foreach ($andLeaves as $leaf) {
					$bucket = $this->classifyByRefs($leaf, $innerNames, $corrNames);
					
					// Unsafe: leave the entire predicate as a single innerPart.
					if ($bucket === 'MIXED_OR_COMPLEX') {
						return ['innerPart' => $predicate, 'corrPart' => null];
					}
					
					if ($bucket === 'CORR') {
						$corrParts[] = $leaf;
					} else {
						$innerParts[] = $leaf; // INNER
					}
				}
				
				return [
					'innerPart' => $this->andAll($innerParts),
					'corrPart'  => $this->andAll($corrParts),
				];
			}
			
			// Non-AND predicates are classified as a whole.
			return match ($this->classifyByRefs($predicate, $innerNames, $corrNames)) {
				'MIXED_OR_COMPLEX' => ['innerPart' => $predicate, 'corrPart' => null],
				'CORR' => ['innerPart' => null, 'corrPart' => $predicate],
				default => ['innerPart' => $predicate, 'corrPart' => null],
			};
		}
		
		/**
		 * Classify an expression by the sets of ranges it references:
		 *   - 'INNER'            : only innerNames appear
		 *   - 'CORR'             : only corrNames appear
		 *   - 'MIXED_OR_COMPLEX' : both appear AND there's an OR somewhere (unsafe split)
		 *
		 * Rationale: a conjunct that mixes both sides but has no OR can be pushed
		 * into either bucket by normalization, but we keep it conservative: only
		 * split when it's clearly safe and clean.
		 *
		 * @param AstInterface $expr
		 * @param string[] $innerNames
		 * @param string[] $corrNames
		 * @return 'INNER'|'CORR'|'MIXED_OR_COMPLEX'
		 */
		private function classifyByRefs(AstInterface $expr, array $innerNames, array $corrNames): string {
			$ids = $this->getIdentifiers($expr);
			$hasInner = false;
			$hasCorr = false;
			
			foreach ($ids as $id) {
				$n = $id->getRange()->getName();
				
				if (in_array($n, $innerNames, true)) {
					$hasInner = true;
				}
				
				if (in_array($n, $corrNames, true)) {
					$hasCorr = true;
				}
			}
			
			// If both sides appear AND there is an OR in the subtree, splitting
			// risks changing semantics (e.g., distributing over OR). Avoid it.
			if ($this->containsOr($expr) && $hasInner && $hasCorr) {
				return 'MIXED_OR_COMPLEX';
			}
			
			if ($hasCorr && !$hasInner) {
				return 'CORR';
			}
			
			return 'INNER';
		}
		
		/**
		 * True if the subtree contains an OR node anywhere.
		 * @param AstInterface $node
		 * @return bool
		 */
		private function containsOr(AstInterface $node): bool {
			if ($this->isOrNode($node)) {
				return true;
			}
			
			foreach ($this->childrenOf($node) as $child) {
				if ($this->containsOr($child)) {
					return true;
				}
			}
			
			return false;
		}
		
		// ──────────────────────────────────────────────────────────────────────────
		// Small AST helpers (simple, boring, and well-commented)
		// ──────────────────────────────────────────────────────────────────────────
		
		/**
		 * Build a left-associative AND chain from a list of predicates.
		 * Examples:
		 *   []        → null
		 *   [a]       → a
		 *   [a,b,c]   → ((a AND b) AND c)
		 *
		 * @param AstInterface[] $parts Predicates to AND together (nulls are ignored).
		 * @return AstInterface|null
		 */
		private function andAll(array $parts): ?AstInterface {
			// Drop nulls/empties early to keep the tree lean.
			$parts = array_values(array_filter($parts));
			$n = count($parts);
			
			if ($n === 0) {
				return null;
			}
			
			if ($n === 1) {
				return $parts[0];
			}
			
			// Build a simple left-deep AND tree; balancing offers no real advantage here.
			$acc = new AstBinaryOperator($parts[0], $parts[1], 'AND');
			
			for ($i = 2; $i < $n; $i++) {
				$acc = new AstBinaryOperator($acc, $parts[$i], 'AND');
			}
			
			return $acc;
		}
		
		/**
		 * Lightweight operator test for AND.
		 * @param AstInterface $node
		 * @return bool
		 */
		private function isAndNode(AstInterface $node): bool {
			return $node instanceof AstBinaryOperator && strtoupper($node->getOperator()) === 'AND';
		}
		
		/**
		 * Lightweight operator test for OR.
		 * @param AstInterface $node
		 * @return bool
		 */
		private function isOrNode(AstInterface $node): bool {
			return $node instanceof AstBinaryOperator && strtoupper($node->getOperator()) === 'OR';
		}
		
		/**
		 * Return binary children when present; otherwise empty list.
		 * @param AstInterface $node
		 * @return AstInterface[]
		 */
		private function childrenOf(AstInterface $node): array {
			return $node instanceof AstBinaryOperator ? [$node->getLeft(), $node->getRight()] : [];
		}
		
		/**
		 * Collect identifiers in an AST subtree.
		 * We use this to find which ranges are referenced by expressions.
		 * @param AstInterface|null $ast
		 * @return array<int,AstIdentifier>
		 */
		private function getIdentifiers(?AstInterface $ast): array {
			if ($ast === null) {
				return [];
			}
			
			$visitor = new CollectIdentifiers();
			$ast->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Deep clone of the query ranges for safe, local mutations.
		 * @param AstRetrieve $ast
		 * @return AstRange[]
		 */
		private function cloneRanges(AstRetrieve $ast): array {
			$result = [];
			
			foreach ($ast->getRanges() as $range) {
				$result[] = $range->deepClone();
			}
			
			return $result;
		}
		
		/**
		 * Collect all ANY nodes under the retrieve AST in one pass.
		 * @param AstRetrieve $ast
		 * @return AstAny[]
		 */
		private function getAllAnyNodes(AstRetrieve $ast): array {
			$visitor = new CollectNodes([AstAny::class]);
			$ast->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Check whether the given ANY(...) node is a simple top-level projection.
		 * @param AstRetrieve $ast
		 * @param AstAny $node
		 * @return bool
		 */
		private function isTopLevelAny(AstRetrieve $ast, AstAny $node): bool {
			return $ast->getLocationOfChild($node) === 'select';
		}
		
		/**
		 * Decide if we can inline ANY(...) as a literal without a subselect.
		 * @param $subQueryType
		 * @param $finalWhere
		 * @param array $kept
		 * @param AstRetrieve $ast
		 * @param AstAny $node
		 * @return bool
		 */
		private function shouldInlineAnyFastPath($subQueryType, $finalWhere, array $kept, AstRetrieve $ast, AstAny $node): bool {
			return
				$subQueryType === AstSubquery::TYPE_CASE_WHEN
				&& $finalWhere === null
				&& count($kept) === 1
				&& $this->isTopLevelAny($ast, $node);
		}
		
		/**
		 * Apply the optimized inlining: ANY(...) → 1, and cap to a single row.
		 * @param AstRetrieve $ast
		 * @param AstAny $node
		 * @return void
		 */
		private function applyInlineAnyFastPath(AstRetrieve $ast, AstAny $node): void {
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
		 * Fallback to the regular subquery form.
		 * @param $subQueryType
		 * @param array $kept
		 * @param $finalWhere
		 * @param $node
		 * @return void
		 */
		private function applyRegularAnyPath($subQueryType, array $kept, $finalWhere, $node): void {
			$subQuery = new AstSubquery($subQueryType, null, $kept, $finalWhere);
			$this->nodeReplacer->replaceChild($node->getParent(), $node, $subQuery);
		}
	}