<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
	/**
	 * Class EntityExistenceValidator
	 * Validates the existence of entities within an AST (Abstract Syntax Tree).
	 * This visitor traverses the AST and verifies that all entity references
	 * actually exist in the EntityStore.
	 */
	class EntityReferenceValidator implements AstVisitorInterface {
		
		/**
		 * The EntityStore for storing and fetching entity metadata.
		 * Used to validate if referenced entities actually exist in the system.
		 */
		private EntityStore $entityStore;
		
		/**
		 * @var array Tracks already visited nodes to prevent infinite loops
		 * during AST traversal. Keys are object IDs, values are boolean true.
		 */
		private array $visitedNodes;
		
		/**
		 * EntityExistenceValidator constructor.
		 * Initializes the validator with the entity store to be used for validation.
		 * @param EntityStore $entityStore Repository of entity metadata
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
			$this->visitedNodes = [];
		}

		/**
		 * Visits a node in the AST to validate entity existence.
		 * This method specifically handles AstIdentifier nodes,
		 * extracting entity names and validating them against the EntityStore.
		 * @param AstInterface $node The node to visit and validate
		 * @throws QuelException When an entity reference doesn't exist in the store
		 */
		public function visitNode(AstInterface $node): void {
			// Only handle AstIdentifier nodes
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Generate a unique hash for the object to prevent duplicate processing
			$objectHash = spl_object_hash($node);
			
			// Skip already visited nodes to prevent infinite loops in cyclic ASTs
			if (isset($this->visitedNodes[$objectHash])) {
				return;
			}
			
			// Mark the current node as visited
			$this->visitedNodes[$objectHash] = true;
			
			// Check if the node is attached to an entity
			if (!$node->isFromEntity()) {
				return;
			}
			
			// Extract the entity name from the identifier node
			$entityName = $node->getEntityName();
			
			// Skip validation if no entity name is specified
			if ($entityName === null) {
				return;
			}
			
			// Validate entity existence in the entity store
			// Throw an exception with detailed error message if entity doesn't exist
			if (!$this->entityStore->exists($entityName)) {
				throw new QuelException("The entity or range {$entityName} referenced in the query does not exist. Please check the query for incorrect references and ensure all specified entities or ranges are correctly defined.");
			}
		}
	}