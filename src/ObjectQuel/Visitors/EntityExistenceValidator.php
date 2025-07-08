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
	class EntityExistenceValidator implements AstVisitorInterface {
		
		/**
		 * The EntityStore for storing and fetching entity metadata.
		 * Used to validate if referenced entities actually exist in the system.
		 * @var $entityStore EntityStore
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
		 * Marks an AST node as visited to prevent re-processing.
		 * For AstIdentifier nodes, it also adds their child nodes
		 * to the visited list to ensure complete traversal.
		 *
		 * @param AstInterface $ast The AST node to mark as visited
		 * @return void
		 */
		protected function addToVisitedNodes(AstInterface $ast): void {
			// Add node to the visited list using PHP's spl_object_id to get unique identifier
			$this->visitedNodes[spl_object_id($ast)] = true;
			
			// Also add all AstIdentifier child properties for complete traversal
			if ($ast instanceof AstIdentifier) {
				if ($ast->hasNext()) {
					$this->addToVisitedNodes($ast->getNext());
				}
			}
		}
		
		/**
		 * Visits a node in the AST to validate entity existence.
		 * This method specifically handles AstIdentifier nodes,
		 * extracting entity names and validating them against the EntityStore.
		 * @param AstInterface $node The node to visit and validate
		 * @throws QuelException When an entity reference doesn't exist in the store
		 */
		public function visitNode(AstInterface $node): void {
			if ($node instanceof AstIdentifier) {
				// Generate a unique hash for the object to prevent duplicate processing
				$objectHash = spl_object_id($node);
				
				// Skip already visited nodes to prevent infinite loops in cyclic ASTs
				if (isset($this->visitedNodes[$objectHash])) {
					return;
				}
				
				// Mark the current node as visited
				$this->visitedNodes[$objectHash] = true;
				
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
	}