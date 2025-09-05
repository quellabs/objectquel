<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * JoinPredicateProcessor handles the processing and splitting of JOIN predicates.
	 *
	 * This class is responsible for:
	 * - Extracting correlation-only predicates from JOIN clauses
	 * - Splitting JOIN predicates by range references (live vs correlation)
	 * - Classifying predicates based on which ranges they reference
	 * - Handling complex predicates with OR operators safely
	 */
	class JoinPredicateProcessor {
		
		/**
		 * For each live range, keep only the inner-related part of its JOIN predicate.
		 * The input array is copied before mutation.
		 *
		 * This method processes correlated subqueries by splitting JOIN conditions:
		 * - Inner parts (references between live ranges) stay in JOIN
		 * - Correlation parts (references to outer query ranges) get removed from JOIN
		 *
		 * Example: JOIN ON (t1.id = t2.id AND t1.status = outer.filter)
		 * Result:  JOIN ON (t1.id = t2.id) -- correlation part removed
		 *
		 * @param AstRange[] $allRanges All ranges in the query (live + correlation)
		 * @param array<string,AstRange> $liveRanges Map of range name -> range object for active ranges
		 * @param string[] $liveRangeNames Names of ranges that are part of this subquery level
		 * @param string[] $correlationRangeNames Names of ranges from outer query contexts
		 * @return AstRange[] Updated ranges with JOINs stripped of correlation-only parts
		 */
		public static function buildUpdatedRangesWithInnerJoinsOnly(
			array $allRanges,
			array $liveRanges,
			array $liveRangeNames,
			array $correlationRangeNames
		): array {
			// Create a copy to avoid mutating the original array
			// This preserves the original state for other operations
			$updatedRanges = $allRanges;
			
			foreach ($updatedRanges as $range) {
				// Skip ranges that aren't part of the active subquery
				// Dead/eliminated ranges don't need JOIN processing
				if (!self::isRangeInLiveSet($range, $liveRanges)) {
					continue;
				}
				
				// Get the current JOIN condition for this range
				// Ranges without JOIN conditions (like the first table) are skipped
				$joinPredicate = $range->getJoinProperty();
				if ($joinPredicate === null) {
					continue;
				}
				
				// Split the JOIN predicate into inner and correlation parts
				// Inner: conditions between tables in current subquery
				// Correlation: conditions referencing outer query tables
				$split = self::splitJoinPredicateByRangeReferences(
					$joinPredicate,
					$liveRangeNames,
					$correlationRangeNames
				);
				
				// Replace the JOIN condition with only the inner part
				// Correlation parts will be handled separately (moved to WHERE)
				// Note: innerPart could be null if JOIN was purely correlational
				$range->setJoinProperty($split['innerPart']);
			}
			
			return $updatedRanges;
		}
		
		/**
		 * For each live range, collect correlation-only JOIN parts to move into WHERE.
		 * This method does not mutate the provided ranges.
		 *
		 * Correlation predicates need to be moved from JOIN to WHERE because:
		 * 1. JOINs should only contain conditions between tables at the same nesting level
		 * 2. Correlation conditions create dependencies on outer query context
		 * 3. Moving to WHERE ensures proper evaluation order in nested queries
		 *
		 * Example: JOIN ON (t1.id = outer.parent_id) becomes WHERE t1.id = outer.parent_id
		 *
		 * @param AstRange[] $allRanges All ranges in the query (live + correlation)
		 * @param array<string,AstRange> $liveRanges Map of range name -> range object for active ranges
		 * @param string[] $liveRangeNames Names of ranges that are part of this subquery level
		 * @param string[] $correlationRangeNames Names of ranges from outer query contexts
		 * @return AstInterface[] Correlation-only predicates to promote to WHERE clause
		 */
		public static function gatherCorrelationOnlyPredicatesFromJoins(
			array $allRanges,
			array $liveRanges,
			array $liveRangeNames,
			array $correlationRangeNames
		): array {
			$promoted = [];
			
			foreach ($allRanges as $range) {
				// Only process ranges that are active in current subquery
				// Inactive ranges won't contribute to the final query
				if (!self::isRangeInLiveSet($range, $liveRanges)) {
					continue;
				}
				
				// Skip ranges without JOIN conditions
				// These are typically the first table in FROM clause
				$joinPredicate = $range->getJoinProperty();
				
				if ($joinPredicate === null) {
					continue;
				}
				
				// Analyze the JOIN predicate to separate concerns
				// This handles complex predicates with multiple conditions
				$split = self::splitJoinPredicateByRangeReferences(
					$joinPredicate,
					$liveRangeNames,
					$correlationRangeNames
				);
				
				// Collect any correlation parts that need to move to WHERE
				// These predicates reference tables from outer query scopes
				if ($split['corrPart'] !== null) {
					$promoted[] = $split['corrPart'];
				}
			}
			
			return $promoted;
		}
		
		/**
		 * Split a predicate into inner and correlation parts based on range references.
		 *
		 * Handles complex predicates containing AND/OR operators by recursively analyzing
		 * each component to determine which ranges they reference. Returns separate
		 * predicates for inner (live range) and correlation parts.
		 *
		 * @param AstInterface|null $predicate The JOIN predicate to split (null = no predicate)
		 * @param string[] $liveRangeNames Names of ranges considered "inner" to current subquery
		 * @param string[] $correlationRangeNames Names of ranges from outer query contexts
		 * @return array{innerPart: AstInterface|null, corrPart: AstInterface|null} Split predicate parts
		 */
		private static function splitJoinPredicateByRangeReferences(
			?AstInterface $predicate,
			array         $liveRangeNames,
			array         $correlationRangeNames
		): array {
			// If no predicate set, return empty data
			if ($predicate === null) {
				return ['innerPart' => null, 'corrPart' => null];
			}
			
			// Handle OR expressions with distribution
			if (AstUtilities::isBinaryOrOperator($predicate)) {
				return self::splitOrPredicate($predicate, $liveRangeNames, $correlationRangeNames);
			}
			
			// Handle AND expressions by processing each conjunct
			if (AstUtilities::isBinaryAndOperator($predicate)) {
				return self::splitAndPredicate($predicate, $liveRangeNames, $correlationRangeNames);
			}
			
			// Leaf node - classify directly
			return match (self::classifyLeafPredicate($predicate, $liveRangeNames, $correlationRangeNames)) {
				'CORR' => ['innerPart' => null, 'corrPart' => $predicate],
				default => ['innerPart' => $predicate, 'corrPart' => null],
			};
		}
		
		/**
		 * Split OR predicate by distributing across operands.
		 *
		 * Transforms (A OR B) into separate inner and correlation parts:
		 * - Inner part: (A_inner OR B_inner) if any inner components exist
		 * - Correlation part: (A_corr OR B_corr) if any correlation components exist
		 *
		 * @param AstInterface $orPredicate The OR predicate to split
		 * @param string[] $liveRangeNames Names of ranges considered "inner"
		 * @param string[] $correlationRangeNames Names of ranges from outer contexts
		 * @return array{innerPart: AstInterface|null, corrPart: AstInterface|null} Split result
		 */
		private static function splitOrPredicate(
			AstInterface $orPredicate,
			array        $liveRangeNames,
			array        $correlationRangeNames
		): array {
			$orTerms = self::flattenOrTerms($orPredicate);
			$innerTerms = [];
			$corrTerms = [];
			
			foreach ($orTerms as $term) {
				$split = self::splitJoinPredicateByRangeReferences($term, $liveRangeNames, $correlationRangeNames);
				
				if ($split['innerPart'] !== null) {
					$innerTerms[] = $split['innerPart'];
				}
				if ($split['corrPart'] !== null) {
					$corrTerms[] = $split['corrPart'];
				}
			}
			
			return [
				'innerPart' => self::combinePredicatesWithOr($innerTerms),
				'corrPart'  => self::combinePredicatesWithOr($corrTerms)
			];
		}
		
		/**
		 * Split AND predicate by separating conjuncts based on range references.
		 *
		 * Processes (A AND B) by classifying each conjunct independently:
		 * - Inner part: contains conjuncts referencing only live ranges
		 * - Correlation part: contains conjuncts referencing correlation ranges
		 *
		 * @param AstInterface $andPredicate The AND predicate to split
		 * @param string[] $liveRangeNames Names of ranges considered "inner"
		 * @param string[] $correlationRangeNames Names of ranges from outer contexts
		 * @return array{innerPart: AstInterface|null, corrPart: AstInterface|null} Split result
		 */
		private static function splitAndPredicate(
			AstInterface $andPredicate,
			array        $liveRangeNames,
			array        $correlationRangeNames
		): array {
			$andTerms = self::flattenAndTerms($andPredicate);
			$innerTerms = [];
			$corrTerms = [];
			
			foreach ($andTerms as $term) {
				$split = self::splitJoinPredicateByRangeReferences($term, $liveRangeNames, $correlationRangeNames);
				
				if ($split['innerPart'] !== null) {
					$innerTerms[] = $split['innerPart'];
				}
				if ($split['corrPart'] !== null) {
					$corrTerms[] = $split['corrPart'];
				}
			}
			
			return [
				'innerPart' => AstUtilities::combinePredicatesWithAnd($innerTerms),
				'corrPart'  => AstUtilities::combinePredicatesWithAnd($corrTerms)
			];
		}
		
		/**
		 * Flatten OR expression tree into a flat array of terms.
		 *
		 * Recursively processes nested OR operators to create a single-level array.
		 * Example: (A OR (B OR C)) becomes [A, B, C]
		 *
		 * @param AstInterface $orExpr The OR expression to flatten
		 * @return AstInterface[] Array of individual OR terms
		 */
		private static function flattenOrTerms(AstInterface $orExpr): array {
			if (!AstUtilities::isBinaryOrOperator($orExpr)) {
				return [$orExpr];
			}
			
			$terms = [];
			$queue = [$orExpr];
			
			while (!empty($queue)) {
				$node = array_pop($queue);
				
				if (AstUtilities::isBinaryOrOperator($node)) {
					$queue[] = $node->getLeft();
					$queue[] = $node->getRight();
				} else {
					$terms[] = $node;
				}
			}
			
			return $terms;
		}
		
		/**
		 * Flatten AND expression tree into a flat array of conjuncts.
		 *
		 * Recursively processes nested AND operators to create a single-level array.
		 * Example: (A AND (B AND C)) becomes [A, B, C]
		 *
		 * @param AstInterface $andExpr The AND expression to flatten
		 * @return AstInterface[] Array of individual AND terms (conjuncts)
		 */
		private static function flattenAndTerms(AstInterface $andExpr): array {
			if (!AstUtilities::isBinaryAndOperator($andExpr)) {
				return [$andExpr];
			}
			
			$terms = [];
			$queue = [$andExpr];
			
			while (!empty($queue)) {
				$node = array_pop($queue);
				
				if (AstUtilities::isBinaryAndOperator($node)) {
					$queue[] = $node->getLeft();
					$queue[] = $node->getRight();
				} else {
					$terms[] = $node;
				}
			}
			
			return $terms;
		}
		
		/**
		 * Classify a leaf predicate based on which ranges it references.
		 *
		 * Analyzes all identifiers in the expression to determine classification:
		 * - INNER: references only live ranges
		 * - CORR: references only correlation ranges
		 * - MIXED: references both live and correlation ranges
		 *
		 * @param AstInterface $expr The leaf expression to classify (no AND/OR operators)
		 * @param string[] $liveRangeNames Names of ranges considered "inner"
		 * @param string[] $correlationRangeNames Names of ranges from outer contexts
		 * @return string Classification result: 'INNER', 'CORR', or 'MIXED'
		 */
		private static function classifyLeafPredicate(
			AstInterface $expr,
			array        $liveRangeNames,
			array        $correlationRangeNames
		): string {
			$ids = AstUtilities::collectIdentifiersFromAst($expr);
			$hasInner = false;
			$hasCorr = false;
			
			foreach ($ids as $id) {
				$range = $id->getRange();
				
				// Skip identifiers without range references
				if ($range === null) {
					continue;
				}
				
				$rangeName = $range->getName();
				
				if (in_array($rangeName, $liveRangeNames, true)) {
					$hasInner = true;
				}
				
				if (in_array($rangeName, $correlationRangeNames, true)) {
					$hasCorr = true;
				}
			}
			
			// For leaf predicates, we can handle mixed references
			// They'll be duplicated in both parts
			if ($hasCorr && $hasInner) {
				return 'MIXED';
			} elseif ($hasCorr) {
				return 'CORR';
			} else {
				return 'INNER';
			}
		}
		
		/**
		 * Check if a range is considered live (actively used in the query).
		 * @param AstRange $range The range to check
		 * @param array<string,AstRange> $liveRanges Map of live range names to ranges
		 * @return bool True if the range is live
		 */
		private static function isRangeInLiveSet(AstRange $range, array $liveRanges): bool {
			return isset($liveRanges[$range->getName()]);
		}
		
		/**
		 * Combine multiple predicates with OR operator.
		 * Filters out null values and handles edge cases.
		 * @param AstInterface[] $predicates Array of predicates to combine
		 * @return AstInterface|null Combined predicate or null if no valid predicates
		 */
		public static function combinePredicatesWithOr(array $predicates): ?AstInterface {
			// Filter out null predicates
			$validPredicates = array_filter($predicates, function($predicate) {
				return $predicate !== null;
			});
			
			// Reindex array to avoid gaps
			$validPredicates = array_values($validPredicates);
			
			// Handle edge cases
			if (empty($validPredicates)) {
				return null;
			}
			
			if (count($validPredicates) === 1) {
				return $validPredicates[0];
			}
			
			// Build OR tree by folding from left to right
			$result = $validPredicates[0];
			
			for ($i = 1; $i < count($validPredicates); $i++) {
				$result = AstFactory::createBinaryOrOperator($result, $validPredicates[$i]);
			}
			
			return $result;
		}
	}