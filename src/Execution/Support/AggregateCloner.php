<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAggregate;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
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