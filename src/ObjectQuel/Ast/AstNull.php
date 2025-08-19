<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	class AstNull extends Ast {
		
		public function getValue(): null {
			return null;
		}
		
		public function deepClone(): static {
			return new static();
		}
	}