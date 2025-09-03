<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
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
		
		/** @var AstUtilities Utility methods for AST operations. */
		private AstUtilities $astUtilities;
		
		/**
		 * AnchorManager constructor
		 * @param AstUtilities $astUtilities AST utility methods
		 */
		public function __construct(AstUtilities $astUtilities) {
			$this->astUtilities = $astUtilities;
		}
		
		/**
		 * Core logic for selecting a single anchor range.
		 *
		 * @param AstRange[]         $ranges
		 * @param AstInterface|null  $whereClause (by-ref) — we may move JOIN → WHERE here
		 * @param string[]           $exprRangeNames       — ranges referenced by the owner's expression
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
				$join     = $selected->getJoinProperty();
				
				if ($join !== null) {
					$whereClause = $this->astUtilities->combinePredicatesWithAnd([$whereClause, $join]);
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
				$inExpr     = in_array($range->getName(), $exprRangeNames, true);
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
		 * @param AstAny $anyNode The ANY node being optimized
		 * @param AstInterface|null &$whereClause WHERE clause (modified by reference)
		 * @param array<string,bool> $usedInExpr Usage analyzer map
		 * @param array<string,bool> $usedInCond Usage analyzer map
		 * @param array<string,bool> $hasIsNullInCond Usage analyzer map
		 * @param array<string,bool> $nonNullableUse Usage analyzer map
		 * @return AstRange[] Updated ranges with anchor guaranteed
		 */
		/**
		 * Kept for backward-compatibility (used by AnyOptimizer).
		 * Now generic: derives expression range candidates from $usedInExpr.
		 *
		 * @param AstRange[]         $ranges
		 * @param AstInterface|null  $whereClause
		 * @param array<string,bool> $usedInExpr
		 * @param array<string,bool> $usedInCond
		 * @param array<string,bool> $hasIsNullInCond
		 * @param array<string,bool> $nonNullableUse
		 * @return AstRange[]
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
		 * Generic version for nodes like SUM/COUNT/AVG/MIN/MAX that expose getIdentifier().
		 *
		 * @param AstRange[]         $ranges
		 * @param AstInterface       $owner
		 * @param AstInterface|null  $whereClause
		 * @param array<string,bool> $usedInExpr
		 * @param array<string,bool> $usedInCond
		 * @param array<string,bool> $hasIsNullInCond
		 * @param array<string,bool> $nonNullableUse
		 * @return AstRange[]
		 */
		public function ensureSingleAnchorRangeForOwner(
			array         $ranges,
			AstInterface  $owner,
			?AstInterface &$whereClause,
			array         $usedInExpr,
			array         $usedInCond,
			array         $hasIsNullInCond,
			array         $nonNullableUse
		): array {
			// Collect candidate ranges from the owner's identifier
			$exprIds = $this->astUtilities->collectIdentifiersFromAst(
				method_exists($owner, 'getIdentifier') ? $owner->getIdentifier() : null
			);
			
			$exprRangeNames = array_map(static fn($id) => $id->getRange()->getName(), $exprIds);
			
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
		 * Create a function that converts a range at given index to an anchor.
		 * @param AstRange[] &$ranges Ranges array (modified by reference)
		 * @param AstInterface|null &$whereClause WHERE clause (modified by reference)
		 * @return callable Function that takes an index and returns updated ranges
		 */
		private function createAnchorMaker(array &$ranges, ?AstInterface &$whereClause): callable {
			return function (int $index) use (&$ranges, &$whereClause): array {
				$range = $ranges[$index];
				
				// Move JOIN predicate to WHERE clause
				if ($range->getJoinProperty() !== null) {
					$whereClause = $this->astUtilities->combinePredicatesWithAnd([$whereClause, $range->getJoinProperty()]);
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
		
		/**
		 * Create a function that checks if a LEFT range can be safely collapsed to INNER.
		 * @param array<string,bool> $usedInExpr Usage analyzer map
		 * @param array<string,bool> $usedInCond Usage analyzer map
		 * @param array<string,bool> $hasIsNullInCond Usage analyzer map
		 * @param array<string,bool> $nonNullableUse Usage analyzer map
		 * @return callable Function that takes a range and returns bool
		 */
		private function createSafeCollapseChecker(
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
		
		/**
		 * Find a range used in ANY expression that can serve as anchor.
		 * @param AstRange[] $ranges Available ranges
		 * @param AstAny $anyNode ANY node to analyze
		 * @param callable $canCollapse Function to check if range can be collapsed
		 * @return int|null Index of suitable anchor range, null if none found
		 */
		private function findExpressionBasedAnchor(array $ranges, AstAny $anyNode, callable $canCollapse): ?int {
			$expressionRangeNames = $this->getRangeNamesUsedInAnyExpression($anyNode);
			
			foreach ($ranges as $index => $range) {
				$isInExpression = in_array($range->getName(), $expressionRangeNames, true);
				$canBeAnchor = $range->isRequired() || $canCollapse($range);
				
				if ($isInExpression && $canBeAnchor) {
					return $index;
				}
			}
			
			return null;
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
		 * Extract the names of ranges referenced by ANY(expr).
		 * @param AstAny $anyNode The ANY(...) node.
		 * @return string[] Range names used in the ANY expression.
		 */
		private function getRangeNamesUsedInAnyExpression(AstAny $anyNode): array {
			$exprIds = $this->astUtilities->collectIdentifiersFromAst($anyNode->getIdentifier());
			return array_map(static fn($id) => $id->getRange()->getName(), $exprIds);
		}

		/**
		 * Find a range used in the owner's identifier that can serve as anchor.
		 * @param AstRange[] $ranges
		 * @param AstInterface $owner
		 * @param callable $canCollapse
		 * @return int|null
		 */
		private function findExpressionBasedAnchorForOwner(array $ranges, AstInterface $owner, callable $canCollapse): ?int {
			$exprIds = $this->astUtilities->collectIdentifiersFromAst($owner->getIdentifier());
			$exprRangeNames = array_map(static fn($id) => $id->getRange()->getName(), $exprIds);
			
			foreach ($ranges as $index => $range) {
				$isInExpression = in_array($range->getName(), $exprRangeNames, true);
				$canBeAnchor = $range->isRequired() || $canCollapse($range);
				if ($isInExpression && $canBeAnchor) {
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
		private function buildCollapsePredicate(
			array $usedInCond,
			array $hasIsNullInCond,
			array $nonNullableUse
		): callable {
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