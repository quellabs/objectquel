<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class AddNamespacesToRanges
	 */
	class AddNamespacesToRanges implements AstVisitorInterface {
		
		/**
		 * The EntityStore for storing and fetching entity metadata.
		 */
		private EntityStore $entityStore;
		
		/**
		 * EntityExistenceValidator constructor.
		 * @param EntityStore $entityStore
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Function to visit a node in the AST (Abstract Syntax Tree).
		 * @param AstInterface $node
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			// Checks if the node is an instance of AstIdentifier. If not, the function stops.
			if (!$node instanceof AstRangeDatabase) {
				return;
			}
			
			// If none of the above checks are true, the function adds a namespace
			// to the name of the node. This is done by a method of the entityStore object.
			$node->setEntityName($this->entityStore->normalizeEntityName($node->getEntityName()));
		}
	}