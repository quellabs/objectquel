<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class ContainsRange
	 * Throws an exception if the given range is used in the AST
	 */
	class ContainsCheckIsNullForRange implements AstVisitorInterface {
		
		private string $rangeName;
		
		/**
		 * ContainsRange constructor.
		 * @param string $rangeName
		 */
		public function __construct(string $rangeName) {
			$this->rangeName = $rangeName;
		}
		
		/**
		 * Function to visit a node in the AST (Abstract Syntax Tree).
		 * @param AstInterface $node
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			if (!$node instanceof AstCheckNull) {
				return;
			}
			
			if (!$node->getExpression() instanceof AstIdentifier) {
				return;
			}
			
			if ($node->getExpression()->getRange()->getName() === $this->rangeName) {
				throw new \Exception("Contains {$this->rangeName}");
			}
		}
	}