<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
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
			// Create a new visitor instance to collect ranges
			$visitor = new CollectRanges();
			
			// Traverse the AST node tree using the visitor pattern
			// The visitor will internally collect all AstRange nodes it encounters
			$node->accept($visitor);
			
			// Return the accumulated range nodes
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Collects range references from an array of mixed node types
		 * Handles both direct AstInterface nodes and wrapped expression nodes
		 * @param array<int,mixed> $nodes Value/Expression nodes
		 * @return AstRange[] Ranges referenced by all nodes
		 */
		public static function collectRangesFromNodes(array $nodes): array {
			// Single visitor instance to accumulate ranges from all nodes
			$visitor = new CollectRanges();
			
			foreach ($nodes as $n) {
				// Handle direct AST nodes
				if ($n instanceof AstInterface) {
					$n->accept($visitor);
				} else {
					// Handle wrapped nodes - extract the expression first
					// Note: This assumes $n has a getExpression() method
					// Could throw error if $n is null or doesn't have this method
					$expr = $n->getExpression();
					
					// Only process if the extracted expression is an AST node
					if ($expr instanceof AstInterface) {
						$expr->accept($visitor);
					}
					// Silent failure if expression is not AstInterface - might be intentional
				}
			}
			
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Specifically extracts ranges from SELECT statement values
		 * More targeted than general collection methods
		 * @param AstRetrieve $root Root SELECT/RETRIEVE AST node
		 * @return AstRange[] Ranges referenced anywhere in SELECT values
		 */
		public static function collectRangesUsedInSelect(AstRetrieve $root): array {
			// New collector for this specific operation
			$collector = new CollectRanges();
			
			// Iterate through all values in the SELECT statement
			// getValues() presumably returns the selected columns/expressions
			foreach ($root->getValues() as $value) {
				// Each value should be an AST node that can accept visitors
				// This will collect ranges from column references, function calls, etc.
				$value->accept($collector);
			}
			
			// Return all ranges found in the SELECT values
			return $collector->getCollectedNodes();
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
		 * @param AstRange $range Starting range to expand from
		 * @param AstRange[] $universe All known ranges (currently unused but kept for interface compatibility)
		 * @param array<int,AstRange> $required Collected set of required ranges (modified by reference)
		 * @param array<int,AstRange> $processed DFS cycle detection guard (modified by reference)
		 * @return void
		 */
		public static function expandWithJoinDependencies(AstRange $range, array $universe, array &$required, array &$processed): void {
			// Cycle detection: if we've already processed this range in the current DFS path,
			// we've found a cycle and should terminate this branch to avoid infinite recursion
			if (in_array($range, $processed, true)) {
				return; // avoid cycles
			}
			
			// Mark this range as processed in the current DFS path
			$processed[] = $range;
			
			// Add the current range to the required set if not already present
			// Using strict comparison to ensure object identity matching
			if (!in_array($range, $required, true)) {
				$required[] = $range;
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
			
			// For each seed range (directly referenced by the aggregate), expand to include
			// all transitively dependent ranges through join conditions
			foreach ($seedRanges as $seed) {
				// Recursively add this seed and all ranges it depends on via joins
				// This ensures we maintain referential integrity in the subquery
				self::expandWithJoinDependencies($seed, $allRanges, $required, $processed);
			}
			
			// Return the minimal set of ranges that preserves query semantics
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