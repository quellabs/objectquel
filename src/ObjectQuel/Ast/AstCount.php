<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstCount
	 */
	class AstCount extends Ast {
		
		/**
		 * @var AstInterface The right-hand operand of the AND expression.
		 */
		protected AstInterface $identifier;
		
		/**
		 * AstCount constructor.
		 * @param AstInterface $entityOrIdentifier
		 */
		public function __construct(AstInterface $entityOrIdentifier) {
			$this->identifier = $entityOrIdentifier;
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
		 * Returns the return type of this node
		 * @return string|null
		 */
		public function getReturnType(): ?string {
			return "integer";
		}
	}
