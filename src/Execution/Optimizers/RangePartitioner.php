<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	
	/**
	 * RangePartitioner handles the partitioning and filtering of ranges based on usage analysis.
	 *
	 * This class is responsible for:
	 * - Building join reference maps to understand cross-range dependencies
	 * - Separating ranges into "live" and "correlation-only" categories
	 * - Providing fallback live ranges when analysis yields no results
	 * - Filtering ranges to keep only live ones
	 */
	class RangePartitioner {
		
		/** @var AstUtilities Utility methods for AST operations. */
		private AstUtilities $astUtilities;
		
		/**
		 * RangePartitioner constructor
		 * @param AstUtilities $astUtilities AST utility methods
		 */
		public function __construct(AstUtilities $astUtilities) {
			$this->astUtilities = $astUtilities;
		}
		
		/**
		 * Build a map of cross-join references:
		 *   joinReferences[K][R] = true  ⇔  JOIN(K).ON mentions range R (and R != K).
		 *
		 * Why we need this: a range that only appears inside other JOINs is
		 * "correlation-only" and shouldn't stay as a joined input in the subquery.
		 *
		 * @param AstRange[] $ranges All cloned ranges.
		 * @return array<string,array<string,bool>> Map of JOIN cross-refs.
		 */
		public function buildJoinReferenceMap(array $ranges): array {
			$joinReferences = [];
			
			foreach ($ranges as $k) {
				$kName = $k->getName();
				$join = $k->getJoinProperty();
				
				if ($join === null) {
					continue;
				}
				
				// Identify which ranges are referenced by this join predicate.
				foreach ($this->astUtilities->collectIdentifiersFromAst($join) as $id) {
					$rName = $id->getRange()->getName();
					
					if ($rName === $kName) {
						// Self-reference is not correlation; it stays "inner".
						continue;
					}
					
					$joinReferences[$kName][$rName] = true;
				}
			}
			
			return $joinReferences;
		}
		
		/**
		 * Decide which ranges are "live" vs "correlation-only".
		 *
		 * Live:     directly referenced in ANY(expr) or ANY(WHERE ...).
		 * CorrOnly: not live, but referenced inside someone else's JOIN predicate.
		 *
		 * @param AstRange[] $ranges All cloned ranges (in original order).
		 * @param array<string,bool> $usedInExpr Analyzer map.
		 * @param array<string,bool> $usedInCond Analyzer map.
		 * @param array<string,array<string,bool>> $joinReferences Map: [K][R] = true if JOIN(K) mentions R (K != R).
		 * @return array{0: array<string,AstRange>, 1: array<string,AstRange>} [liveRanges, correlationOnlyRanges]
		 */
		public function separateLiveAndCorrelationRanges(
			array $ranges,
			array $usedInExpr,
			array $usedInCond,
			array $joinReferences
		): array {
			$liveRanges = [];
			$correlationOnlyRanges = [];
			
			foreach ($ranges as $range) {
				// Fetch range name
				$rangeName = $range->getName();
				
				// Criterion 1: Range is directly used in expressions or conditions
				$isDirectlyUsed = ($usedInExpr[$rangeName] ?? false) || ($usedInCond[$rangeName] ?? false);
				
				if ($isDirectlyUsed) {
					$liveRanges[$rangeName] = $range;
					continue;
				}
				
				// Criterion 2: Range is only referenced through other ranges' JOIN predicates
				$isReferencedInJoins = $this->isRangeUsedInAnyJoinPredicate($rangeName, $ranges, $joinReferences);
				
				if ($isReferencedInJoins) {
					$correlationOnlyRanges[$rangeName] = $range;
				}
				
				// Note: Ranges that are neither live nor correlation-only are implicitly excluded
			}
			
			return [$liveRanges, $correlationOnlyRanges];
		}
		
		/**
		 * Provide a fallback set of live ranges when analyzer finds none.
		 * Preference order:
		 *   1) Ranges referenced by ANY(expr)
		 *   2) The first range in the list (stable fallback)
		 *
		 * @param AstRange[] $ranges All cloned ranges.
		 * @param AstAny $node ANY node whose expr we inspect.
		 * @return array<string,AstRange> Live map keyed by range name.
		 */
		public function selectFallbackLiveRanges(array $ranges, AstAny $node): array {
			if (empty($ranges)) {
				return [];
			}
			
			$rangesByName = $this->createRangeNameMap($ranges);
			$liveRanges = $this->extractLiveRangesFromAnyExpression($node, $rangesByName);
			
			// If no ranges found in expression, use first range as stable fallback
			if (empty($liveRanges)) {
				$firstRange = $ranges[0];
				$liveRanges[$firstRange->getName()] = $firstRange;
			}
			
			return $liveRanges;
		}
		
		/**
		 * Keep only the ranges that are live; preserve their original order.
		 * @param AstRange[] $ranges All cloned ranges (post-promotion).
		 * @param array<string,AstRange> $liveRanges Live ranges keyed by name.
		 * @return AstRange[] The subset of $ranges that are live.
		 */
		public function filterToLiveRangesOnly(array $ranges, array $liveRanges): array {
			// Early exit if no live ranges
			if (empty($liveRanges)) {
				return [];
			}
			
			// Use array_filter with isset for O(1) lookup per element
			return array_filter($ranges, fn($r) => isset($liveRanges[$r->getName()]));
		}
		
		/**
		 * Check if a range is referenced in any other range's JOIN predicates.
		 * @param string $targetRangeName The range to check for references
		 * @param AstRange[] $allRanges All available ranges
		 * @param array<string,array<string,bool>> $joinReferences JOIN reference map
		 * @return bool True if the target range is referenced in any JOIN predicate
		 */
		private function isRangeUsedInAnyJoinPredicate(
			string $targetRangeName,
			array  $allRanges,
			array  $joinReferences
		): bool {
			foreach ($allRanges as $otherRange) {
				$otherRangeName = $otherRange->getName();
				
				// Skip self-references
				if ($otherRangeName === $targetRangeName) {
					continue;
				}
				
				// Check if this other range's JOIN mentions our target range
				if (!empty($joinReferences[$otherRangeName][$targetRangeName])) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Create a lookup map of ranges indexed by their names.
		 * @param AstRange[] $ranges Array of range objects
		 * @return array<string,AstRange> Map from range name to range object
		 */
		private function createRangeNameMap(array $ranges): array {
			$rangesByName = [];
			
			foreach ($ranges as $range) {
				$rangesByName[$range->getName()] = $range;
			}
			
			return $rangesByName;
		}
		
		/**
		 * Extract live ranges from identifiers in the ANY expression.
		 * @param AstAny $node ANY node to analyze
		 * @param array<string,AstRange> $rangesByName Available ranges indexed by name
		 * @return array<string,AstRange> Live ranges found in the expression
		 */
		private function extractLiveRangesFromAnyExpression(AstAny $node, array $rangesByName): array {
			$liveRanges = [];
			$identifiers = $this->astUtilities->collectIdentifiersFromAst($node->getIdentifier());
			
			foreach ($identifiers as $identifier) {
				$rangeName = $identifier->getRange()->getName();
				
				if (isset($rangesByName[$rangeName])) {
					$liveRanges[$rangeName] = $rangesByName[$rangeName];
				}
			}
			
			return $liveRanges;
		}
	}