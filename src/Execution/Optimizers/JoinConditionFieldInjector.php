<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\Execution\Support\AstUtilities;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectJoinConditionIdentifiers;
	
	/**
	 * Ensures JOIN operations have access to required identifier fields.
	 *
	 * When a WHERE condition references fields from joined tables, those fields
	 * must be included in the SELECT clause for the JOIN to execute properly,
	 * even if the user didn't explicitly request them. This optimizer identifies
	 * such fields and adds them as hidden projections.
	 *
	 * Example:
	 *   Query: SELECT users.name WHERE users.department.name = "Engineering"
	 *   Needs: users.department_id (for JOIN) but user only asked for users.name
	 *   Result: Adds users.department_id as hidden field, filters it from output
	 */
	class JoinConditionFieldInjector {
		
		/**
		 * Adds hidden projections for identifier fields referenced in WHERE conditions
		 * that are needed to execute JOINs but weren't explicitly requested by the user.
		 * @param AstRetrieve $ast The query to optimize
		 * @return void
		 */
		public function optimize(AstRetrieve $ast): void {
			$allProjectionsAggregates = AstUtilities::areAllSelectFieldsAggregates($ast);
			
			// No conditions means no WHERE clause, so no implicit fields needed
			if ($ast->getConditions() === null) {
				return;
			}
			
			// Find all database identifiers used in WHERE conditions that belong to joined ranges
			$visitor = new CollectJoinConditionIdentifiers($ast);
			$ast->accept($visitor);
			
			// Add each identifier as a hidden projection field
			foreach ($visitor->getIdentifiers() as $identifier) {
				$clonedIdentifier = $identifier->deepClone();
				
				// When all user projections are aggregates (COUNT, SUM, etc.),
				// wrap implicit fields in MIN() to satisfy SQL GROUP BY requirements
				if ($allProjectionsAggregates) {
					$alias = new AstAlias($identifier->getCompleteName(), new AstMin($clonedIdentifier));
				} else {
					$alias = new AstAlias($identifier->getCompleteName(), $clonedIdentifier);
				}
				
				// Adds value to projection
				$ast->addValue($alias);
				
				// Mark invisible so this technical field doesn't appear in user results
				$alias->setShowInResult(false);
			}
		}
	}