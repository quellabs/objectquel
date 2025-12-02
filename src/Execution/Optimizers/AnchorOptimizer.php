<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\Execution\Support\AnchorCandidate;
	use Quellabs\ObjectQuel\Execution\Support\AstFactory;
	use Quellabs\ObjectQuel\Execution\Support\QueryAnalysisResult;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
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
	class AnchorOptimizer {
		
		// Priority scores for anchor selection (higher = better)
		private const int PRIORITY_EXPRESSION_REFERENCED = 1000;  // Table used in SELECT expressions
		private const int PRIORITY_INNER_JOIN = 100;              // INNER JOIN (guaranteed rows)
		private const int PRIORITY_ORIGINAL_ANCHOR = 200;         // Stability bonus for keeping current anchor
		private const int PRIORITY_COLLAPSIBLE_BONUS = 10;        // Can optimize JOIN conditions
		
		/**
		 * Main entry point: Ensures exactly one anchor exists and optimizes the query structure.
		 *
		 * This method orchestrates the entire anchor optimization process:
		 * 1. Validates input ranges exist
		 * 2. Checks if optimization is needed (no existing anchor)
		 * 3. Selects the optimal anchor table based on usage patterns
		 * 4. Applies the optimization strategy to restructure the query
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
			// Early return for empty queries - nothing to optimize
			if (empty($ranges)) {
				return $ranges;
			}
			
			// Find the best table to serve as the primary anchor
			$anchorCandidate = self::selectBestAnchor($ranges, $analysis);
			
			// Critical error: no viable anchor means malformed query
			if ($anchorCandidate === null) {
				throw new QuelException("No valid anchor range found for query optimization");
			}
			
			// Apply the selected optimization strategy
			return self::applyAnchorOptimization($ranges, $anchorCandidate, $whereClause);
		}
		
		/**
		 * Find the best table to serve as the query anchor.
		 *
		 * Evaluates each table as a potential anchor by:
		 * 1. Calculating viability (can it serve as anchor?)
		 * 2. Computing priority score based on usage patterns
		 * 3. Selecting the highest-scoring viable candidate
		 *
		 * @param AstRange[] $ranges All table references to evaluate
		 * @param QueryAnalysisResult $analysis Table usage analysis data
		 * @return AnchorCandidate|null Best anchor candidate or null if none viable
		 */
		private static function selectBestAnchor(array $ranges, QueryAnalysisResult $analysis): ?AnchorCandidate {
			$candidates = [];
			
			foreach ($ranges as $index => $range) {
				// Evaluate the potential anchor
				$candidate = self::evaluateAsAnchor($range, $index, $analysis);
				
				// If viable add it to the list
				if ($candidate->isViable()) {
					$candidates[] = $candidate;
				}
			}
			
			// No viable anchors found - query structure issue
			// Should never happen, because parser enforces correct structure
			if (empty($candidates)) {
				return null;
			}
			
			// Select highest priority candidate (best optimization potential)
			usort($candidates, fn($a, $b) => $b->getPriorityScore() <=> $a->getPriorityScore());
			
			// Return the best match
			return $candidates[0];
		}
		
		/**
		 * Evaluate whether a table can serve as a good anchor.
		 *
		 * Analyzes a table's characteristics to determine:
		 * - Viability: Can it technically serve as an anchor?
		 * - Priority: How beneficial would it be as an anchor?
		 * - Strategy: What optimization approach should be used?
		 *
		 * Key factors:
		 * - Expression usage: Tables in SELECT get highest priority
		 * - JOIN type: INNER JOINs preferred over LEFT JOINs
		 * - Optimization potential: Can JOIN conditions be collapsed?
		 *
		 * @param AstRange $range Table reference to evaluate
		 * @param int $index Position in ranges array (for tracking)
		 * @param QueryAnalysisResult $analysis Pre-computed usage patterns
		 * @return AnchorCandidate Evaluation result with priority and strategy
		 */
		private static function evaluateAsAnchor(AstRange $range, int $index, QueryAnalysisResult $analysis): AnchorCandidate {
			// Tables with JOIN conditions that are not required (LEFT JOINs)
			// cannot be anchors - this would change query semantics
			if ($range->getJoinProperty() !== null && !$range->isRequired()) {
				return new AnchorCandidate($index, $range, 0, 'left_join_not_viable', false);
			}
			
			// Analyze table characteristics
			/**
			 * @var AstRetrieve $retrieveObject
			 */
			$retrieveObject = $range->getParent();
			$rangeName = $range->getName();
			$tableUsage = $analysis->getTableUsage($rangeName);
			$isReferencedInExpressions = $tableUsage->isUsedInSelectExpressions(); // Used in SELECT
			$canOptimizeJoinConditions = $tableUsage->canSafelyCollapseToInner();   // Optimization safe
			
			// Calculate priority score based on anchor quality factors
			$priority = 0;
			
			// Highest priority: tables referenced in SELECT expressions
			// These MUST return rows, making them ideal anchors
			if ($isReferencedInExpressions) {
				$priority += self::PRIORITY_EXPRESSION_REFERENCED;
			}
			
			// Original anchor gets stability bonus, INNER JOINs get standard priority
			if ($retrieveObject->getMainDatabaseRange()->getName() !== $rangeName) {
				$priority += self::PRIORITY_ORIGINAL_ANCHOR;
			} else {
				$priority += self::PRIORITY_INNER_JOIN;
			}
			
			// Bonus for tables where JOIN conditions can be safely optimized
			if ($canOptimizeJoinConditions) {
				$priority += self::PRIORITY_COLLAPSIBLE_BONUS;
			}
			
			// Determine the optimization strategy based on characteristics
			$strategy = self::determineOptimizationStrategy(
				$isReferencedInExpressions,
				$canOptimizeJoinConditions
			);
			
			// Create candidate with all evaluation data
			return new AnchorCandidate(
				$index,
				$range,
				$priority,
				$strategy,
				true  // viable if INNER or original anchor
			);
		}
		
		/**
		 * Determine how to optimize this anchor choice.
		 *
		 * Selects optimization strategy based on table characteristics:
		 *
		 * Expression-referenced tables (highest priority):
		 * - These must return rows, making them ideal anchors
		 * - Can usually be optimized safely
		 *
		 * INNER JOINs:
		 * - Guaranteed to return rows
		 * - Safe to optimize if analysis permits
		 *
		 * LEFT JOINs:
		 * - Can be converted to INNER if safe (performance boost)
		 * - Must preserve NULL semantics if conversion unsafe
		 *
		 * @param bool $isExpressionReferenced Table used in SELECT expressions
		 * @param bool $canOptimizeJoins Safe to collapse JOIN conditions
		 * @return string Strategy identifier for optimization phase
		 */
		private static function determineOptimizationStrategy(
			bool $isExpressionReferenced,
			bool $canOptimizeJoins
		): string {
			// Expression-referenced tables get priority treatment
			if ($isExpressionReferenced) {
				return $canOptimizeJoins ? 'expression_with_optimization' : 'expression_preserve';
			}
			
			// Non-expression tables: attempt optimization if safe
			return $canOptimizeJoins ? 'inner_with_optimization' : 'inner_preserve';
		}
		
		/**
		 * Apply the selected anchor optimization to the query.
		 *
		 * Executes the optimization strategy determined during evaluation:
		 *
		 * Optimization strategies:
		 * - WITH_OPTIMIZATION: Move JOIN conditions to WHERE clause for better performance
		 * - PRESERVE: Keep existing structure, just designate anchor
		 * - OPTIMIZE_TO_INNER: Convert LEFT JOIN to INNER JOIN where safe
		 *
		 * @param AstRange[] $ranges Original table references
		 * @param AnchorCandidate $anchor Selected anchor with strategy
		 * @param AstInterface|null &$whereClause WHERE clause to modify
		 * @return AstRange[] Optimized ranges array
		 */
		private static function applyAnchorOptimization(
			array           $ranges,
			AnchorCandidate $anchor,
			?AstInterface   &$whereClause
		): array {
			$strategy = $anchor->getStrategy();
			
			// Ensure all ranges are AstRangeDatabase instances
			// This is required because we need setJoinProperty() which only exists on AstRangeDatabase
			assert(array_reduce($ranges, fn($carry, $range) => $carry && $range instanceof AstRangeDatabase, true),
				'All ranges must be AstRangeDatabase instances for anchor optimization');
			
			// Check if strategy involves JOIN condition movement/optimization
			if (str_contains($strategy, 'optimization') || str_contains($strategy, 'optimize')) {
				// Complex optimization: restructure query with condition movement
				/** @var AstRangeDatabase[] $ranges */
				return self::convertToAnchorWithOptimization($ranges, $anchor, $whereClause);
			} else {
				// Simple optimization: preserve structure, designate anchor only
				/** @var AstRangeDatabase[] $ranges */
				return self::promoteToAnchorWithoutChanges($ranges, $anchor);
			}
		}
		
		/**
		 * Convert selected table to anchor and move JOIN conditions to WHERE clause.
		 *
		 * This is the most aggressive optimization that:
		 * 1. Promotes the selected table to anchor status (removes JOIN condition)
		 * 2. Moves all JOIN conditions to WHERE clause
		 * 3. Allows database optimizer to choose optimal JOIN order
		 * 4. Often results in better query performance
		 *
		 * Example transformation:
		 * FROM users u LEFT JOIN orders o ON u.id = o.user_id
		 * ->
		 * FROM users u, orders o WHERE u.id = o.user_id
		 *
		 * @param AstRangeDatabase[] $ranges Original table references
		 * @param AnchorCandidate $anchor Selected anchor candidate
		 * @param AstInterface|null &$whereClause WHERE clause to extend
		 * @return AstRangeDatabase[] Array containing all tables with optimized structure
		 */
		private static function convertToAnchorWithOptimization(
			array           $ranges,
			AnchorCandidate $anchor,
			?AstInterface   &$whereClause
		): array {
			$anchorRange = $ranges[$anchor->getIndex()];
			
			// Move the anchor's JOIN condition to WHERE clause
			// This converts "FROM ... JOIN anchor ON condition" to "FROM anchor WHERE condition"
			if ($anchorRange->getJoinProperty() !== null) {
				$whereClause = self::addToWhereClause($whereClause, $anchorRange->getJoinProperty());
				$anchorRange->setJoinProperty(null); // Remove JOIN condition
			}
			
			// Move all other tables' JOIN conditions to WHERE clause
			// This creates cross-product with WHERE filtering (often more efficient)
			foreach ($ranges as $index => $range) {
				if ($index !== $anchor->getIndex() && $range->getJoinProperty() !== null) {
					$whereClause = self::addToWhereClause($whereClause, $range->getJoinProperty());
				}
			}
			
			// Return all ranges - other tables become implicit cross-product
			// Database will optimize JOIN order based on WHERE conditions
			return $ranges;
		}
		
		/**
		 * Simply promote the selected table to anchor without structural changes.
		 *
		 * This conservative optimization:
		 * 1. Designates the selected table as the anchor
		 * 2. Preserves all existing JOIN conditions and structure
		 * 3. Used when aggressive optimization might change query semantics
		 *
		 * Suitable for:
		 * - Complex JOIN conditions that shouldn't be moved
		 * - Queries where LEFT JOIN NULL semantics must be preserved
		 * - Cases where structural changes might affect result correctness
		 *
		 * @param AstRangeDatabase[] $ranges Original table references
		 * @param AnchorCandidate $anchor Selected anchor candidate
		 * @return AstRangeDatabase[] Original ranges with anchor designation
		 */
		private static function promoteToAnchorWithoutChanges(array $ranges, AnchorCandidate $anchor): array {
			// Get anchor
			$anchorRange = $ranges[$anchor->getIndex()];
			
			// Remove JOIN condition from the selected anchor to make it the FROM table
			$anchorRange->setJoinProperty(null);
			
			// Return ranges
			return $ranges;
		}
		
		/**
		 * Add a condition to the WHERE clause using AND logic.
		 *
		 * Combines existing WHERE conditions with new JOIN conditions moved
		 * from the FROM clause. Uses logical AND to ensure both conditions
		 * must be satisfied.
		 *
		 * Handles two cases:
		 * 1. No existing WHERE: new condition becomes the WHERE clause
		 * 2. Existing WHERE: combine with AND operator
		 *
		 * @param AstInterface|null $existing Current WHERE clause (null if none)
		 * @param AstInterface $newCondition JOIN condition being moved to WHERE
		 * @return AstInterface Combined WHERE clause
		 */
		private static function addToWhereClause(?AstInterface $existing, AstInterface $newCondition): AstInterface {
			// First WHERE condition - use as-is
			if ($existing === null) {
				return $newCondition;
			}
			
			// Combine existing WHERE with new condition using AND
			// This preserves both the original WHERE logic and the JOIN condition
			return AstFactory::createBinaryAndOperator($existing, $newCondition);
		}
		
		/**
		 * Check if any table already serves as an anchor.
		 *
		 * An anchor is identified by having no JOIN condition - it's the base table
		 * that all other tables join against. If one exists, no optimization needed.
		 *
		 * SQL equivalents:
		 * - Anchor: FROM users (no JOIN keyword)
		 * - Not anchor: JOIN orders ON ... (has JOIN condition)
		 *
		 * @param AstRange[] $ranges All table references to check
		 * @return bool True if an anchor already exists
		 * @phpstan-ignore-next-line method.unused
		 */
		private static function hasExistingAnchor(array $ranges): bool {
			foreach ($ranges as $range) {
				// Table with no JOIN condition is the anchor
				if ($range->getJoinProperty() === null) {
					return true;
				}
			}
			
			// All tables have JOIN conditions - no anchor exists yet
			return false;
		}
	}