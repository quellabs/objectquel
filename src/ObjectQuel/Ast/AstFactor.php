<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	class AstFactor extends Ast {
		
		protected AstInterface $left;
		protected AstInterface $right;
		protected string $operator;
		
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
		 * Updates the left side with a new AST
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
		 * Updates the right side with a new AST
		 * @param AstInterface $ast
		 * @return void
		 */
		public function setRight(AstInterface $ast): void {
			$this->right = $ast;
		}
		
		public function deepClone(): static {
			// Clone both operands
			$clonedLeft = $this->left->deepClone();
			$clonedRight = $this->right->deepClone();
			
			// Create new instance with cloned operands
			// Parent relationships are already set by the constructor
			return new static($clonedLeft, $clonedRight, $this->operator);
		}
	}