<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstAny
	 */
	class AstAny extends Ast {
		
		/**
		 * @var AstInterface The right-hand operand of the AND expression.
		 */
		protected AstInterface $identifier;
		
		/**
		 * AstAny constructor.
		 * @param AstInterface $entityOrIdentifier
		 */
		public function __construct(AstInterface $entityOrIdentifier) {
			$this->identifier = $entityOrIdentifier;
			$this->identifier->setParent($this);
		}
		
		/**
		 * Accept a visitor to perform operations on this node.
		 * @param AstVisitorInterface $visitor The visitor to accept.
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->identifier->accept($visitor);
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
		 * @param AstIdentifier $ast
		 * @return void
		 */
		public function setIdentifier(AstIdentifier $ast): void {
			$this->identifier = $ast;
		}
		
		/**
		 * Clone this node
		 * @return static
		 */
		public function deepClone(): static {
			// Clone the identifier
			$clonedIdentifier = $this->identifier->deepClone();
			
			// Create new instance with cloned identifier
			// @phpstan-ignore-next-line new.static
			return new static($clonedIdentifier);
		}
	}