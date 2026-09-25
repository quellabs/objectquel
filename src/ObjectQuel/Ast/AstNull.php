<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	class AstNull extends Ast {
		
		/**
		 * Returns the value.
		 * @return null
		 */
		public function getValue(): null {
			return null;
		}
		
		/**
		 * Returns a deep clone of this node.
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static();
		}
	}