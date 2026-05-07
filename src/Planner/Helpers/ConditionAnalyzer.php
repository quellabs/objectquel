<?php
	
	namespace Quellabs\ObjectQuel\Planner\Helpers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\Planner\Walker\AnyRangeReferenceChecker;
	use Quellabs\ObjectQuel\Planner\Walker\RangeNameCollector;
	use Quellabs\ObjectQuel\Planner\Walker\RangeReferenceChecker;
	
	/**
	 * Provides pure AST interrogation methods that answer questions about which
	 * ranges a condition node references. All methods are free of side effects
	 * except for the result cache, which exists purely for performance.
	 *
	 * This class is a dependency of ConditionFilter and StageFactory. It carries
	 * the cache so that a single cache lifetime spans an entire plan-build pass —
	 * clearCache() should be called at the start of each build() call.
	 *
	 * AST traversal is handled by three dedicated walker classes:
	 *   - RangeReferenceChecker    — does this subtree reference a specific range?
	 *   - AnyRangeReferenceChecker — does this subtree reference any range at all?
	 *   - RangeNameCollector       — collect all temp range names in this subtree
	 *
	 * Recursion safety:
	 *   All walkers assume the AST is acyclic, which is guaranteed by the parser.
	 *   No depth guard is therefore needed.
	 */
	class ConditionAnalyzer {
		
		/**
		 * Cache of results for expensive range-reference checks.
		 * Keyed by concatenated spl_object_hash values of the condition and range pair
		 * to avoid redundant AST traversals for the same inputs.
		 * Lifetime is strictly per plan-build pass: clearCache() is called at the
		 * start of every build() call, so destroyed-and-reused objects
		 * cannot produce stale cache hits.
		 * @var array<string, bool>
		 */
		private array $cache = [];
		
		/**
		 * Clears the internal result cache.
		 * Must be called at the start of each build() call to prevent stale results
		 * and unbounded memory growth across decomposition passes.
		 * @return void
		 */
		public function clearCache(): void {
			$this->cache = [];
		}
		
		// =========================================================================
		// Temp-range dependency scanning
		// =========================================================================
		
		/**
		 * Finds all temp range names referenced in a query's WHERE conditions and
		 * retrieve expressions. Used by ExecutionPlanBuilder to build the
		 * inter-temp-range dependency graph so that stages are executed in the
		 * correct order.
		 * @param AstRetrieve $query The query whose dependencies should be scanned
		 * @param string[] $tempRangeNames The closed set of temp range names to look for
		 * @return string[] Deduplicated list of temp range names this query depends on
		 */
		public function findTempRangeDependencies(AstRetrieve $query, array $tempRangeNames): array {
			$collector = new RangeNameCollector($tempRangeNames);
			$dependencies = [];
			
			// Check WHERE conditions
			if ($query->getConditions() !== null) {
				$dependencies = array_merge(
					$dependencies,
					$collector->walk($query->getConditions())
				);
			}
			
			// Check retrieve expressions
			foreach ($query->getValues() as $value) {
				$dependencies = array_merge($dependencies, $collector->walk($value));
			}
			
			return array_unique($dependencies);
		}
		
		// =========================================================================
		// Range-reference analysis
		// =========================================================================
		
		/**
		 * Returns true if any node in the given AST subtree references any data range
		 * (database table or other data source). Used to distinguish conditions that
		 * require database or in-memory execution from those that can be evaluated
		 * entirely in PHP without touching any range data.
		 * @param AstInterface $condition The root of the AST subtree to inspect
		 * @return bool True if the subtree contains at least one range reference
		 */
		public function containsAnyRangeReference(AstInterface $condition): bool {
			return (new AnyRangeReferenceChecker())->walk($condition);
		}
		
		/**
		 * Returns true if any node in the given AST subtree references the specified range.
		 * Delegates to RangeReferenceChecker, which short-circuits as soon as a match
		 * is found.
		 *
		 * For repeated calls with the same condition/range pair, prefer
		 * doesConditionInvolveRangeCached() to avoid redundant traversals.
		 * @param AstInterface $condition The root of the AST subtree to inspect
		 * @param AstRange $range The range to search for
		 * @return bool True if the subtree contains at least one reference to $range
		 */
		public function hasReferenceToRange(AstInterface $condition, AstRange $range): bool {
			return (new RangeReferenceChecker($range))->walk($condition);
		}
		
		/**
		 * Returns true if the condition references any of the given ranges.
		 * Iterates the range list and delegates each check to the per-pair cache,
		 * short-circuiting as soon as the first match is confirmed.
		 * @param AstInterface $condition The root of the AST subtree to inspect
		 * @param AstRange[] $ranges The ranges to test against
		 * @return bool True if the condition references at least one range in $ranges
		 */
		public function hasReferenceToAnyRange(AstInterface $condition, array $ranges): bool {
			foreach ($ranges as $range) {
				if ($this->doesConditionInvolveRangeCached($condition, $range)) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Cached wrapper around hasReferenceToRange(). Returns the cached result for
		 * a given condition/range pair if one exists, otherwise runs the traversal,
		 * stores the result, and returns it.
		 *
		 * The cache key is formed from the spl_object_hash of both arguments, so it
		 * is valid only for the lifetime of those specific object instances. Call
		 * clearCache() at the start of each build() pass to prevent stale hits from
		 * reused memory addresses.
		 * @param AstInterface $condition The root of the AST subtree to inspect
		 * @param AstRange $range The range to search for
		 * @return bool True if the subtree contains at least one reference to $range
		 */
		public function doesConditionInvolveRangeCached(AstInterface $condition, AstRange $range): bool {
			$cacheKey = spl_object_hash($condition) . '_' . spl_object_hash($range);
			
			if (!isset($this->cache[$cacheKey])) {
				$this->cache[$cacheKey] = $this->hasReferenceToRange($condition, $range);
			}
			
			return $this->cache[$cacheKey];
		}
	}