<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIn;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * A visitor implementation that traverses the AST to identify the first IN() clause
	 * used on the primary key. This class follows the Visitor design pattern to separate
	 * the traversal logic from the AST structure. When the target node is found, it
	 * throws a GetMainEntityInAstException containing the found node, effectively terminating
	 * the traversal.
	 */
	class GetMainEntityInAst implements AstVisitorInterface {
		
		/**
		 * The primary key identifier that we're looking for in the AST
		 * @var AstIdentifier
		 */
		private AstIdentifier $primaryKey;
		
		/**
		 * ContainsRange constructor.
		 * @param AstIdentifier $primaryKey The primary key identifier to search for
		 */
		public function __construct(AstIdentifier $primaryKey) {
			$this->primaryKey = $primaryKey;
		}
		
		/**
		 * Loop door de AST en gooit een exception zodra de AstIn node voor de primary key is gevonden
		 * Traverse through the AST and throw an exception as soon as the AstIn node for the primary key is found.
		 * @param AstInterface $node The current node being visited in the AST
		 * @return void
		 * @throws GetMainEntityInAstException When the target AstIn node is found
		 */
		public function visitNode(AstInterface $node): void {
			// If the node is not an AstIn instance, we're not interested in it
			if (!$node instanceof AstIn) {
				return;
			}
			
			// Check if the range name of the node's identifier matches our primary key's range name
			// If not, this is not the node we're looking for
			if ($node->getIdentifier()->getRange()->getName() !== $this->primaryKey->getRange()->getName()) {
				return;
			}
			
			// Check if the name of the node's identifier matches our primary key's name
			// If not, this is not the node we're looking for
			if ($node->getIdentifier()->getName() !== $this->primaryKey->getName()) {
				return;
			}
			
			// If we've reached this point, we've found the AstIn node for our primary key
			// Throw an exception with the found node to interrupt the traversal
			throw new GetMainEntityInAstException($node);
		}
	}