<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstIfNull
	 */
	class AstIfNull extends Ast implements NodeSingleExpression {
		
		/**
		 * @var AstInterface The expression
		 */
		protected AstInterface $expression;
		
		/**
		 * @var AstInterface The value when the expression is NULL
		 */
		protected AstInterface $altValue;
		
		/**
		 * AstIfnull constructor.
		 * @param AstInterface $expression
		 * @param AstInterface $altValue
		 */
		public function __construct(AstInterface $expression, AstInterface $altValue) {
			$this->expression = $expression;
			$this->altValue = $altValue;
			
			$expression->setParent($this);
			$altValue->setParent($this);
		}
		
		/**
		 * Accept a visitor to perform operations on this node.
		 * @param AstVisitorInterface $visitor The visitor to accept.
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->expression->accept($visitor);
			$this->altValue->accept($visitor);
		}
		
		/**
		 * Returns the expression.
		 * @return AstInterface
		 */
		public function getExpression(): AstInterface {
			return $this->expression;
		}
		
		/**
		 * Sets the expression.
		 * @param AstInterface $expression
		 * @return void
		 */
		public function setExpression(AstInterface $expression): void {
			$this->expression = $expression;
		}
		
		/**
		 * Returns the alternate value.
		 * @return AstInterface
		 */
		public function getAltValue(): AstInterface {
			return $this->altValue;
		}
		
		/**
		 * Sets the alternate value.
		 * @param AstInterface $altValue
		 * @return void
		 */
		public function setAltValue(AstInterface $altValue): void {
			$this->altValue = $altValue;
		}
		
		/**
		 * Returns a deep clone of this node.
		 * @return static
		 */
		public function deepClone(): static {
			// Clone both operands
			$clonedExpression = $this->expression->deepClone();
			$clonedAltValue = $this->altValue->deepClone();
			
			// Create new instance with cloned operands
			// Parent relationships are already set by the constructor
			// @phpstan-ignore-next-line new.static
			return new static($clonedExpression, $clonedAltValue);
		}
	}
