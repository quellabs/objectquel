<?php
	
	namespace Services\Signalize\Ast;
	
	use Services\Signalize\AstInterface;
	use Services\Signalize\AstVisitorInterface;
	
	class AstTerm extends Ast {
		
		protected AstInterface $left;
		protected AstInterface $right;
		protected string $operator;
		
		public function __construct(AstInterface $left, AstInterface $right, string $operator) {
			$this->left = $left;
			$this->right = $right;
			$this->operator = $operator;
		}
		
		/**
		 * Accept a visitor to perform operations on this node.
		 * @param AstVisitorInterface $visitor The visitor to accept.
		 * @return void
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
		 * Get the right-hand operand of this expression.
		 * @return AstInterface The right operand.
		 */
		public function getRight(): AstInterface {
			return $this->right;
		}
	}