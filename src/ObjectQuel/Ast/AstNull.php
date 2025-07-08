<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	class AstNull extends Ast {
		
		public function getValue(): null {
			return null;
		}
	}