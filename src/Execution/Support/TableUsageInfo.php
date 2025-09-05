<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	/**
	 * Information about how a specific table is used in the query.
	 */
	class TableUsageInfo {
		public function __construct(
			private bool $usedInSelectExpressions = false,
			private bool $usedInConditions = false,
			private bool $hasNullChecks = false,
			private bool $hasNonNullableFieldAccess = false
		) {
		}
		
		public function isUsedInSelectExpressions(): bool {
			return $this->usedInSelectExpressions;
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