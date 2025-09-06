<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstExists
	 */
	class AstExists extends Ast {
		
		/**
		 * The value or string to check
		 * @var AstInterface
		 */
		protected AstInterface $identifier;
		
		/**
		 * AstExists constructor.
		 * @param AstInterface $identifier
		 */
		public function __construct(AstInterface $identifier) {
			$this->identifier = $identifier;
			$this->identifier->setParent($this);
		}
		
		/**
		 * Accept the visitor
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->identifier->accept($visitor);
		}
		
		/**
		 * Retrieves the entity
		 * @return AstInterface
		 */
		public function getIdentifier(): AstInterface {
			return $this->identifier;
		}
		
		/**
		 * Returns the return type of this node
		 * @return string|null
		 */
		public function getReturnType(): ?string {
			return "boolean";
		}
		
		/**
		 * Clone this node
		 * @return static
		 */
		public function deepClone(): static {
			// Clone the identifier
			$clonedIdentifier = $this->identifier->deepClone();
			
			// Return cloned node
			// @phpstan-ignore-next-line new.static
			return new static($clonedIdentifier);
		}
	}