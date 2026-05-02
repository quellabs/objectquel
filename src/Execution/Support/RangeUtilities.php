<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectRanges;
	
	class RangeUtilities {
		
		// -------------------------------------------------
		// Range collection
		// -------------------------------------------------
	
		/**
		 * Collects all range references from a single AST node using the visitor pattern
		 * @param AstInterface $node Node to inspect
		 * @return AstRange[] Ranges referenced by the node
		 */
		public static function collectRangesFromNode(AstInterface $node): array {
			$visitor = new CollectRanges();
			$node->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Collects range references from an array of mixed node types
		 * Handles both direct AstInterface nodes and wrapped expression nodes
		 * @param array<int,AstInterface> $nodes Value/Expression nodes
		 * @return AstRange[] Ranges referenced by all nodes
		 */
		public static function collectRangesFromNodes(array $nodes): array {
			$visitor = new CollectRanges();
			
			foreach ($nodes as $node) {
				$node->accept($visitor);
			}
			
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Specifically extracts ranges from SELECT statement values
		 * More targeted than general collection methods
		 * @param AstRetrieve $root Root RETRIEVE AST node
		 * @return AstRange[] Ranges referenced anywhere in SELECT values
		 */
		public static function collectRangesUsedInSelect(AstRetrieve $root): array {
			return self::collectRangesFromNodes($root->getValues());
		}
		
		/**
		 * Specifically extracts ranges from ORDER_BY statement values
		 * More targeted than general collection methods
		 * @param AstRetrieve $root Root RETRIEVE AST node
		 * @return AstRange[] Ranges referenced anywhere in SELECT values
		 */
		public static function collectRangesUsedInSort(AstRetrieve $root): array {
			return self::collectRangesFromNodes(array_map(function($e) { return $e["ast"]; }, $root->getSort()));
		}
		
		// -------------------------------------------------
		// Range relationships
		// -------------------------------------------------
		
		/**
		 * Determines if two sets of AST ranges overlap directly or are transitively related through joins
		 * @param AstRange[] $setA First set of ranges to compare
		 * @param AstRange[] $setB Second set of ranges to compare
		 * @return bool True if sets overlap or are joined transitively
		 */
		public static function rangesOverlapOrAreRelated(array $setA, array $setB): bool {
			// First check for direct overlap - same range object in both sets
			foreach ($setA as $a) {
				foreach ($setB as $b) {
					if ($a === $b) {
						return true;
					}
				}
			}
			
			// If no direct overlap, check for transitive relationship via joins
			return self::rangesAreRelatedViaJoins($setA, $setB);
		}
		
		/**
		 * Checks if any range from setA is joined to any range from setB
		 * @param AstRange[] $setA First set of ranges
		 * @param AstRange[] $setB Second set of ranges
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
		 * Determines if two ranges are connected by a join predicate
		 * Checks bidirectionally - either range can reference the other in its join
		 * @param AstRange $a First range
		 * @param AstRange $b Second range
		 * @return bool True if ranges reference each other in a join predicate
		 */
		public static function rangesAreJoined(AstRange $a, AstRange $b): bool {
			// Check if range b's join predicate references range a
			$bJoin = $b->getJoinProperty();
			
			if ($bJoin && self::joinPredicateReferencesRange($bJoin, $a)) {
				return true;
			}
			
			// Check if range a's join predicate references range b
			$aJoin = $a->getJoinProperty();
			
			if ($aJoin && self::joinPredicateReferencesRange($aJoin, $b)) {
				return true;
			}
			
			// No references
			return false;
		}
		
		/**
		 * Searches a join predicate AST for any identifier that belongs to the target range
		 * @param AstInterface $joinPredicate Join expression AST to search
		 * @param AstRange $target Range to look for references to
		 * @return bool True if any identifier in the predicate belongs to $target
		 */
		public static function joinPredicateReferencesRange(AstInterface $joinPredicate, AstRange $target): bool {
			// Extract all identifiers from the join predicate expression
			$ids = AstUtilities::collectIdentifiersFromAst($joinPredicate);
			
			// Check if any identifier belongs to the target range
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
		 * This function performs a depth-first search to collect all ranges that are
		 * transitively required by the given starting range through join dependencies.
		 * It maintains cycle detection to prevent infinite recursion.
		 *
		 * NOTE:
		 * This relies on the invariant that join predicates only reference ranges
		 * declared within the same RETRIEVE statement.
		 *
		 * If correlated joins across query scopes are introduced, this DFS will
		 * incorrectly pull outer-scope ranges into the inner required set.
		 * At that point, a "universe" constraint (allowed range set) must be
		 * reintroduced to restrict traversal.
		 *
		 * @param AstRange $range Starting range to expand from
		 * @param \WeakMap<AstRange, true> $required Collected set of required ranges
		 * @param \WeakMap<AstRange, true> $processed DFS cycle detection guard
		 * @return void
		 */
		public static function expandWithJoinDependencies(AstRange $range, \WeakMap $required, \WeakMap $processed): void {
			// Cycle detection: if we've already processed this range in the current DFS path,
			// we've found a cycle and should terminate this branch to avoid infinite recursion
			if (isset($processed[$range])) {
				return;
			}
			
			// Mark this range as processed in the current DFS path
			$processed[$range] = true;
			
			// Add the current range to the required set if not already present
			// WeakMap keyed by object identity guarantees no duplicates
			if (!isset($required[$range])) {
				$required[$range] = $range;
			}
			
			// Get the join predicate (condition) for this range
			// If null, this range has no dependencies, so we're done with this branch
			$joinPredicate = $range->getJoinProperty();
			
			if ($joinPredicate === null) {
				return;
			}
			
			// Extract all ranges referenced within the join predicate
			// This likely parses the predicate AST to find range references
			$referenced = RangeUtilities::collectRangesFromNode($joinPredicate);
			
			// Recursively process each referenced range
			foreach ($referenced as $ref) {
				// Self-reference check: avoid processing the same range as a dependency of itself
				// This prevents trivial cycles and unnecessary work
				if ($ref !== $range) {
					// Recursive call to expand dependencies of the referenced range
					self::expandWithJoinDependencies($ref, $required, $processed);
				}
			}
		}
		
		/**
		 * Expands the set of ranges to include all join dependencies transitively.
		 * @param AstRange[] $directlyUsed
		 * @return \WeakMap<AstRange, AstRange>
		 */
		public static function expandWithAllJoinDependencies(array $directlyUsed): \WeakMap {
			$required = new \WeakMap();
			$processed = new \WeakMap();
			
			foreach ($directlyUsed as $range) {
				$required[$range] = $range;
				self::expandWithJoinDependencies($range, $required, $processed);
			}
			
			return $required;
		}
		
		/**
		 * Compute minimal range set (seed ranges + join dependency closure).
		 * @param AstRange[] $seedRanges Ranges referenced by the aggregate
		 * @return \WeakMap<AstRange, AstRange> Minimal set of ranges needed for correctness
		 */
		public static function computeMinimalRangeSet(array $seedRanges): \WeakMap {
			$required = new \WeakMap();
			$processed = new \WeakMap();
			
			// For each seed range (directly referenced by the aggregate), expand to include
			// all transitively dependent ranges through join conditions
			foreach ($seedRanges as $seed) {
				// Recursively add this seed and all ranges it depends on via joins
				// This ensures we maintain referential integrity in the subquery
				self::expandWithJoinDependencies($seed, $required, $processed);
			}
			
			// Return the minimal set of ranges that preserves query semantics
			return $required;
		}
		
		/**
		 * Removes ranges that are not in the required set.
		 * @param AstRetrieve $root
		 * @param AstRange[] $allRanges
		 * @param \WeakMap<AstRange, AstRange> $requiredRanges
		 * @return void
		 */
		public static function removeRangesNotInSet(AstRetrieve $root, array $allRanges, \WeakMap $requiredRanges): void {
			foreach ($allRanges as $range) {
				if (!isset($requiredRanges[$range])) {
					$root->removeRange($range);
				}
			}
		}
	}