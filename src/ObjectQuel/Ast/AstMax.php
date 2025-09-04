<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstMax
	 */
	class AstMax extends Ast {
		
		/**
		 * @var AstInterface The right-hand operand of the AND expression.
		 */
		protected AstInterface $identifier;
		
		/**
		 * @var AstInterface|null The conditions for this aggregator
		 */
		private ?AstInterface $conditions;
		
		/**
		 * AstMax constructor.
		 * @param AstInterface $entityOrIdentifier
		 */
		public function __construct(AstInterface $entityOrIdentifier, ?AstInterface $conditions = null) {
			$this->identifier = $entityOrIdentifier;
			$this->conditions = $conditions;
			
			$this->identifier->setParent($this);
			$conditions?->setParent($this);
		}
		
		/**
		 * Accept a visitor to perform operations on this node.
		 * @param AstVisitorInterface $visitor The visitor to accept.
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->identifier->accept($visitor);
			$this->conditions?->accept($visitor);
		}
		
		/**
		 * Returns string representation of aggregate
		 * @return string
		 */
		public function getType(): string {
			return "MAX";
		}
		
		/**
		 * Get the left-hand operand of the AND expression.
		 * @return AstInterface The left operand.
		 */
		public function getIdentifier(): AstInterface {
			return $this->identifier;
		}
		
		/**
		 * Updates the identifier with a new AST
		 * @param AstInterface $ast
		 * @return void
		 */
		public function setIdentifier(AstInterface $ast): void {
			$this->identifier = $ast;
		}
		
		/**
		 * Returns the conditions for this aggregator
		 * @return AstInterface|null
		 */
		public function getConditions(): ?AstInterface {
			return $this->conditions;
		}
		
		/**
		 * Updates the conditions for this aggregator
		 * @param AstInterface|null $conditions
		 * @return void
		 */
		public function setConditions(?AstInterface $conditions): void {
			$this->conditions = $conditions;
		}
		
		/**
		 * Clone this node
		 * @return static
		 */
		public function deepClone(): static {
			// Clone the identifier
			$clonedIdentifier = $this->identifier->deepClone();
			$clonedConditions = $this->conditions?->deepClone();
			
			// Create new instance with cloned identifier
			// @phpstan-ignore-next-line new.static
			// Return cloned node
			return new static($clonedIdentifier, $clonedConditions);
		}
	}