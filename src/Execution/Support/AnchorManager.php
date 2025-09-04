<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
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
		public static function ensureSingleAnchorRange(
			array         $ranges,
			?AstInterface &$whereClause,
			array         $usedInExpr,
			array         $usedInCond,
			array         $hasIsNullInCond,
			array         $nonNullableUse
		): array {
			$exprRangeNames = array_keys(array_filter($usedInExpr, static fn($v) => (bool)$v));
			
			return self::ensureSingleAnchorCore(
				$ranges,
				$whereClause,
				$exprRangeNames,
				$usedInCond,
				$hasIsNullInCond,
				$nonNullableUse
			);
		}
		
		/**
		 * Core logic for selecting a single anchor range.
		 * @param AstRange[] $ranges
		 * @param AstInterface|null $whereClause (by-ref) — we may move JOIN → WHERE here
		 * @param string[] $exprRangeNames — ranges referenced by the owner's expression
		 * @param array<string,bool> $usedInCond
		 * @param array<string,bool> $hasIsNullInCond
		 * @param array<string,bool> $nonNullableUse
		 * @return AstRange[]
		 */
		private static function ensureSingleAnchorCore(
			array         $ranges,
			?AstInterface &$whereClause,
			array         $exprRangeNames,
			array         $usedInCond,
			array         $hasIsNullInCond,
			array         $nonNullableUse
		): array {
			// Early exit for valid states
			if (empty($ranges) || self::hasValidAnchorRange($ranges)) {
				return $ranges;
			}
			
			// Check if we can collapse
			$canCollapse = self::buildCollapsePredicate($usedInCond, $hasIsNullInCond, $nonNullableUse);
			
			// Find anchor using priority order
			$anchorIndex = self::findAnchorByPriority($ranges, $exprRangeNames, $canCollapse);
			
			if ($anchorIndex === null) {
				return $ranges;
			}
			
			return self::applyAnchorSelection($ranges, $anchorIndex, $whereClause);
		}
		
		/**
		 * Find the best anchor range using priority-based selection.
		 * @param array $ranges
		 * @param array $exprRangeNames
		 * @param callable $canCollapse
		 * @return int|null
		 */
		private static function findAnchorByPriority(array $ranges, array $exprRangeNames, callable $canCollapse): ?int {
			// Priority 1: Expression range that can be anchored
			$exprAnchor = self::findExpressionRangeAnchor($ranges, $exprRangeNames, $canCollapse);
			if ($exprAnchor !== null) {
				return $exprAnchor;
			}
			
			// Priority 2: Any INNER range
			$innerAnchor = self::findInnerRangeAnchor($ranges);
			
			if ($innerAnchor !== null) {
				return $innerAnchor;
			}
			
			// Priority 3: Collapsible LEFT range
			return self::findCollapsibleLeftAnchor($ranges, $canCollapse);
		}
		
		/**
		 * Find a range used in owner's expression that can be anchored.
		 * @param array $ranges
		 * @param array $exprRangeNames
		 * @param callable $canCollapse
		 * @return int|null
		 */
		private static function findExpressionRangeAnchor(array $ranges, array $exprRangeNames, callable $canCollapse): ?int {
			foreach ($ranges as $index => $range) {
				if (!in_array($range->getName(), $exprRangeNames, true)) {
					continue;
				}
				
				if ($range->isRequired() || $canCollapse($range)) {
					return $index;
				}
			}
			
			return null;
		}
		
		/**
		 * Apply the selected anchor range and normalize position.
		 * @param array $ranges
		 * @param int $anchorIndex
		 * @param AstInterface|null $whereClause
		 * @return array
		 */
		private static function applyAnchorSelection(
			array         $ranges,
			int           $anchorIndex,
			?AstInterface &$whereClause
		): array {
			$selectedRange = $ranges[$anchorIndex];
			
			// Move JOIN condition to WHERE clause if present
			self::moveJoinToWhere($selectedRange, $whereClause);
			
			// Move anchor to front for stable downstream expectations
			if ($anchorIndex !== 0) {
				array_splice($ranges, $anchorIndex, 1);
				array_unshift($ranges, $selectedRange);
			}
			
			return $ranges;
		}
		
		/**
		 * Move JOIN condition to WHERE clause and mark range as required.
		 * @param AstRange $range
		 * @param AstInterface|null $whereClause
		 * @return void
		 */
		private static function moveJoinToWhere(AstRange $range, ?AstInterface &$whereClause): void {
			$joinCondition = $range->getJoinProperty();
			
			if ($joinCondition === null) {
				return;
			}
			
			$whereClause = AstUtilities::combinePredicatesWithAnd([$whereClause, $joinCondition]);
			$range->setJoinProperty(null);
			$range->setRequired(true);
		}
		
		/**
		 * Check if any range already serves as an anchor (has null joinProperty).
		 * @param AstRange[] $ranges Ranges to check
		 * @return bool True if an anchor exists
		 */
		private static function hasValidAnchorRange(array $ranges): bool {
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
		private static function findInnerRangeAnchor(array $ranges): ?int {
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
		private static function findCollapsibleLeftAnchor(array $ranges, callable $canCollapse): ?int {
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
		private static function buildCollapsePredicate(array $usedInCond, array $hasIsNullInCond, array $nonNullableUse): callable {
			return function ($range) use ($usedInCond, $hasIsNullInCond, $nonNullableUse): bool {
				$name = $range->getName();
				
				// Already INNER — nothing to collapse. Treat as OK.
				if ($range->isRequired()) {
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