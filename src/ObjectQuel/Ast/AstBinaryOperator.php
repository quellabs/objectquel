<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstBinaryOperator
	 *
	 * Represents AND/OR logical expression in the AST (Abstract Syntax Tree).
	 */
	class AstBinaryOperator extends Ast {
		
		/**
		 * @var AstInterface The left-hand operand of the AND expression.
		 */
		protected AstInterface $left;
		
		/**
		 * @var AstInterface The right-hand operand of the AND expression.
		 */
		protected AstInterface $right;
		
		/**
		 * @var string Operator ('AND' or 'OR')
		 */
		private string $operator;
		
		/**
		 * AstBinaryOperator constructor.
		 * @param AstInterface $left  The left-hand operand.
		 * @param AstInterface $right The right-hand operand.
		 */
		public function __construct(AstInterface $left, AstInterface $right, string $operator) {
			$this->left = $left;
			$this->right = $right;
			$this->operator = $operator;
			
			$this->left->setParent($this);
			$this->right->setParent($this);
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
		 * Get the left-hand operand of the AND expression.
		 * @return AstInterface The left operand.
		 */
		public function getLeft(): AstInterface {
			return $this->left;
		}
		
		/**
		 * Updates the left side with a new AST
		 * @param AstInterface $ast
		 * @return void
		 */
		public function setLeft(AstInterface $ast): void {
			$this->left = $ast;
		}

		/**
		 * Get the right-hand operand of the AND expression.
		 * @return AstInterface The right operand.
		 */
		public function getRight(): AstInterface {
			return $this->right;
		}
		
		/**
		 * Updates the right side with a new AST
		 * @param AstInterface $ast
		 * @return void
		 */
		public function setRight(AstInterface $ast): void {
			$this->right = $ast;
		}
		
		/**
		 * Returns the operator ('AND' or 'OR')
		 * @return string
		 */
		public function getOperator(): string {
			return $this->operator;
		}
		
		/**
		 * Updates the operator
		 * @param string $operator
		 * @return void
		 */
		public function setOperator(string $operator): void {
			$this->operator = $operator;
		}
		
	}
