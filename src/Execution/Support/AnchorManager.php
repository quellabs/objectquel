<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
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
		 * @throws QuelException
		 */
		public static function configureRangeAnchors(
			array         $ranges,
			?AstInterface &$whereClause,
			array         $usedInExpr,
			array         $usedInCond,
			array         $hasIsNullInCond,
			array         $nonNullableUse
		): array {
			return self::selectAndConfigureAnchor(
				$ranges,
				$whereClause,
				array_keys(array_filter($usedInExpr, static fn($v) => (bool)$v)),
				$usedInCond,
				$hasIsNullInCond,
				$nonNullableUse
			);
		}
		
		/**
		 * Core logic for selecting a single anchor range.
		 * @param AstRange[] $ranges Array of AST range nodes to evaluate
		 * @param AstInterface|null $whereClause (by-ref) — we may move JOIN → WHERE here
		 * @param string[] $exprRangeNames — ranges referenced by the owner's expression
		 * @param array<string,bool> $usedInCond — tracks which ranges appear in conditions
		 * @param array<string,bool> $hasIsNullInCond — tracks IS NULL checks per range
		 * @param array<string,bool> $nonNullableUse — tracks non-nullable usage patterns
		 * @return AstRange[] Modified ranges array with single anchor established
		 * @throws QuelException When no valid anchor can be determined
		 */
		private static function selectAndConfigureAnchor(
			array         $ranges,
			?AstInterface &$whereClause,
			array         $exprRangeNames,
			array         $usedInCond,
			array         $hasIsNullInCond,
			array         $nonNullableUse
		): array {
			// Early exit for valid states - no processing needed if already optimal
			if (empty($ranges) || self::hasExistingAnchor($ranges)) {
				return $ranges;
			}
			
			// Build collapsibility predicate - determines which ranges can be safely collapsed
			// based on NULL handling semantics and conditional usage patterns
			$canCollapse = self::createCollapseChecker($usedInCond, $hasIsNullInCond, $nonNullableUse);
			
			// Find anchor using improved selection logic - prioritizes expression ranges,
			// then evaluates join types and collapsibility to determine optimal strategy
			$anchorSelection = self::evaluateAnchorOptions($ranges, $exprRangeNames, $canCollapse);
			
			if ($anchorSelection === null) {
				// No viable anchor found - this is now an explicit failure state
				// rather than silently proceeding with suboptimal query structure
				throw new QuelException("No valid anchor range found for query optimization");
			}
			
			// Execute the selected optimization strategy
			switch ($anchorSelection['strategy']) {
				case 'expression_collapsible':
				case 'outer_collapsible':
					// Collapse other ranges into WHERE conditions, preserving semantics
					return self::convertToAnchorRange($ranges, $anchorSelection['index'], $whereClause);
				
				case 'expression_inner':
				case 'inner_preserve':
					// Promote selected range to anchor without structural changes
					return self::promoteToAnchor($ranges, $anchorSelection['index']);
				
				case 'inner_collapsible':
					// Inner join that can be collapsed - move conditions to WHERE
					return self::convertToAnchorRange($ranges, $anchorSelection['index'], $whereClause);
				
				default:
					// Defensive programming - should never reach here with valid input
					return throw new QuelException("Unknown anchor strategy: {$anchorSelection['strategy']}");
			}
		}
		
		/**
		 * Find the optimal anchor range using a comprehensive selection strategy.
		 * @param AstRange[] $ranges Array of AST ranges to evaluate as potential anchors
		 * @param string[] $exprRangeNames Corresponding names for expression ranges
		 * @param callable $canCollapse Callback function to determine if a range can be collapsed
		 * @return array{index: int, strategy: string, score: int}|null
		 *         Returns the optimal anchor with its array index, selection strategy used, and
		 *         priority score, or null if no viable anchor is found
		 */
		private static function evaluateAnchorOptions(array $ranges, array $exprRangeNames, callable $canCollapse): ?array {
			$candidates = [];
			
			foreach ($ranges as $index => $range) {
				// Assess for viability and assigned a priority score
				$evaluation = self::scoreRangeForAnchor($range, $index, $exprRangeNames, $canCollapse);
				
				// Only consider ranges that pass the viability check
				if ($evaluation['viable']) {
					$candidates[] = $evaluation;
				}
			}
			
			// Return null if no ranges meet the anchor criteria
			if (empty($candidates)) {
				return null;
			}
			
			// Sort candidates by priority score in descending order
			// Higher scores indicate more suitable anchor points
			usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
			
			// Return the highest-scoring candidate as the optimal anchor
			return $candidates[0];
		}
		
		/**
		 * Evaluate a range as a potential anchor.
		 * @param AstRange $range
		 * @param int $index
		 * @param string[] $exprRangeNames
		 * @param callable $canCollapse
		 * @return array{index: int, strategy: string, score: int, viable: bool}
		 */
		private static function scoreRangeForAnchor(AstRange $range, int $index, array $exprRangeNames, callable $canCollapse): array {
			// Extract basic range properties for evaluation
			$rangeName = $range->getName();
			$joinType = $range->isRequired() ? "INNER" : "LEFT";
			$isCollapsible = $canCollapse($range);
			$isExpressionRange = in_array($rangeName, $exprRangeNames, true);
			
			// Determine if this range can serve as a viable anchor based on join type
			// INNER joins are always viable as they guarantee result rows
			// LEFT joins are only viable if the range can be collapsed (optimized away)
			switch ($joinType) {
				case 'INNER':
					// INNER joins guarantee matching rows exist, making them reliable anchors
					$viable = true;
					break;
				
				case 'LEFT':
					// LEFT joins may produce NULL results, only viable if collapsible
					$viable = $isCollapsible;
					break;
				
				default:
					// Unknown join types cannot be safely used as anchors
					$viable = false;
					break;
			}
			
			// Early return for non-viable ranges to avoid unnecessary computation
			if (!$viable) {
				return [
					'index'    => $index,
					'strategy' => 'rejected',
					'score'    => -1,
					'viable'   => false
				];
			}
			
			// For viable ranges, calculate priority score and determine optimization strategy
			return [
				'index'    => $index,
				'strategy' => self::selectOptimizationStrategy($isExpressionRange, $joinType, $isCollapsible),
				'score'    => self::computeAnchorPriority($isExpressionRange, $isCollapsible, $joinType),
				'viable'   => true
			];
		}
		
		/**
		 * Calculate priority score for anchor selection.
		 * Higher scores indicate better anchor candidates.
		 * @param bool $isExpressionRange
		 * @param bool $isCollapsible
		 * @param string $joinType
		 * @return int
		 */
		private static function computeAnchorPriority(bool $isExpressionRange, bool $isCollapsible, string $joinType): int {
			$score = 0;
			
			// Primary priority: Expression ranges (they drive the query logic)
			if ($isExpressionRange) {
				$score += 1000;
			}
			
			// Secondary priority: Join type preference
			switch ($joinType) {
				case 'INNER':
					$score += 100;
					break;
				
				case 'LEFT':
					$score += 50;
					break;
				
				case 'RIGHT':
					$score += 25;
					break;
				
				default:
					$score += 0;
					break;
			}
			
			// Tertiary priority: Collapsibility bonus
			if ($isCollapsible) {
				$score += 10;
			}
			
			// Additional scoring factors could be added here:
			// - Table size estimates
			// - Index availability
			// - Selectivity hints
			return $score;
		}
		
		/**
		 * Determine the strategy used for anchor selection.
		 * @param bool $isExpressionRange
		 * @param string $joinType
		 * @param bool $isCollapsible
		 * @return string
		 * @throws QuelException
		 */
		private static function selectOptimizationStrategy(bool $isExpressionRange, string $joinType, bool $isCollapsible): string {
			// Expression ranges have priority over join type
			if ($isExpressionRange) {
				return $isCollapsible ? 'expression_collapsible' : 'expression_inner';
			}
			
			// Handle join-based strategies (system supports INNER and LEFT only)
			return match (strtoupper($joinType)) {
				'INNER' => $isCollapsible ? 'inner_collapsible' : 'inner_preserve',
				'LEFT' => 'outer_collapsible', // Collapsible state irrelevant for left joins
				default => throw new QuelException("Unsupported join type: {$joinType}. Only INNER and LEFT joins are supported.")
			};
		}
		
		/**
		 * Collapse other ranges into the anchor range.
		 * @param array $ranges
		 * @param int $anchorIndex
		 * @param AstInterface|null $whereClause
		 * @return AstRange[]
		 */
		private static function convertToAnchorRange(array $ranges, int $anchorIndex, ?AstInterface &$whereClause): array {
			// Fetch the new anchor
			$anchor = $ranges[$anchorIndex];
			
			// Move the anchor's own JOIN expression to WHERE before clearing it
			if ($anchor->getJoinProperty() !== null) {
				$whereClause = self::mergeWhereConditions($whereClause, $anchor->getJoinProperty());
			}
			
			// Clear the join properties of the anchor
			$anchor->setJoinProperty(null);
			
			// Extract JOIN expressions from collapsed ranges and add to WHERE
			foreach ($ranges as $index => $range) {
				if ($index !== $anchorIndex && $range->getJoinProperty() !== null) {
					$whereClause = self::mergeWhereConditions($whereClause, $range->getJoinProperty());
				}
			}
			
			// Return the new anchor
			return [$anchor];
		}
		
		/**
		 * Combine two expression using an AND operator
		 * @param AstInterface|null $existing
		 * @param AstInterface $newCondition
		 * @return AstInterface
		 */
		private static function mergeWhereConditions(?AstInterface $existing, AstInterface $newCondition): AstInterface {
			// If no existing WHERE clause, the new condition becomes the WHERE clause
			if ($existing === null) {
				return $newCondition;
			}
			
			// If there's already a WHERE clause, AND them together
			return AstFactory::createBinaryAndOperator($existing, $newCondition);
		}
		
		/**
		 * Promote a range to anchor without collapsing others.
		 */
		private static function promoteToAnchor(array $ranges, int $anchorIndex): array {
			$anchor = $ranges[$anchorIndex];
			$anchor->setAsAnchor(true);
			return $ranges;
		}
		
		/**
		 * Check if any range already serves as an anchor (has null joinProperty).
		 * @param AstRange[] $ranges Ranges to check
		 * @return bool True if an anchor exists
		 */
		private static function hasExistingAnchor(array $ranges): bool {
			foreach ($ranges as $range) {
				if ($range->getJoinProperty() === null) {
					return true;
				}
			}
			
			return false;
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
		private static function createCollapseChecker(array $usedInCond, array $hasIsNullInCond, array $nonNullableUse): callable {
			return function ($range) use ($usedInCond, $hasIsNullInCond, $nonNullableUse): bool {
				// Fetch the name of the range
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