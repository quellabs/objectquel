<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstIsNumeric
	 */
	class AstExists extends Ast {
		
		/**
		 * The value or string to check
		 * @var AstIdentifier
		 */
		protected AstIdentifier $identifier;
		
		/**
		 * AstExists constructor.
		 * @param AstIdentifier $identifier
		 */
		public function __construct(AstIdentifier $identifier) {
			$this->identifier = $identifier;
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
		 * @return AstIdentifier
		 */
		public function getIdentifier(): AstIdentifier {
			return $this->identifier;
		}
		
		/**
		 * Returns the return type of this node
		 * @return string|null
		 */
		public function getReturnType(): ?string {
			return "boolean";
		}
		
	}