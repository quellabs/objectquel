<?php
	
	namespace Quellabs\ObjectQuel\Visitor;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Visitor class that detects if an AST node contains references to JSON sources
	 * This visitor is used to identify mixed data source references in query conditions
	 */
	class ContainsJsonReference implements AstVisitorInterface {
		
		/**
		 * Visits an AST node and checks if it references a JSON source
		 * If a JSON reference is found, an exception is thrown to interrupt the traversal
		 *
		 * @param AstInterface $node The current node being visited in the AST
		 * @return void
		 * @throws \Exception When a JSON reference is detected
		 */
		public function visitNode(AstInterface $node) {
			// We only care about identifier nodes, since these reference fields from ranges
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Check if the entity's range is a JSON source
			if (!$node->getRange() instanceof AstRangeJsonSource) {
				return;
			}
			
			// If we reached here, we found a JSON reference
			// Throw an exception to signal this to the calling method
			throw new \Exception("Contains json reference");
		}
	}