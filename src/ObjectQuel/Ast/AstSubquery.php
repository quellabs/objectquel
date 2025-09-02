<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstSubquery
	 */
	class AstSubquery extends Ast {
		
		/**
		 * @var AstInterface The right-hand operand of the AND expression.
		 */
		protected AstInterface $expression;
		
		/**
		 * @var AstInterface|null The conditions for this aggregator
		 */
		private ?AstInterface $conditions;
		
		/**
		 * AstMin constructor.
		 * @param AstInterface $entityOrIdentifier
		 */
		public function __construct(AstInterface $expression, ?AstInterface $conditions = null) {
			$this->expression = $expression;
			$this->conditions = $conditions;
			
			$this->expression->setParent($this);
			$conditions?->setParent($this);
		}
		
		/**
		 * Accept a visitor to perform operations on this node.
		 * @param AstVisitorInterface $visitor The visitor to accept.
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->expression->accept($visitor);
			$this->conditions?->accept($visitor);
		}
		
		/**
		 * Get the subquery expression
		 * @return AstInterface The left operand.
		 */
		public function getExpression(): AstInterface {
			return $this->expression;
		}
		
		/**
		 * Updates the identifier with a new AST
		 * @param AstIdentifier $ast
		 * @return void
		 */
		public function setIdentifier(AstIdentifier $ast): void {
			$this->expression = $ast;
		}
		
		/**
		 * Returns the conditions for this aggregator
		 * @return AstInterface|null
		 */
		public function getConditions(): ?AstInterface {
			return $this->conditions;
		}
		
		/**
		 * Clone this node
		 * @return static
		 */
		public function deepClone(): static {
			// Clone the identifier
			$clonedIdentifier = $this->expression->deepClone();
			$clonedConditions = $this->conditions?->deepClone();
			
			// Create new instance with cloned identifier
			// @phpstan-ignore-next-line new.static
			// Return cloned node
			return new static($clonedIdentifier, $clonedConditions);
		}
	}