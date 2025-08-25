<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstIsNumeric
	 */
	class AstIsFloat extends Ast {
		
		/**
		 * The value or string to check
		 * @var AstInterface
		 */
		protected AstInterface $identifierOrString;
		
		/**
		 * AstIsNumeric constructor.
		 * @param AstInterface $identifierOrString
		 */
		public function __construct(AstInterface $identifierOrString) {
			$this->identifierOrString = $identifierOrString;
			$this->identifierOrString->setParent($this);
		}
		
		/**
		 * Accept the visitor
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->identifierOrString->accept($visitor);
		}
		
		/**
		 * Retrieves the numerical value stored in this AST node.
		 * @return AstInterface The stored numerical value.
		 */
		public function getValue(): AstInterface {
			return $this->identifierOrString;
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
			$clonedIdentifier = $this->identifierOrString->deepClone();
			
			// Return cloned node
			// @phpstan-ignore-next-line new.static
			return new static($clonedIdentifier);
		}
	}