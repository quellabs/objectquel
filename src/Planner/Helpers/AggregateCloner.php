<?php
	
	namespace Quellabs\ObjectQuel\Planner\Helpers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAggregate;
	
	class AggregateCloner {
		
		/**
		 * Deep-clone an aggregate and drop embedded conditions.
		 * @param AstAggregate $aggregate Aggregate to clone
		 * @return AstAggregate Clean clone without conditions
		 */
		public static function cloneWithoutConditions(AstAggregate $aggregate): AstAggregate {
			$clone = $aggregate->deepClone();
			$clone->setConditions(null);
			return $clone;
		}
	}