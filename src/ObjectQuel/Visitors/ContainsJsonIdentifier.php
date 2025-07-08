<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class ContainsJsonIdentifier
	 *
	 * This visitor class implements the visitor pattern to detect identifiers that
	 * are attached to JSON sources within an Abstract Syntax Tree (AST).
	 * When such an identifier is found, it throws an exception to halt traversal
	 * and signal the presence of a JSON-sourced identifier.
	 */
	class ContainsJsonIdentifier implements AstVisitorInterface {
		
		/**
		 * Visits a node in the AST and checks if it's an identifier attached to a JSON source.
		 *
		 * The method examines each node to determine if:
		 * 1. It is an AstIdentifier node
		 * 2. It has a non-null range
		 * 3. The range is specifically a JSON source (AstRangeJsonSource)
		 *
		 * If all conditions are met, an exception is thrown to indicate that an
		 * identifier attached to a JSON source was found.
		 *
		 * @param AstInterface $node The current node being visited
		 * @return void
		 * @throws \Exception When an identifier attached to a JSON source is found
		 */
		public function visitNode(AstInterface $node): void {
			// Skip if the node is not an AstIdentifier
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Skip if the identifier doesn't have a range
			if ($node->getRange() === null) {
				return;
			}
			
			// Skip if the identifier's range is not a JSON source
			if (!$node->getRange() instanceof AstRangeJsonSource) {
				return;
			}
			
			// If we reach here, we found an identifier that's attached to a JSON source
			// Throw exception to halt traversal and signal this detection to the caller
			throw new \Exception("Contains json");
		}
	}