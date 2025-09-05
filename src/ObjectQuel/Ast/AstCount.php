<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstCount
	 */
	class AstCount extends AstAggregate {

		/**
		 * Returns string representation of aggregate
		 * @return string
		 */
		public function getType(): string {
			return "COUNT";
		}
		
		/**
		 * Returns the return type of this node
		 * @return string|null
		 */
		public function getReturnType(): ?string {
			return "integer";
		}
	}
