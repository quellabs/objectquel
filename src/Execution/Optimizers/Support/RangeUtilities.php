<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers\Support;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectRanges;
	
	class RangeUtilities {
		
		// -------------------------------------------------
		// Range collection
		// -------------------------------------------------
		
		/**
		 * @param AstInterface $node Node to inspect
		 * @return AstRange[] Ranges referenced by the node
		 */
		public static function collectRangesFromNode(AstInterface $node): array {
			$visitor = new CollectRanges();
			$node->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * @param array<int,mixed> $nodes Value/Expression nodes
		 * @return AstRange[] Ranges referenced by all nodes
		 */
		public static function collectRangesFromNodes(array $nodes): array {
			$visitor = new CollectRanges();
			
			foreach ($nodes as $n) {
				if ($n instanceof AstInterface) {
					$n->accept($visitor);
				} else {
					$expr = $n->getExpression();
					
					if ($expr instanceof AstInterface) {
						$expr->accept($visitor);
					}
				}
			}
			
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * @param AstRetrieve $root
		 * @return AstRange[] Ranges referenced anywhere in SELECT
		 */
		public static function collectRangesUsedInSelect(AstRetrieve $root): array {
			$collector = new CollectRanges();
			
			foreach ($root->getValues() as $value) {
				$value->accept($collector);
			}
			
			return $collector->getCollectedNodes();
		}
		
		// -------------------------------------------------
		// Range relationships
		// -------------------------------------------------
		
		/**
		 * @param AstRange[] $setA
		 * @param AstRange[] $setB
		 * @return bool True if sets overlap or are joined transitively
		 */
		public static function rangesOverlapOrAreRelated(array $setA, array $setB): bool {
			foreach ($setA as $a) {
				foreach ($setB as $b) {
					if ($a === $b) {
						return true; // direct overlap
					}
				}
			}
			return self::rangesAreRelatedViaJoins($setA, $setB);
		}
		
		/**
		 * @param AstRange[] $setA
		 * @param AstRange[] $setB
		 * @return bool True if any pair (a,b) is joined
		 */
		public static function rangesAreRelatedViaJoins(array $setA, array $setB): bool {
			foreach ($setA as $a) {
				foreach ($setB as $b) {
					if (self::rangesAreJoined($a, $b)) {
						return true;
					}
				}
			}
			return false;
		}
		
		/**
		 * @param AstRange $a
		 * @param AstRange $b
		 * @return bool True if ranges reference each other in a join predicate
		 */
		public static function rangesAreJoined(AstRange $a, AstRange $b): bool {
			$bJoin = $b->getJoinProperty();
			
			if ($bJoin && self::joinPredicateReferencesRange($bJoin, $a)) {
				return true;
			}
			
			$aJoin = $a->getJoinProperty();
			
			if ($aJoin && self::joinPredicateReferencesRange($aJoin, $b)) {
				return true;
			}
			
			return false;
		}
		
		/**
		 * @param AstInterface $joinPredicate Join expression
		 * @param AstRange $target Range to look for
		 * @return bool True if any identifier in the predicate belongs to $target
		 */
		public static function joinPredicateReferencesRange(AstInterface $joinPredicate, AstRange $target): bool {
			$ids = AstUtilities::collectIdentifiersFromAst($joinPredicate);
			
			foreach ($ids as $id) {
				if ($id->getRange() === $target) {
					return true;
				}
			}
			
			return false;
		}
		
		// -------------------------------------------------
		// Range dependency analysis
		// -------------------------------------------------
		
		/**
		 * Recursively add a range and the ranges referenced by its join predicate.
		 * @param AstRange $range Starting range
		 * @param AstRange[] $universe All known ranges
		 * @param array<int,AstRange> $required Collected set (by reference)
		 * @param array<int,AstRange> $processed DFS guard
		 * @return void
		 */
		public static function expandWithJoinDependencies(AstRange $range, array $universe, array &$required, array &$processed): void {
			if (in_array($range, $processed, true)) {
				return; // avoid cycles
			}
			
			$processed[] = $range;
			if (!in_array($range, $required, true)) {
				$required[] = $range;
			}
			
			$joinPredicate = $range->getJoinProperty();
			if ($joinPredicate === null) {
				return;
			}
			
			$referenced = RangeUtilities::collectRangesFromNode($joinPredicate);
			
			foreach ($referenced as $ref) {
				if ($ref !== $range) {
					self::expandWithJoinDependencies($ref, $universe, $required, $processed);
				}
			}
		}
		
		/**
		 * Expands the set of ranges to include all join dependencies transitively.
		 * @param array $directlyUsed
		 * @param array $allRanges
		 * @return array
		 */
		public static function expandWithAllJoinDependencies(array $directlyUsed, array $allRanges): array {
			$required = [];
			$processed = [];
			
			foreach ($directlyUsed as $range) {
				$required[spl_object_hash($range)] = $range;
				self::expandWithJoinDependencies($range, $allRanges, $required, $processed);
			}
			
			return $required;
		}
		
		/**
		 * Compute minimal range set (seed ranges + join dependency closure).
		 * @param AstRange[] $allRanges All ranges in the outer query
		 * @param AstRange[] $seedRanges Ranges referenced by the aggregate
		 * @return AstRange[] Minimal set of ranges needed for correctness
		 */
		public static function computeMinimalRangeSet(array $allRanges, array $seedRanges): array {
			$required = [];
			$processed = [];
			
			foreach ($seedRanges as $seed) {
				self::expandWithJoinDependencies($seed, $allRanges, $required, $processed);
			}
			
			return $required;
		}
		
		/**
		 * Removes ranges that are not in the required set.
		 * @param AstRetrieve $root
		 * @param array $allRanges
		 * @param array $requiredRanges
		 * @return void
		 */
		public static function removeRangesNotInSet(AstRetrieve $root, array $allRanges, array $requiredRanges): void {
			foreach ($allRanges as $range) {
				if (!isset($requiredRanges[spl_object_hash($range)])) {
					$root->removeRange($range);
				}
			}
		}
	}