<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\Execution\Support\AstUtilities;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectJoinConditionIdentifiers;
	
	/**
	 * Adds referenced field values to the query's value list for join conditions.
	 * These are technical fields needed for joins but not visible in final results.
	 */
	class ValueReferenceOptimizer {
		
		/**
		 * Scans the query conditions to find field references that are needed for
		 * join operations but aren't explicitly requested in the SELECT clause.
		 * These fields are added as invisible aliases to ensure proper join execution.
		 * @param AstRetrieve $ast The AST node representing the retrieve operation
		 * @return void
		 */
		public function optimize(AstRetrieve $ast): void {
			// True if all projection fields are aggregates
			$allProjectionsAggregates = AstUtilities::areAllSelectFieldsAggregates($ast);
			
			// Skip optimization if no conditions exist - no joins to process
			if ($ast->getConditions() === null) {
				return;
			}
			
			// Use visitor pattern to traverse AST and collect field references
			$visitor = new CollectJoinConditionIdentifiers($ast);
			$ast->accept($visitor);
			
			// Add each referenced field as an invisible alias
			foreach ($visitor->getIdentifiers() as $identifier) {
				// Clone to avoid modifying the original identifier used in conditions
				$clonedIdentifier = $identifier->deepClone();
				
				// Create alias using the field's complete name (e.g., "table.field")
				// Wrap identifier in AstMin if all projection fields are aggregates
				if ($allProjectionsAggregates) {
					$alias = new AstAlias($identifier->getCompleteName(), new AstMin($clonedIdentifier));
				} else {
					$alias = new AstAlias($identifier->getCompleteName(), $clonedIdentifier);
				}
				
				// Mark as invisible - needed for joins but not user-requested data
				// This prevents the field from appearing in final query results
				$alias->setVisibleInResult(false);
				
				// Add to the query's value list for execution
				$ast->addValue($alias);
			}
		}
	}