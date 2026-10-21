<?php
	
	namespace Quellabs\ObjectQuel\Planner\Helpers;
	
	/**
	 * Information about how a specific table is used in the query.
	 */
	class TableUsageInfo {
		private bool $usedInSelectExpressions = false;
		private bool $usedInConditions = false;
		private bool $hasNullChecks = false;
		private bool $hasNonNullableFieldAccess = false;
		
		public function __construct(
			bool $usedInSelectExpressions = false,
			bool $usedInConditions = false,
			bool $hasNullChecks = false,
			bool $hasNonNullableFieldAccess = false
		) {
			$this->hasNonNullableFieldAccess = $hasNonNullableFieldAccess;
			$this->hasNullChecks = $hasNullChecks;
			$this->usedInConditions = $usedInConditions;
			$this->usedInSelectExpressions = $usedInSelectExpressions;
		}
		
		public function isUsedInSelectExpressions(): bool {
			return $this->usedInSelectExpressions;
		}
		
		public function isUsedInConditions(): bool {
			return $this->usedInConditions;
		}
		
		public function hasNullChecks(): bool {
			return $this->hasNullChecks;
		}
		
		public function hasNonNullableFieldAccess(): bool {
			return $this->hasNonNullableFieldAccess;
		}
		
		/**
		 * Determine if a LEFT JOIN can be safely converted to INNER JOIN.
		 *
		 * Safe only when the table has an unconditionally-required non-nullable
		 * field access (RangeUsageAnalyzer only sets this when the reference isn't
		 * reachable through an OR), since that's the only signal that proves every
		 * row must have a match. Not being referenced in the conditions at all
		 * proves nothing: the table is still joined into the subquery's FROM, so an
		 * unmatched row can still change whether that subquery's result is empty
		 * regardless of what the WHERE clause does or doesn't mention — collapsing
		 * on that absence alone can silently drop rows.
		 */
		public function canSafelyCollapseToInner(): bool {
			if ($this->hasNullChecks) {
				return false;  // Explicit NULL checks mean LEFT JOIN semantics matter
			}

			return $this->hasNonNullableFieldAccess;
		}
	}