<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstSumU
	 */
	class AstSumU extends AstAggregate {
		
		/**
		 * Returns string representation of aggregate
		 * @return string
		 */
		public function getType(): string {
			return "SUMU";
		}
	}