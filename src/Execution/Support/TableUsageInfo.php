<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
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
		 * Safe when:
		 * - Table has non-nullable field access (guarantees row exists)
		 * - Table not used in conditions, OR
		 * - No explicit NULL checks on this table
		 */
		public function canSafelyCollapseToInner(): bool {
			if ($this->hasNullChecks) {
				return false;  // Explicit NULL checks mean LEFT JOIN semantics matter
			}
			
			if ($this->hasNonNullableFieldAccess) {
				return true;   // Non-nullable access guarantees row existence
			}
			
			if (!$this->usedInConditions) {
				return true;   // Not used in WHERE, safe to optimize
			}
			
			return false;      // Conservative: preserve LEFT JOIN semantics
		}
	}