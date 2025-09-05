<?php
	
	namespace Quellabs\ObjectQuel\Execution\Support;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAggregate;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	class AggregateCloner {
		
		/**
		 * Deep-clone an aggregate and drop embedded conditions.
		 * @param AstAggregate $aggregate Aggregate to clone
		 * @return AstInterface Clean clone without conditions
		 */
		public static function cloneWithoutConditions(AstAggregate $aggregate): AstInterface {
			$clone = $aggregate->deepClone();
			$clone->setConditions(null);
			return $clone;
		}
	}