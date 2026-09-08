<?php
	
	namespace Quellabs\ObjectQuel\Planner\Optimizers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseMaterialized;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseTempTable;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\Planner\Helpers\AstUtilities;
	use Quellabs\ObjectQuel\Planner\QueryPlan\PlanLogInterface;
	use Quellabs\ObjectQuel\Planner\QueryPlan\NullPlanLog;
	
	/**
	 * Class AggregateOptimizer
	 */
	class DatabaseRangePromotor {
		
		/**
		 * Optimize the provided retrieve AST in-place.
		 * @param AstRetrieve $retrieve
		 * @return void
		 */
		public function optimize(AstRetrieve $retrieve, PlanLogInterface $log = new NullPlanLog()): void {
			$ranges = [];
			
			foreach ($retrieve->getRanges() as $i => $range) {
				// Skip
				if (!$range instanceof AstRangeDatabaseSubquery) {
					$ranges[] = $range;
					continue;
				}
				
				// Fetch the inner query
				$innerQuery = $range->getQuery();
				
				// Replace range with temp table or materialized
				if ($this->rangeQueryContainsExternalSource($innerQuery)) {
					$replacement = new AstRangeDatabaseTempTable(
						$range->getName(),
						$innerQuery,
						'tmp_' . $range->getName() . '_' . uniqid(),
						$range->getJoinProperty(),
						$range->isRequired()
					);
					
					$log->note('optimizer', 'range', 'SUBQUERY_TO_TEMP_TABLE',
						"Range '{$range->getName()}' contains external source; promoted to temp table",
						$range->getName()
					);
				} else {
					$replacement = new AstRangeDatabaseMaterialized(
						$range->getName(),
						$innerQuery,
						$range->getJoinProperty(),
						$range->isRequired()
					);
					
					$log->note('optimizer', 'range', 'SUBQUERY_MATERIALIZED',
						"Range '{$range->getName()}' is pure SQL; kept as inline derived table",
						$range->getName()
					);
				}
				
				// Every AstIdentifier's range pointer (e.g. the `t` in `t.username`)
				// was captured by ResolveIdentifierRange earlier in the pipeline,
				// before this promotion ever runs — getRange() returns that stored
				// reference, not a live lookup by name. Left unrelinked, every
				// reference to this range throughout the query (projections,
				// WHERE, sort) would keep pointing at the discarded pre-promotion
				// $range object instead of $replacement.
				$this->relinkIdentifiers($retrieve, $range, $replacement);
				$ranges[] = $replacement;
			}

			$retrieve->setRanges($ranges);
		}

		/**
		 * Re-points every identifier referencing $oldRange (by stored object
		 * identity) to $newRange instead, so a range swap stays transparent to
		 * the rest of the query — see optimize()'s call site.
		 * @param AstRetrieve $retrieve
		 * @param AstRange $oldRange
		 * @param AstRange $newRange
		 * @return void
		 */
		private function relinkIdentifiers(AstRetrieve $retrieve, AstRange $oldRange, AstRange $newRange): void {
			foreach (AstUtilities::collectIdentifiersFromAst($retrieve) as $identifier) {
				if ($identifier->getRange() === $oldRange) {
					$identifier->setRange($newRange);
				}
			}
		}

		/**
		 * Recursively determines whether a retrieve node contains external sources
		 * @param AstRetrieve $retrieve
		 * @return bool True if any range in the inner query (recursively) is external
		 */
		protected function rangeQueryContainsExternalSource(AstRetrieve $retrieve): bool {
			foreach ($retrieve->getRanges() as $innerRange) {
				// Direct external source — JSON today, others in the future
				if ($innerRange instanceof AstRangeJsonSource) {
					return true;
				}
				
				// Nested subquery: recurse to check its ranges as well
				if ($innerRange instanceof AstRangeDatabaseSubquery) {
					if ($this->rangeQueryContainsExternalSource($innerRange->getQuery())) {
						return true;
					}
				}
			}
			
			return false;
		}
	}