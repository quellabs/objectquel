<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	class AstCheckNull extends Ast {
		
		protected AstInterface $expression;
		
		/**
		 * AstNot constructor
		 * @param AstInterface $expression
		 */
		public function __construct(AstInterface $expression) {
			$this->expression = $expression;
		}
		
		/**
		 * Accept a visitor to perform operations on this node.
		 * @param AstVisitorInterface $visitor The visitor to accept.
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->expression->accept($visitor);
		}
		
		/**
		 * Get the inner expression of the NOT expression
		 * @return AstInterface The left operand.
		 */
		public function getExpression(): AstInterface {
			return $this->expression;
		}
		
		/**
		 * Set the inner expression of the NOT expression
		 * @param AstInterface $ast
		 * @return void
		 */
		public function setExpression(AstInterface $ast): void {
			$this->expression = $ast;
		}
		
		/**
		 * Returns the return type of this node
		 * @return string|null
		 */
		public function getReturnType(): ?string {
			return "boolean";
		}
	}