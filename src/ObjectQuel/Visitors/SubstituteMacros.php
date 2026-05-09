<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeBinary;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;
	
	/**
	 * This class implements the Visitor pattern to process an Abstract Syntax Tree (AST).
	 * It replaces entity identifiers with their corresponding macros defined in the column section.
	 * Part of a query processing system that handles entity substitution for ObjectQuel.
	 */
	class SubstituteMacros implements AstVisitorInterface {
		
		/**
		 * An array of macros where keys are entity names and values are their replacements
		 * @var array<string, AstInterface>
		 */
		private array $macros;
		
		/**
		 * EntityPlugMacros constructor
		 * @param array<string, AstInterface> $macros Array of macro definitions to be used for replacement
		 */
		public function __construct(array $macros) {
			$this->macros = $macros;
		}
		
		/**
		 * Visits a node in the AST and performs macro substitution if applicable
		 * Implements the AstVisitorInterface method for traversing the syntax tree
		 * @param AstInterface $node The node being visited in the AST
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			// We can only call getLeft/getRight on these nodes
			if (!$node instanceof NodeBinary) {
				return;
			}
			
			// Get the left child node
			$left = $node->getLeft();
			
			// If the left node is an entity identifier and has a defined macro, replace it
			if (
				$left instanceof AstIdentifier &&
				$left->getType() === IdentifierType::EntityReference &&
				isset($this->macros[$left->getName()])
			) {
				// Substitute the left node with its corresponding macro
				$node->setLeft($this->macros[$left->getName()]);
			}
			
			// Get the right child node
			$right = $node->getRight();
			
			// If the right node is an entity identifier and has a defined macro, replace it
			if (
				$right instanceof AstIdentifier &&
				$right->getType() === IdentifierType::EntityReference &&
				isset($this->macros[$right->getName()])
			) {
				// Substitute the right node with its corresponding macro
				$node->setRight($this->macros[$right->getName()]);
			}
		}
	}