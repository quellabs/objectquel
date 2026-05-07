<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Structural interface for type-check function nodes that wrap a single value expression.
	 *
	 * Implemented by AstIsNumeric, AstIsFloat, AstIsInteger, and AstIsEmpty. Walkers
	 * use this interface to recurse into the function's argument without enumerating
	 * every concrete function type.
	 */
	interface NodeFunction extends AstInterface {
		
		/**
		 * Returns the value expression this function operates on.
		 * @return AstInterface
		 */
		public function getValue(): AstInterface;
	}