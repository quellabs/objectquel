<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AliasPlugAliasPattern
	 *
	 * This visitor implements the visitor pattern to set alias patterns on AstAlias nodes
	 * that contain AstIdentifiers at the entity level (without parents).
	 * The pattern is derived from the range name of the identifier expression.
	 */
	class AliasPlugAliasPattern implements AstVisitorInterface {
		
		/**
		 * Visits a node in the AST and adds a unique alias pattern if conditions are met
		 *
		 * This method:
		 * 1. Checks if the node is an AstAlias
		 * 2. Checks if the alias expression is an AstIdentifier
		 * 3. Ensures the identifier doesn't have a parent (is at entity level)
		 * 4. Sets the alias pattern based on the identifier's range name
		 *
		 * @param AstInterface $node The current node being visited
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			// Only process AstAlias nodes, skip all others
			if (!$node instanceof AstAlias) {
				return;
			}
			
			// Skip if the alias expression is not an AstIdentifier
			if (!$node->getExpression() instanceof AstIdentifier) {
				return;
			}
			
			/**
			 * Skip properties (identifiers with parents)
			 * We only want to process top-level entity identifiers
			 */
			if ($node->getExpression()->hasParent()) {
				return;
			}
			
			// Set the alias pattern using the range name from the identifier's range
			// The pattern format is "[range_name]."
			$node->setAliasPattern($node->getExpression()->getRange()->getName() . ".");
		}
	}