<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstExpression
	 *
	 * Represents a general expression node in the AST (Abstract Syntax Tree).
	 */
	class AstExpression extends Ast {
		
		/**
		 * @var AstInterface The left-hand operand of the expression.
		 */
		protected AstInterface $left;
		
		/**
		 * @var AstInterface The right-hand operand of the expression.
		 */
		protected AstInterface $right;
		
		/**
		 * @var string The operator for this expression.
		 */
		protected string $operator;
		
		/**
		 * AstExpression constructor.
		 * @param AstInterface $left     The left-hand operand.
		 * @param AstInterface $right    The right-hand operand.
		 * @param string       $operator The operator for this expression.
		 */
		public function __construct(AstInterface $left, AstInterface $right, string $operator) {
			$this->left = $left;
			$this->right = $right;
			$this->operator = $operator;
		}
		
		/**
		 * Accept a visitor to perform operations on this node.
		 * @param AstVisitorInterface $visitor The visitor to accept.
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->left->accept($visitor);
			$this->right->accept($visitor);
		}
		
		/**
		 * Get the operator used in this expression.
		 * @return string The operator.
		 */
		public function getOperator(): string {
			return $this->operator;
		}
		
		/**
		 * Get the left-hand operand of this expression.
		 * @return AstInterface The left operand.
		 */
		public function getLeft(): AstInterface {
			return $this->left;
		}
		
		/**
		 * @param AstInterface $ast
		 * @return void
		 */
		public function setLeft(AstInterface $ast): void {
			$this->left = $ast;
		}
		
		/**
		 * Get the right-hand operand of this expression.
		 * @return AstInterface The right operand.
		 */
		public function getRight(): AstInterface {
			return $this->right;
		}
		
		/**
		 * @param AstInterface $ast
		 * @return void
		 */
		public function setRight(AstInterface $ast): void {
			$this->right = $ast;
		}
	}
