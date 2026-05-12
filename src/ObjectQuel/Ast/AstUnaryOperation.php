<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Represents a unary arithmetic operation in the AST (e.g. -x, +x).
	 *
	 * Produced by the parser when a +/- sign precedes a non-numeric operand.
	 * When a sign precedes a numeric literal, the sign is folded directly into
	 * the AstNumber value instead, so this node only appears for operands that
	 * are identifiers or sub-expressions.
	 */
	class AstUnaryOperation extends Ast {
		
		/**
		 * The operand this sign operator is applied to.
		 * @var AstInterface
		 */
		private AstInterface $expression;
		
		/**
		 * The sign operator: '+' or '-'.
		 * @var string
		 */
		private string $operator;
		
		/**
		 * @param AstInterface $expression The operand to apply the sign to
		 * @param string $operator The sign operator: '+' or '-'
		 */
		public function __construct(AstInterface $expression, string $operator) {
			$this->expression = $expression;
			$this->operator = $operator;
			
			$expression->setParent($this);
		}
		
		/**
		 * Returns the operand this sign operator is applied to.
		 * @return AstInterface
		 */
		public function getExpression(): AstInterface {
			return $this->expression;
		}
		
		/**
		 * Returns the sign operator ('+' or '-').
		 * @return string
		 */
		public function getOperator(): string {
			return $this->operator;
		}
		
		/**
		 * Accepts a visitor to perform operations on this node.
		 * Visits this node first, then recurses into the operand.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->expression->accept($visitor);
		}
		
		/**
		 * Returns a deep clone of this node with an independent copy of the operand.
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static($this->expression->deepClone(), $this->operator);
		}
	}