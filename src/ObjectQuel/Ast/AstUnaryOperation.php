<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Represents a unary operation in the AST (e.g. IS EMPTY, IS INTEGER, IS FLOAT).
	 *
	 * Wraps a single inner expression and applies a named operator to it.
	 * Implements NodeSingleExpression so that walkers can recurse into the inner
	 * expression without knowing the concrete wrapper type.
	 */
	class AstUnaryOperation extends Ast implements NodeSingleExpression {
		
		/**
		 * The inner expression this operation is applied to.
		 * @var AstInterface
		 */
		private AstInterface $expression;
		
		/**
		 * The operator applied to the inner expression (e.g. 'IS EMPTY').
		 * @var string
		 */
		private string $operator;
		
		/**
		 * @param AstInterface $expression The inner expression to operate on
		 * @param string $operator The operator to apply
		 */
		public function __construct(AstInterface $expression, string $operator) {
			$this->expression = $expression;
			$this->operator = $operator;
			
			$expression->setParent($this);
		}
		
		/**
		 * Returns the inner expression this operation wraps.
		 * @return AstInterface
		 */
		public function getExpression(): AstInterface {
			return $this->expression;
		}
		
		/**
		 * Replaces the inner expression.
		 * Also updates the parent reference on the new expression.
		 * @param AstInterface $expression
		 * @return void
		 */
		public function setExpression(AstInterface $expression): void {
			$this->expression = $expression;
			$expression->setParent($this);
		}
		
		/**
		 * Returns the operator applied to the inner expression.
		 * @return string
		 */
		public function getOperator(): string {
			return $this->operator;
		}
		
		/**
		 * Accepts a visitor to perform operations on this node.
		 * Visits this node first, then recurses into the inner expression.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->expression->accept($visitor);
		}
		
		/**
		 * Returns a deep clone of this node with an independent copy of the inner expression.
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static($this->expression->deepClone(), $this->operator);
		}
	}