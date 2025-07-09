<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
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
			// We can only call getLeft/getRight on these nodes
			if (
				!$node instanceof AstTerm &&
				!$node instanceof AstBinaryOperator &&
				!$node instanceof AstExpression &&
				!$node instanceof AstFactor
			) {
				return;
			}
			
			// Get the left child node
			$left = $node->getLeft();
			
			// If the left node is an entity and has a defined macro, replace it
			// @phpstan-ignore-next-line
			if ($this->identifierIsEntity($left) && isset($this->macros[$left->getName()])) {
				// Substitute the left node with its corresponding macro
				// @phpstan-ignore-next-line
				$node->setLeft($this->macros[$left->getName()]);
			}
			
			// Get the right child node
			$right = $node->getRight();
			
			// If the right node is an entity and has a defined macro, replace it
			// @phpstan-ignore-next-line
			if ($this->identifierIsEntity($right) && isset($this->macros[$right->getName()])) {
				// Substitute the right node with its corresponding macro
				// @phpstan-ignore-next-line
				$node->setRight($this->macros[$right->getName()]);
			}
		}
	}