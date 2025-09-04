<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\Execution\Optimizers\Support\AstUtilities;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * AnchorManager handles anchor range selection and ensures exactly one anchor exists.
	 *
	 * This class is responsible for:
	 * - Ensuring exactly one anchor range (with joinProperty == null) exists
	 * - Selecting the optimal anchor based on usage patterns and safety rules
	 * - Safely collapsing LEFT JOINs to INNER when possible
	 * - Moving JOIN predicates to WHERE clause when converting to anchor
	 */
	class AnchorManager {
		
		/**
		 * Core logic for selecting a single anchor range.
		 *
		 * @param AstRange[] $ranges
		 * @param AstInterface|null $whereClause (by-ref) — we may move JOIN → WHERE here
		 * @param string[] $exprRangeNames — ranges referenced by the owner's expression
		 * @param array<string,bool> $usedInCond
		 * @param array<string,bool> $hasIsNullInCond
		 * @param array<string,bool> $nonNullableUse
		 * @return AstRange[]
		 */
		private function ensureSingleAnchorCore(
			array         $ranges,
			?AstInterface &$whereClause,
			array         $exprRangeNames,
			array         $usedInCond,
			array         $hasIsNullInCond,
			array         $nonNullableUse
		): array {
			if (empty($ranges) || $this->hasValidAnchorRange($ranges)) {
				return $ranges;
			}
			
			$canCollapse = $this->buildCollapsePredicate($usedInCond, $hasIsNullInCond, $nonNullableUse);
			
			// Helper to apply the chosen anchor and normalize position
			$anchorize = function (int $index) use (&$ranges, &$whereClause) {
				$selected = $ranges[$index];
				$join = $selected->getJoinProperty();
				
				if ($join !== null) {
					$whereClause = AstUtilities::combinePredicatesWithAnd([$whereClause, $join]);
					$selected->setJoinProperty(null);
					$selected->setRequired(true);
				}
				
				// Move anchor to front (stable downstream expectations)
				if ($index !== 0) {
					array_splice($ranges, $index, 1);
					array_unshift($ranges, $selected);
				}
				
				return $ranges;
			};
			
			// Priority 1: a range used in the owner's expression that we can anchor on
			foreach ($ranges as $i => $range) {
				$inExpr = in_array($range->getName(), $exprRangeNames, true);
				$canBeAnchor = $range->isRequired() || $canCollapse($range);
				if ($inExpr && $canBeAnchor) {
					return $anchorize($i);
				}
			}
			
			// Priority 2: any INNER range
			if (method_exists($this, 'findInnerRangeAnchor')) {
				$idx = $this->findInnerRangeAnchor($ranges);
				if ($idx !== null) {
					return $anchorize($idx);
				}
			}
			
			// Priority 3: a LEFT range that can safely collapse to INNER
			if (method_exists($this, 'findCollapsibleLeftAnchor')) {
				$idx = $this->findCollapsibleLeftAnchor($ranges, $canCollapse);
				if ($idx !== null) {
					$ranges[$idx]->setRequired(true);
					return $anchorize($idx);
				}
			}
			
			return $ranges;
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
		 *
		 * @param AstRange[] $ranges Ranges to process
		 * @param AstInterface|null &$whereClause WHERE clause (modified by reference)
		 * @param array<string,bool> $usedInExpr Usage analyzer map
		 * @param array<string,bool> $usedInCond Usage analyzer map
		 * @param array<string,bool> $hasIsNullInCond Usage analyzer map
		 * @param array<string,bool> $nonNullableUse Usage analyzer map
		 * @return AstRange[] Updated ranges with anchor guaranteed
		 */
		public function ensureSingleAnchorRange(
			array         $ranges,
			?AstInterface &$whereClause,
			array         $usedInExpr,
			array         $usedInCond,
			array         $hasIsNullInCond,
			array         $nonNullableUse
		): array {
			$exprRangeNames = array_keys(array_filter($usedInExpr, static fn($v) => (bool)$v));
			
			return $this->ensureSingleAnchorCore(
				$ranges,
				$whereClause,
				$exprRangeNames,
				$usedInCond,
				$hasIsNullInCond,
				$nonNullableUse
			);
		}
		
		/**
		 * Check if any range already serves as an anchor (has null joinProperty).
		 * @param AstRange[] $ranges Ranges to check
		 * @return bool True if an anchor exists
		 */
		private function hasValidAnchorRange(array $ranges): bool {
			foreach ($ranges as $range) {
				if ($range->getJoinProperty() === null) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Find any INNER range that can serve as anchor.
		 * @param AstRange[] $ranges Available ranges
		 * @return int|null Index of INNER range, null if none found
		 */
		private function findInnerRangeAnchor(array $ranges): ?int {
			foreach ($ranges as $index => $range) {
				if ($range->isRequired()) {
					return $index;
				}
			}
			
			return null;
		}
		
		/**
		 * Find a LEFT range that can be safely collapsed to INNER.
		 * @param AstRange[] $ranges Available ranges
		 * @param callable $canCollapse Function to check collapse safety
		 * @return int|null Index of collapsible range, null if none found
		 */
		private function findCollapsibleLeftAnchor(array $ranges, callable $canCollapse): ?int {
			foreach ($ranges as $index => $range) {
				if (!$range->isRequired() && $canCollapse($range)) {
					return $index;
				}
			}
			
			return null;
		}
		
		/**
		 * Build a predicate used to check whether a LEFT-joined range can be safely
		 * collapsed to an INNER join for an aggregate subquery.
		 *
		 * Conservative rules:
		 *  - If the range participates in an explicit IS NULL check -> do NOT collapse.
		 *  - If the range is used in the condition only via non-nullable fields -> OK to collapse.
		 *  - If the range is not used in the aggregate condition at all -> OK to collapse.
		 * Otherwise: do not collapse.
		 *
		 * @param array<string,bool> $usedInCond
		 * @param array<string,bool> $hasIsNullInCond
		 * @param array<string,bool> $nonNullableUse
		 * @return callable($range):bool
		 */
		private function buildCollapsePredicate(array $usedInCond, array $hasIsNullInCond, array $nonNullableUse): callable {
			return function ($range) use ($usedInCond, $hasIsNullInCond, $nonNullableUse): bool {
				$name = $range->getName();
				
				// Already INNER — nothing to collapse. Treat as OK.
				if (method_exists($range, 'isRequired') && $range->isRequired()) {
					return true;
				}
				
				// If the aggregate condition checks this range for IS NULL, keep it LEFT.
				if (!empty($hasIsNullInCond[$name])) {
					return false;
				}
				
				// If conditions reference non-nullable fields on this range, collapsing is safe.
				if (!empty($nonNullableUse[$name])) {
					return true;
				}
				
				// If the range is not used in the aggregate's condition at all, collapsing is safe.
				if (empty($usedInCond[$name])) {
					return true;
				}
				
				// Otherwise, be conservative.
				return false;
			};
		}
	}