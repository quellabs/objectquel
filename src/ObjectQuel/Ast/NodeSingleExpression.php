<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Structural interface for AST nodes that wrap exactly one inner expression.
	 *
	 * Implemented by unary operators, logical NOT, NULL checks, and alias wrappers
	 * (AstUnaryOperation, AstNot, AstCheckNull, AstCheckNotNull, AstAlias, AstIfNull).
	 * Walkers use this interface to recurse into the wrapped expression without
	 * enumerating every concrete wrapper type.
	 */
	interface NodeSingleExpression {
		
		/**
		 * Returns the single inner expression wrapped by this node.
		 * @return AstInterface
		 */
		public function getExpression(): AstInterface;
	}