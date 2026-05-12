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
	interface NodeSingleExpression extends AstInterface {
		
		/**
		 * Returns the single inner expression wrapped by this node.
		 * @return AstInterface
		 */
		public function getExpression(): AstInterface;
		
		/**
		 * Replaces the inner expression wrapped by this node.
		 * Required by ConditionFilter to reconstruct unary wrappers after
		 * filtering their inner expression without knowing the concrete type.
		 * @param AstInterface $expression
		 * @return void
		 */
		public function setExpression(AstInterface $expression): void;
	}