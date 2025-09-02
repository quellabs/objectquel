<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * AST visitor implementation that checks for the presence of specific node types
	 * during tree traversal. This visitor is designed to detect unwanted or restricted
	 * node types within an Abstract Syntax Tree and throw an exception when found.
	 */
	class ContainsNodeObject implements AstVisitorInterface {
		
		private AstInterface $node;
		
		/**
		 * Constructor - Initialize the visitor with node types to detect
		 * @param AstInterface $node
		 */
		public function __construct(AstInterface $node) {
			$this->node = $node;
		}
		
		/**
		 * This method is called for each node during AST traversal.
		 * @param AstInterface $node The current AST node being visited
		 * @return void
		 * @throws \Exception
		 */
		public function visitNode(AstInterface $node): void {
			if ($node === $this->node) {
				throw new \Exception("Has node");
			}
		}
	}