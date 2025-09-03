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
		public function ensureSingleAnchorRange(
			array         $ranges,
			AstAny        $anyNode,
			?AstInterface &$whereClause,
			array         $usedInExpr,
			array         $usedInCond,
			array         $hasIsNullInCond,
			array         $nonNullableUse
		): array {
			if (empty($ranges)) {
				return $ranges;
			}
			
			// Fast path: anchor already exists
			if ($this->hasValidAnchorRange($ranges)) {
				return $ranges;
			}
			
			// Priority 1: Range used in ANY(expr) that's INNER or safely collapsible
			$anchorMaker = $this->createAnchorMaker($ranges, $whereClause);
			$canCollapse = $this->createSafeCollapseChecker($usedInExpr, $usedInCond, $hasIsNullInCond, $nonNullableUse);
			$anchorIndex = $this->findExpressionBasedAnchor($ranges, $anyNode, $canCollapse);
			
			if ($anchorIndex !== null) {
				if (!$ranges[$anchorIndex]->isRequired()) {
					$ranges[$anchorIndex]->setRequired(true); // Safe LEFT → INNER
				}
				
				return $anchorMaker($anchorIndex);
			}
			
			// Priority 2: Any existing INNER range
			$anchorIndex = $this->findInnerRangeAnchor($ranges);
			
			if ($anchorIndex !== null) {
				return $anchorMaker($anchorIndex);
			}
			
			// Priority 3: LEFT range that can safely become INNER
			$anchorIndex = $this->findCollapsibleLeftAnchor($ranges, $canCollapse);
			
			if ($anchorIndex !== null) {
				$ranges[$anchorIndex]->setRequired(true);
				return $anchorMaker($anchorIndex);
			}
			
			// No safe transformation possible - preserve semantics
			return $ranges;
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
	}