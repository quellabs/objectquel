<?php

	namespace Quellabs\ObjectQuel\Execution\Support;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	class AggregateRewriter {
		
		/**
		 * Replace an aggregate node with a window-function node.
		 * @param AstInterface $aggregate Aggregate node to replace
		 * @return void
		 */
		public static function rewriteAggregateAsWindowFunction(AstInterface $aggregate): void {
			// Clone the aggregate node and clear the conditions
			$cleanAgg = self::cloneAggregateWithoutConditions($aggregate);
			
			// Create a new subquery node that replaces the original aggregate
			$windowFn = new AstSubquery(
				AstSubquery::TYPE_WINDOW,
				$cleanAgg,
				[],
				null,
				$aggregate->getType(),
			);
			
			// Replace the aggregate with the new version
			AstNodeReplacer::replaceChild($aggregate->getParent(), $aggregate, $windowFn);
		}
		
		/**
		 * Rewrites an aggregate expression as a correlated subquery.
		 * This is typically done to handle aggregates that can't be processed in the main query,
		 * such as aggregates with different grouping or filtering requirements.
		 */
		public static function rewriteAggregateAsCorrelatedSubquery(AstRetrieve $root, AstInterface $aggregate): void {
			// Collect all range references (table/alias references) used within the aggregate expression
			// This includes any tables or aliases that the aggregate depends on
			$aggRanges = RangeUtilities::collectRangesFromNode($aggregate);
			
			// Get the ranges available in the outer query context
			// These are the tables/aliases that can be correlated between outer and inner queries
			$outerRanges = $root->getRanges();
			
			// Determine the minimal set of ranges needed for correlation
			// This ensures we only include necessary table references in the subquery
			// to maintain proper correlation with the outer query
			$neededRanges = RangeUtilities::computeMinimalRangeSet($outerRanges, $aggRanges);
			
			// Deep clone the needed ranges to avoid reference issues
			// Each range in the subquery must be independent of the outer query ranges
			$clonedRanges = array_map(static fn(AstRange $r) => $r->deepClone(), $neededRanges);
			
			// Extract any WHERE conditions from the original aggregate
			// These will become the subquery's WHERE clause
			$subWhere = $aggregate->getConditions();
			
			// Create a clean version of the aggregate without its conditions
			// The conditions will be moved to the subquery's WHERE clause instead
			$cleanAgg = self::cloneAggregateWithoutConditions($aggregate);
			
			// Construct the correlated scalar subquery
			// TYPE_SCALAR indicates this subquery returns a single value (typical for aggregates)
			$subquery = new AstSubquery(
				AstSubquery::TYPE_SCALAR,        // Subquery type - returns single scalar value
				$cleanAgg,                       // The aggregate expression (without conditions)
				$clonedRanges,                   // Table ranges for correlation
				$subWhere,                       // WHERE conditions moved from aggregate
				$aggregate->getType(),           // Preserve the original aggregate's data type
			);
			
			// Replace the original aggregate node with the new subquery in the AST
			// This maintains the query structure while changing the execution strategy
			AstNodeReplacer::replaceChild($aggregate->getParent(), $aggregate, $subquery);
		}
		
		/**
		 * Deep-clone an aggregate and drop embedded conditions.
		 * @param AstInterface $aggregate Aggregate to clone
		 * @return AstInterface Clean clone without conditions
		 */
		public static function cloneAggregateWithoutConditions(AstInterface $aggregate): AstInterface {
			$clone = $aggregate->deepClone();
			$clone->setConditions(null);
			return $clone;
		}
	}