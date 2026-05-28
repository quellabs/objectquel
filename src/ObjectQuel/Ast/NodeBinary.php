<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
	/**
	 * Structural interface for AST nodes that have exactly two child expressions:
	 * a left operand and a right operand.
	 *
	 * Implemented by comparison expressions, logical operators, and arithmetic nodes
	 * (AstExpression, AstBinaryOperator, AstTerm, AstFactor). Walkers that need to
	 * recurse into both sides of an operation use this interface rather than
	 * enumerating concrete types.
	 */
	interface NodeBinary extends AstInterface {
		
		/**
		 * Get the operator used in this expression.
		 * @return string The operator.
		 */
		public function getOperator(): string;
		
		/**
		 * Returns the left-hand child expression.
		 * @return AstInterface
		 */
		public function getLeft(): AstInterface;
		
		/**
		 * Returns the right-hand child expression.
		 * @return AstInterface
		 */
		public function getRight(): AstInterface;
		
		/**
		 * Updates the left side with a new AST
		 * @param AstInterface $ast
		 * @return void
		 */
		public function setLeft(AstInterface $ast): void;
		
		/**
		 * Updates the right side with a new AST
		 * @param AstInterface $ast
		 * @return void
		 */
		public function setRight(AstInterface $ast): void;
	}