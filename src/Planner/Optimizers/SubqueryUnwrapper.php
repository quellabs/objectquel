<?php
	
	namespace Quellabs\ObjectQuel\Planner\Optimizers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	
	/**
	 * Unwraps a single subquery range into the outer retrieve when no other
	 * database ranges are present. This avoids unnecessary derived-table or
	 * temp-table overhead when the subquery is the sole data source.
	 *
	 * Limitation: the ObjectQuel language currently does not support nested
	 * subqueries, so this unwrapper assumes at most one level of nesting.
	 * If nested subqueries are introduced in a future version, this optimizer
	 * will need to become recursive or be replaced with a multi-pass unwrapper.
	 */
	class SubqueryUnwrapper {
		
		/**
		 * Optimize the provided retrieve AST in-place.
		 * @param AstRetrieve $retrieve
		 * @return void
		 */
		public function optimize(AstRetrieve $retrieve): void {
			// Retrieve all ranges
			$ranges = $retrieve->getRanges();
			
			// Check for exactly one subquery range and no AstRangeDatabase ranges
			$subqueryRanges = array_filter($ranges, fn($r) => $r instanceof AstRangeDatabaseSubquery);
			$databaseRanges = array_filter($ranges, fn($r) => $r instanceof AstRangeDatabase);
			
			// Conditions not met. Do nothing.
			if (count($subqueryRanges) !== 1 || count($databaseRanges) > 0) {
				return;
			}
			
			/** @var AstRangeDatabaseSubquery $subquery */
			$subquery = reset($subqueryRanges);
			$inner = $subquery->getQuery();
			
			// Merge inner into outer — the subquery range is discarded and the
			// inner retrieve's ranges, values and grouping become the outer's directly.
			$retrieve->setRanges($inner->getRanges());
			$retrieve->setValues($inner->getValues());
			$retrieve->setGroupBy($inner->getGroupBy());
			
			// AND the conditions together if both exist
			if ($retrieve->getConditions() === null) {
				// Outer has no conditions — just take the inner's conditions as-is
				$retrieve->setConditions($inner->getConditions());
			} elseif ($inner->getConditions() !== null) {
				// Both have conditions — combine them so both must be satisfied
				$retrieve->setConditions(new AstBinaryOperator(
					$retrieve->getConditions(),
					$inner->getConditions(),
					"AND"
				));
			}
			
			// Sort: outer takes precedence if set, otherwise inherit from inner
			if (empty($retrieve->getSort())) {
				$retrieve->setSort($inner->getSort());
			}
		}
	}