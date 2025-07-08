<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AstTernary
	 *
	 * Represents a ternary operation (condition ? true : false) in the AST (Abstract Syntax Tree).
	 */
	class AstTernary extends Ast {
		
		/**
		 * @var AstInterface The condition of the ternary operation.
		 */
		protected AstInterface $condition;
		
		/**
		 * @var AstInterface The true branch of the ternary operation.
		 */
		protected AstInterface $true;
		
		/**
		 * @var AstInterface The false branch of the ternary operation.
		 */
		protected AstInterface $false;
		
		/**
		 * AstTernary constructor.
		 *
		 * @param AstInterface $condition The condition.
		 * @param AstInterface $true The true branch.
		 * @param AstInterface $false The false branch.
		 */
		public function __construct(AstInterface $condition, AstInterface $true, AstInterface $false) {
			$this->condition = $condition;
			$this->true = $true;
			$this->false = $false;
		}
		
		/**
		 * Accept a visitor to perform operations on this node.
		 * @param AstVisitorInterface $visitor The visitor to accept.
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
			$this->condition->accept($visitor);
			$this->true->accept($visitor);
			$this->false->accept($visitor);
		}
		
		/**
		 * Get the condition of the ternary operation.
		 * @return AstInterface The condition.
		 */
		public function getCondition(): AstInterface {
			return $this->condition;
		}
		
		/**
		 * Get the true branch of the ternary operation.
		 * @return AstInterface The true branch.
		 */
		public function getTrue(): AstInterface {
			return $this->true;
		}
		
		/**
		 * Get the false branch of the ternary operation.
		 * @return AstInterface The false branch.
		 */
		public function getFalse(): AstInterface {
			return $this->false;
		}
	}
