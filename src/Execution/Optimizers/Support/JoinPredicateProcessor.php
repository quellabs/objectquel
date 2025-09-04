<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers\Support;
	
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
		 * Split a predicate into:
		 *   - innerPart: references only $liveRangeNames
		 *   - corrPart : references only $correlationRangeNames
		 *
		 * If a conjunct mixes inner & corr refs and contains OR, we treat it as
		 * "unsafe to split" and keep the whole predicate as innerPart.
		 *
		 * @param AstInterface|null $predicate Original JOIN predicate.
		 * @param string[] $liveRangeNames Names considered "inner".
		 * @param string[] $correlationRangeNames Names considered "correlation".
		 * @return array{innerPart: AstInterface|null, corrPart: AstInterface|null} Split predicate parts
		 */
		private static function splitJoinPredicateByRangeReferences(
			?AstInterface $predicate,
			array         $liveRangeNames,
			array         $correlationRangeNames
		): array {
			// When no predicate passed, return empty values
			if ($predicate === null) {
				return ['innerPart' => null, 'corrPart' => null];
			}
			
			// If it's an AND tree, we can classify each leaf conjunct independently.
			if (AstUtilities::isBinaryAndOperator($predicate)) {
				$queue = [$predicate];
				$andLeaves = [];
				
				// Flatten the AND tree to a list of leaves.
				while ($queue) {
					$n = array_pop($queue);
					
					if (AstUtilities::isBinaryAndOperator($n)) {
						$queue[] = $n->getLeft();
						$queue[] = $n->getRight();
					} else {
						$andLeaves[] = $n;
					}
				}
				
				$innerParts = [];
				$corrParts = [];
				
				foreach ($andLeaves as $leaf) {
					$bucket = self::classifyPredicateByRangeReferences($leaf, $liveRangeNames, $correlationRangeNames);
					
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
					'innerPart' => AstUtilities::combinePredicatesWithAnd($innerParts),
					'corrPart'  => AstUtilities::combinePredicatesWithAnd($corrParts),
				];
			}
			
			// Non-AND predicates are classified as a whole.
			return match (self::classifyPredicateByRangeReferences($predicate, $liveRangeNames, $correlationRangeNames)) {
				'CORR' => ['innerPart' => null, 'corrPart' => $predicate],
				default => ['innerPart' => $predicate, 'corrPart' => null],
			};
		}
		
		/**
		 * Classify an expression by the sets of ranges it references:
		 *   - 'INNER'            : only liveRangeNames appear
		 *   - 'CORR'             : only correlationRangeNames appear
		 *   - 'MIXED_OR_COMPLEX' : both appear AND there's an OR somewhere (unsafe split)
		 *
		 * Rationale: a conjunct that mixes both sides but has no OR can be pushed
		 * into either bucket by normalization, but we keep it conservative: only
		 * split when it's clearly safe and clean.
		 *
		 * @param AstInterface $expr Expression to classify
		 * @param string[] $liveRangeNames Live range names
		 * @param string[] $correlationRangeNames Correlation range names
		 * @return 'INNER'|'CORR'|'MIXED_OR_COMPLEX' Classification result
		 */
		private static function classifyPredicateByRangeReferences(AstInterface $expr, array $liveRangeNames, array $correlationRangeNames): string {
			$ids = AstUtilities::collectIdentifiersFromAst($expr);
			$hasInner = false;
			$hasCorr = false;
			
			foreach ($ids as $id) {
				$n = $id->getRange()->getName();
				
				if (in_array($n, $liveRangeNames, true)) {
					$hasInner = true;
				}
				
				if (in_array($n, $correlationRangeNames, true)) {
					$hasCorr = true;
				}
			}
			
			// If both sides appear AND there is an OR in the subtree, splitting
			// risks changing semantics (e.g., distributing over OR). Avoid it.
			if (self::containsOrOperator($expr) && $hasInner && $hasCorr) {
				return 'MIXED_OR_COMPLEX';
			} elseif ($hasCorr && !$hasInner) {
				return 'CORR';
			} else {
				return 'INNER';
			}
		}
		
		/**
		 * True if the subtree contains an OR node anywhere.
		 * @param AstInterface $node Node to check
		 * @return bool True if OR operator found
		 */
		private static function containsOrOperator(AstInterface $node): bool {
			if (AstUtilities::isBinaryOrOperator($node)) {
				return true;
			}
			
			foreach (AstUtilities::getChildrenFromBinaryOperator($node) as $child) {
				if (self::containsOrOperator($child)) {
					return true;
				}
			}
			
			return false;
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
	}