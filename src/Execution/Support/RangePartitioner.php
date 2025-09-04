<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
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
		public static function buildJoinReferenceMap(array $ranges): array {
			$joinReferences = [];
			
			foreach ($ranges as $k) {
				$kName = $k->getName();
				$join = $k->getJoinProperty();
				
				if ($join === null) {
					continue;
				}
				
				// Identify which ranges are referenced by this join predicate.
				foreach (AstUtilities::collectIdentifiersFromAst($join) as $id) {
					$rName = $id->getRange()->getName();
					
					// Self-reference is not correlation; it stays "inner".
					if ($rName === $kName) {
						continue;
					}
					
					$joinReferences[$kName][$rName] = true;
				}
			}
			
			return $joinReferences;
		}
		
		/**
		 * Computes live ranges by filtering ranges based on their usage in expressions or conditions.
		 * This function performs liveness analysis to determine which variable ranges are actively
		 * used and should be considered "live" for further processing (e.g., optimization passes).
		 * @param array $ranges Array of range objects, each having a getName() method
		 * @param array $usedInExpr Associative array mapping variable names to boolean usage in expressions
		 * @param array $usedInCond Associative array mapping variable names to boolean usage in conditions
		 * @return array            Associative array of live ranges keyed by variable name
		 */
		public static function computeLiveRanges(array $ranges, array $usedInExpr, array $usedInCond): array {
			$liveRanges = [];
			
			foreach ($ranges as $range) {
				// Extract the variable name from the range object
				$name = $range->getName();
				
				// Check if this variable is used in any expression context
				// Uses null coalescing operator to default to false if key doesn't exist
				$exprUsed = $usedInExpr[$name] ?? false;
				
				// Check if this variable is used in any conditional context
				// Uses null coalescing operator to default to false if key doesn't exist
				$condUsed = $usedInCond[$name] ?? false;
				
				// A range is considered "live" if it's used in either expressions OR conditions
				// This implements the union of both usage types for liveness determination
				if ($exprUsed || $condUsed) {
					// Add the live range to our result set, keyed by variable name for quick lookup
					$liveRanges[$name] = $range;
				}
				// Note: Variables not used in either context are implicitly dead and excluded
			}
			
			return $liveRanges;
		}
		
		/**
		 * Computes correlation-only ranges by identifying ranges used solely for join relationships.
		 * This function performs correlation analysis to determine which variable ranges exist only
		 * to establish join predicates and are not actively used in expressions or conditions.
		 * @param array $ranges Array of range objects, each having a getName() method
		 * @param array $joinReferences Array mapping join predicates to their referenced ranges
		 * @param array $usedInExpr Associative array mapping variable names to boolean usage in expressions
		 * @param array $usedInCond Associative array mapping variable names to boolean usage in conditions
		 * @return array            Associative array of correlation-only ranges keyed by variable name
		 */
		public static function computeCorrelationOnlyRanges(array $ranges, array $joinReferences, array $usedInExpr, array $usedInCond): array {
			$correlationOnly = [];
			
			foreach ($ranges as $range) {
				// Get the range name for lookups in usage arrays
				$name = $range->getName();
				
				// Check if range is used in expressions or conditions (makes it "live")
				$isExprUsed = $usedInExpr[$name] ?? false;
				$isCondUsed = $usedInCond[$name] ?? false;
				
				// Skip ranges that are actively used in expressions or conditions
				if ($isExprUsed || $isCondUsed) {
					continue;
				}
				
				// For unused ranges, check if they appear in join predicates
				// These are correlation-only ranges that exist solely for joins
				if (self::isRangeUsedInAnyJoinPredicate($name, $ranges, $joinReferences)) {
					$correlationOnly[$name] = $range;
				}
			}
			
			return $correlationOnly;
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
		public static function selectFallbackLiveRanges(array $ranges, AstAny $node): array {
			if (empty($ranges)) {
				return [];
			}
			
			$rangesByName = self::createRangeNameMap($ranges);
			$liveRanges = self::extractLiveRangesFromAnyExpression($node, $rangesByName);
			
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
		public static function filterToLiveRangesOnly(array $ranges, array $liveRanges): array {
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
		private static function isRangeUsedInAnyJoinPredicate(string $targetRangeName, array $allRanges, array $joinReferences): bool {
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
		private static function createRangeNameMap(array $ranges): array {
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
		private static function extractLiveRangesFromAnyExpression(AstAny $node, array $rangesByName): array {
			$liveRanges = [];
			$identifiers = AstUtilities::collectIdentifiersFromAst($node->getIdentifier());
			
			foreach ($identifiers as $identifier) {
				$rangeName = $identifier->getRange()->getName();
				
				if (isset($rangesByName[$rangeName])) {
					$liveRanges[$rangeName] = $rangesByName[$rangeName];
				}
			}
			
			return $liveRanges;
		}
	}