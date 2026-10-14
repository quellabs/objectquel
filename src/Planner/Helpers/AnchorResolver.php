<?php
	
	namespace Quellabs\ObjectQuel\Planner\Helpers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * AnchorManager optimizes SQL queries by selecting the best "anchor" table.
	 *
	 * An ANCHOR is the primary table that other tables join to. Every SQL query needs
	 * exactly one anchor (a FROM table with no JOIN conditions). This class:
	 *
	 * 1. Identifies the optimal table to serve as the query anchor
	 * 2. Moves that table's own join condition into the WHERE clause
	 * Other tables keep their join conditions and INNER/LEFT join type.
	 */
	class AnchorResolver {
		
		// Priority scores for anchor selection (higher = better)
		private const int PRIORITY_EXPRESSION_REFERENCED = 1000;  // Table used in SELECT expressions
		private const int PRIORITY_INNER_JOIN = 100;              // INNER JOIN (guaranteed rows)
		private const int PRIORITY_ORIGINAL_ANCHOR = 200;         // Stability bonus for keeping current anchor
		private const int PRIORITY_COLLAPSIBLE_BONUS = 10;        // Its LEFT JOIN could safely be INNER
		
		/**
		 * Main entry point: Ensures exactly one anchor exists and optimizes the query structure.
		 *
		 * This method orchestrates the entire anchor optimization process:
		 * 1. Validates input ranges exist
		 * 2. Checks if optimization is needed (no existing anchor)
		 * 3. Selects the optimal anchor table based on usage patterns
		 * 4. Moves the anchor's join condition into the WHERE clause
		 *
		 * @param AstRange[] $ranges All table references in the query
		 * @param AstInterface|null &$whereClause WHERE clause (modified to include moved JOIN conditions)
		 * @param QueryAnalysisResult $analysis Pre-computed analysis of table usage patterns
		 * @return AstRange[] Optimized ranges with exactly one anchor
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
			
			// Make it the FROM range
			return self::promoteToAnchor($ranges, $anchorCandidate, $whereClause);
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
		 * @return AnchorCandidate
		 * @throws \LogicException If no viable anchor exists — the parser should prevent this.
		 */
		private static function selectBestAnchor(array $ranges, QueryAnalysisResult $analysis): AnchorCandidate {
			$candidates = [];
			
			foreach ($ranges as $index => $range) {
				// Evaluate the potential anchor
				$candidate = self::evaluateAsAnchor($range, $index, $analysis);
				
				// If viable add it to the list
				if ($candidate->isViable()) {
					$candidates[] = $candidate;
				}
			}
			
			// The parser enforces valid query structure, so this should be unreachable.
			if (empty($candidates)) {
				throw new \LogicException("No valid anchor range found for query optimization.");
			}
			
			// A range without a join condition is already a FROM range; promoting a joined
			// one instead would only turn its JOIN into a CROSS JOIN plus a WHERE condition
			$unjoined = array_values(array_filter($candidates, fn(AnchorCandidate $c) => $c->getRange()->getJoinProperty() === null));
			
			if (!empty($unjoined)) {
				$candidates = $unjoined;
			}
			
			// Select highest priority candidate (best optimization potential)
			usort($candidates, function(AnchorCandidate $a, AnchorCandidate $b): int {
				return $b->getPriorityScore() <=> $a->getPriorityScore();
			});
			
			// Return the best match
			return $candidates[0];
		}
		
		/**
		 * Evaluate whether a table can serve as a good anchor.
		 *
		 * Analyzes a table's characteristics to determine:
		 * - Viability: Can it technically serve as an anchor?
		 * - Priority: How beneficial would it be as an anchor?
		 *
		 * Key factors:
		 * - Expression usage: Tables in SELECT get highest priority
		 * - JOIN type: INNER JOINs preferred over LEFT JOINs
		 * - Collapse safety: Could its LEFT JOIN safely be INNER?
		 *
		 * @param AstRange $range Table reference to evaluate
		 * @param int $index Position in ranges array (for tracking)
		 * @param QueryAnalysisResult $analysis Pre-computed usage patterns
		 * @return AnchorCandidate Evaluation result with priority and viability
		 */
		private static function evaluateAsAnchor(AstRange $range, int $index, QueryAnalysisResult $analysis): AnchorCandidate {
			// Tables with JOIN conditions that are not required (LEFT JOINs)
			// cannot be anchors - this would change query semantics
			if ($range->getJoinProperty() !== null && !$range->isRequired()) {
				return new AnchorCandidate($index, $range, 0, false);
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
			if ($retrieveObject->getMainDatabaseRange()?->getName() === $rangeName) {
				$priority += self::PRIORITY_ORIGINAL_ANCHOR;
			} else {
				$priority += self::PRIORITY_INNER_JOIN;
			}
			
			// Bonus for tables where JOIN conditions can be safely optimized
			if ($canOptimizeJoinConditions) {
				$priority += self::PRIORITY_COLLAPSIBLE_BONUS;
			}
			
			// Create candidate with all evaluation data
			return new AnchorCandidate(
				$index,
				$range,
				$priority,
				true  // viable if INNER or original anchor
			);
		}
		
		/**
		 * Make the selected range the FROM range by moving its join condition into WHERE.
		 *
		 * Other ranges keep their join conditions: moving a LEFT JOIN's condition into
		 * WHERE would drop its NULL-extended rows. Moving the anchor's own condition is
		 * safe because the anchor is only selected when it is INNER-joined or has none.
		 *
		 * @param AstRange[] $ranges Original table references
		 * @param AnchorCandidate $anchor Selected anchor candidate
		 * @param AstInterface|null &$whereClause WHERE clause to extend
		 * @return AstRange[] Ranges with the anchor's join condition removed
		 */
		private static function promoteToAnchor(
			array           $ranges,
			AnchorCandidate $anchor,
			?AstInterface   &$whereClause
		): array {
			$anchorRange = $ranges[$anchor->getIndex()];
			
			if ($anchorRange->getJoinProperty() !== null) {
				$whereClause = self::addToWhereClause($whereClause, $anchorRange->getJoinProperty());
				$anchorRange->setJoinProperty(null);
			}
			
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