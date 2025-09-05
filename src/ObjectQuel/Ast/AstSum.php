<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstSum
	 */
	class AstSum extends AstAggregate {
		
		/**
		 * Returns string representation of aggregate
		 * @return string
		 */
		public function getType(): string {
			return "SUM";
		}
	}