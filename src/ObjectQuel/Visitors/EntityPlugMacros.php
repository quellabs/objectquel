<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * This class implements the Visitor pattern to process an Abstract Syntax Tree (AST).
	 * It replaces entity identifiers with their corresponding macros defined in the column section.
	 * Part of a query processing system that handles entity substitution for ObjectQuel.
	 */
	class EntityPlugMacros implements AstVisitorInterface {
		
		/**
		 * An array of macros where keys are entity names and values are their replacements
		 */
		private array $macros;
		
		/**
		 * EntityPlugMacros constructor
		 * @param array $macros Array of macro definitions to be used for replacement
		 */
		public function __construct(array $macros) {
			$this->macros = $macros;
		}
		
		/**
		 * Determines if an AST node represents an entity identifier
		 * @param AstInterface $ast The node to check
		 * @return bool Returns true if the node is an entity identifier, false otherwise
		 */
		protected function identifierIsEntity(AstInterface $ast): bool {
			return (
				$ast instanceof AstIdentifier &&                  // Must be an identifier node
				$ast->getRange() instanceof AstRangeDatabase &&   // Must have a database range
				!$ast->hasNext()                                  // Must not have chained properties
			);
		}
		
		/**
		 * Visits a node in the AST and performs macro substitution if applicable
		 * Implements the AstVisitorInterface method for traversing the syntax tree
		 * @param AstInterface $node The node being visited in the AST
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			// Only process nodes that can have left and right children
			if ($node instanceof AstFactor || $node instanceof AstTerm || $node instanceof AstExpression) {
				// Get the left child node
				$left = $node->getLeft();
				
				// If the left node is an entity and has a defined macro, replace it
				if ($this->identifierIsEntity($left) && isset($this->macros[$left->getName()])) {
					// Substitute the left node with its corresponding macro
					$node->setLeft($this->macros[$left->getName()]);
				}
				
				// Get the right child node
				$right = $node->getRight();
				
				// If the right node is an entity and has a defined macro, replace it
				if ($this->identifierIsEntity($right) && isset($this->macros[$right->getName()])) {
					// Substitute the right node with its corresponding macro
					$node->setRight($this->macros[$right->getName()]);
				}
			}
		}
	}