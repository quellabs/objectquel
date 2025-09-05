<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
	/**
	 * AnchorManager optimizes SQL queries by selecting the best "anchor" table.
	 *
	 * An ANCHOR is the primary table that other tables join to. Every SQL query needs
	 * exactly one anchor (a FROM table with no JOIN conditions). This class:
	 *
	 * 1. Identifies the optimal table to serve as the query anchor
	 * 2. Converts other table references to JOINs against that anchor
	 * 3. Safely converts LEFT JOINs to INNER JOINs when possible for better performance
	 *
	 * Example transformation:
	 * BEFORE: SELECT * FROM users u, orders o WHERE u.id = o.user_id
	 * AFTER:  SELECT * FROM users u INNER JOIN orders o ON u.id = o.user_id
	 */
	class AnchorManager {
		// Priority scores for anchor selection (higher = better)
		private const int PRIORITY_EXPRESSION_REFERENCED = 1000;  // Table used in SELECT expressions
		private const int PRIORITY_INNER_JOIN = 100;              // INNER JOIN (guaranteed rows)
		private const int PRIORITY_LEFT_JOIN = 50;                // LEFT JOIN (possible NULLs)
		private const int PRIORITY_COLLAPSIBLE_BONUS = 10;        // Can optimize JOIN conditions
		
		/**
		 * Main entry point: Ensures exactly one anchor exists and optimizes the query structure.
		 *
		 * @param AstRange[] $ranges All table references in the query
		 * @param AstInterface|null &$whereClause WHERE clause (modified to include moved JOIN conditions)
		 * @param QueryAnalysisResult $analysis Pre-computed analysis of table usage patterns
		 * @return AstRange[] Optimized ranges with exactly one anchor
		 * @throws QuelException When no valid anchor can be determined
		 */
		public static function configureRangeAnchors(
			array               $ranges,
			?AstInterface       &$whereClause,
			QueryAnalysisResult $analysis
		): array {
			if (empty($ranges)) {
				return $ranges;
			}
			
			if (self::hasExistingAnchor($ranges)) {
				return $ranges; // Already optimized
			}
			
			$anchorCandidate = self::selectBestAnchor($ranges, $analysis);
			
			if ($anchorCandidate === null) {
				throw new QuelException("No valid anchor range found for query optimization");
			}
			
			return self::applyAnchorOptimization($ranges, $anchorCandidate, $whereClause);
		}
		
		/**
		 * Find the best table to serve as the query anchor.
		 */
		private static function selectBestAnchor(array $ranges, QueryAnalysisResult $analysis): ?AnchorCandidate {
			$candidates = [];
			
			foreach ($ranges as $index => $range) {
				$candidate = self::evaluateAsAnchor($range, $index, $analysis);
				
				if ($candidate->isViable()) {
					$candidates[] = $candidate;
				}
			}
			
			if (empty($candidates)) {
				return null;
			}
			
			// Sort by priority score (highest first)
			usort($candidates, fn($a, $b) => $b->getPriorityScore() <=> $a->getPriorityScore());
			
			return $candidates[0];
		}
		
		/**
		 * Evaluate whether a table can serve as a good anchor.
		 */
		private static function evaluateAsAnchor(AstRange $range, int $index, QueryAnalysisResult $analysis): AnchorCandidate {
			$tableName = $range->getName();
			$tableUsage = $analysis->getTableUsage($tableName);
			
			$isInnerJoin = $range->isRequired();
			$isReferencedInExpressions = $tableUsage->isUsedInSelectExpressions();
			$canOptimizeJoinConditions = $tableUsage->canSafelyCollapseToInner();
			
			// Calculate priority score
			$priority = 0;
			
			if ($isReferencedInExpressions) {
				$priority += self::PRIORITY_EXPRESSION_REFERENCED;
			}
			
			if ($isInnerJoin) {
				$priority += self::PRIORITY_INNER_JOIN;
			} else {
				$priority += self::PRIORITY_LEFT_JOIN;
			}
			
			if ($canOptimizeJoinConditions) {
				$priority += self::PRIORITY_COLLAPSIBLE_BONUS;
			}
			
			// Determine optimization strategy
			$strategy = self::determineOptimizationStrategy(
				$isReferencedInExpressions,
				$isInnerJoin,
				$canOptimizeJoinConditions
			);
			
			return new AnchorCandidate(
				$index,
				$range,
				$priority,
				$strategy,
				$isInnerJoin || $canOptimizeJoinConditions  // viable if INNER or optimizable LEFT
			);
		}
		
		/**
		 * Determine how to optimize this anchor choice.
		 */
		private static function determineOptimizationStrategy(
			bool $isExpressionReferenced,
			bool $isInnerJoin,
			bool $canOptimizeJoins
		): string {
			if ($isExpressionReferenced) {
				return $canOptimizeJoins ? 'expression_with_optimization' : 'expression_preserve';
			}
			
			if ($isInnerJoin) {
				return $canOptimizeJoins ? 'inner_with_optimization' : 'inner_preserve';
			}
			
			return 'left_optimize_to_inner';
		}
		
		/**
		 * Apply the selected anchor optimization to the query.
		 */
		private static function applyAnchorOptimization(
			array           $ranges,
			AnchorCandidate $anchor,
			?AstInterface   &$whereClause
		): array {
			$strategy = $anchor->getStrategy();
			
			if (str_contains($strategy, 'optimization') || str_contains($strategy, 'optimize')) {
				return self::convertToAnchorWithOptimization($ranges, $anchor, $whereClause);
			} else {
				return self::promoteToAnchorWithoutChanges($ranges, $anchor);
			}
		}
		
		/**
		 * Convert selected table to anchor and move JOIN conditions to WHERE clause.
		 */
		private static function convertToAnchorWithOptimization(
			array           $ranges,
			AnchorCandidate $anchor,
			?AstInterface   &$whereClause
		): array {
			$anchorRange = $ranges[$anchor->getIndex()];
			
			// Move the anchor's JOIN condition to WHERE
			if ($anchorRange->getJoinProperty() !== null) {
				$whereClause = self::addToWhereClause($whereClause, $anchorRange->getJoinProperty());
				$anchorRange->setJoinProperty(null);
			}
			
			// Move other tables' JOIN conditions to WHERE
			foreach ($ranges as $index => $range) {
				if ($index !== $anchor->getIndex() && $range->getJoinProperty() !== null) {
					$whereClause = self::addToWhereClause($whereClause, $range->getJoinProperty());
				}
			}
			
			return [$anchorRange];  // Return only the anchor
		}
		
		/**
		 * Simply promote the selected table to anchor without structural changes.
		 */
		private static function promoteToAnchorWithoutChanges(array $ranges, AnchorCandidate $anchor): array {
			$ranges[$anchor->getIndex()]->setAsAnchor(true);
			return $ranges;
		}
		
		/**
		 * Add a condition to the WHERE clause using AND logic.
		 */
		private static function addToWhereClause(?AstInterface $existing, AstInterface $newCondition): AstInterface {
			if ($existing === null) {
				return $newCondition;
			}
			
			return AstFactory::createBinaryAndOperator($existing, $newCondition);
		}
		
		/**
		 * Check if any table already serves as an anchor.
		 */
		private static function hasExistingAnchor(array $ranges): bool {
			foreach ($ranges as $range) {
				if ($range->getJoinProperty() === null) {
					return true;
				}
			}
			return false;
		}
	}