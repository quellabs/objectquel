<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	
	class RangeRemover {
		
		/**
		 * Collects ranges directly referenced in SELECT, WHERE, and JOIN predicates.
		 */
		public static function collectDirectlyUsedRanges(AstRetrieve $root, array $allRanges): array {
			$used = [];
			
			// Ranges used in SELECT clause
			$used = array_merge($used, RangeUtilities::collectRangesUsedInSelect($root));
			
			// Ranges used in WHERE clause
			$outerWhere = $root->getConditions();
			if ($outerWhere) {
				$used = array_merge($used, RangeUtilities::collectRangesFromNode($outerWhere));
			}
			
			// Ranges used in JOIN predicates
			foreach ($allRanges as $range) {
				$joinPredicate = $range->getJoinProperty();
				if ($joinPredicate) {
					$used = array_merge($used, RangeUtilities::collectRangesFromNode($joinPredicate));
				}
			}
			
			return array_unique($used, SORT_REGULAR);
		}
		
		
		/**
		 * Optimizes queries with a single range by folding self-referencing joins into WHERE.
		 */
		public static function optimizeSingleRangeQuery(AstRetrieve $root): void {
			$remainingRanges = $root->getRanges();
			
			if (count($remainingRanges) !== 1) {
				return;
			}
			
			$singleRange = $remainingRanges[0];
			$joinPredicate = $singleRange->getJoinProperty();
			
			if (!$joinPredicate) {
				return;
			}
			
			// Check if join predicate only references the single range
			if (self::joinPredicateReferencesOnlySelf($joinPredicate, $singleRange)) {
				self::foldJoinPredicateIntoWhere($root, $joinPredicate);
				$singleRange->setJoinProperty(null);
			}
		}
		
		/**
		 * Checks if a join predicate only references the given range.
		 * @param $joinPredicate
		 * @param $targetRange
		 * @return bool
		 */
		public static function joinPredicateReferencesOnlySelf($joinPredicate, $targetRange): bool {
			$referencedRanges = RangeUtilities::collectRangesFromNode($joinPredicate);
			
			foreach ($referencedRanges as $range) {
				if ($range !== $targetRange) {
					return false;
				}
			}
			
			return true;
		}
		
		/**
		 * Folds a join predicate into the WHERE clause using AND.
		 * @param AstRetrieve $root
		 * @param $joinPredicate
		 * @return void
		 */
		public static function foldJoinPredicateIntoWhere(AstRetrieve $root, $joinPredicate): void {
			$existingWhere = $root->getConditions();
			
			if ($existingWhere) {
				$newWhere = AstFactory::createBinaryAndOperator($existingWhere, $joinPredicate);
			} else {
				$newWhere = $joinPredicate;
			}
			
			$root->setConditions($newWhere);
		}
		
		/**
		 * Removes unused FROM ranges in aggregate-only SELECT queries.
		 *
		 * Algorithm:
		 * 1. Find ranges referenced in SELECT, WHERE, or JOIN predicates
		 * 2. Expand the set with join dependencies (transitively)
		 * 3. Remove any ranges not in the final set
		 * 4. Optimize single-range case: fold self-referencing joins into WHERE
		 *
		 * @param AstRetrieve $root Query to mutate
		 * @return void
		 */
		public static function removeUnusedRangesInAggregateOnlyQueries(AstRetrieve $root): void {
			$allRanges = $root->getRanges();
			
			// Collect all directly referenced ranges
			$directlyUsed = self::collectDirectlyUsedRanges($root, $allRanges);
			
			// Expand to include join dependencies
			$requiredRanges = RangeUtilities::expandWithAllJoinDependencies($directlyUsed, $allRanges);
			
			// Remove unused ranges
			RangeUtilities::removeRangesNotInSet($root, $allRanges, $requiredRanges);
			
			// Optimize single-range case
			self::optimizeSingleRangeQuery($root);
		}
	}