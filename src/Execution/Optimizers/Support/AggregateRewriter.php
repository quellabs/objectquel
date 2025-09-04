<?php

	namespace Quellabs\ObjectQuel\Execution\Optimizers\Support;
	
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
			$cleanAgg = self::cloneAggregateWithoutConditions($aggregate);
			
			$windowFn = new AstSubquery(
				AstSubquery::TYPE_WINDOW,
				$cleanAgg,
				[],
				null
			);
			
			AstNodeReplacer::replaceChild($aggregate->getParent(), $aggregate, $windowFn);
		}
		
		/**
		 * Replace an aggregate node with a correlated scalar subquery that computes it
		 * over the minimal set of ranges it depends on.
		 * @param AstRetrieve $root Outer query AST
		 * @param AstInterface $aggregate Aggregate node to replace
		 * @return void
		 */
		public static function rewriteAggregateAsCorrelatedSubquery(AstRetrieve $root, AstInterface $aggregate): void {
			$aggRanges = RangeUtilities::collectRangesFromNode($aggregate);
			$outerRanges = $root->getRanges();
			
			$neededRanges = RangeUtilities::computeMinimalRangeSet($outerRanges, $aggRanges);
			$clonedRanges = array_map(static fn(AstRange $r) => $r->deepClone(), $neededRanges);
			
			$subWhere = $aggregate->getConditions();
			$cleanAgg = self::cloneAggregateWithoutConditions($aggregate);
			
			$subquery = new AstSubquery(
				AstSubquery::TYPE_SCALAR,
				$cleanAgg,
				$clonedRanges,
				$subWhere
			);
			
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
		
		
		// -------------------------------------------------
		// Miscellaneous
		// -------------------------------------------------

	}