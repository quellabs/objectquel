<?php
	
	// Namespace declaration for structured code
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	// Import the required classes and interfaces
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class RetrieveEntities
	 * Implements AstVisitor to collect entities from an AST.
	 */
	class QuelToSQLFetchEntities implements AstVisitorInterface {
		
		// Array to store collected entities
		private array $entities;
		
		/**
		 * Constructor to initialize the entities array.
		 */
		public function __construct() {
			$this->entities = [];
		}
		
		/**
		 * Add an entity to the list if it doesn't exist yet.
		 * @param AstIdentifier $entity The entity that might be added.
		 * @return void
		 */
		protected function addEntityIfNotExists(AstIdentifier $entity): void {
			// Loop through all existing entities to check for duplicates
			foreach($this->entities as $e) {
				// If an entity with the same data already exists, exit the function early
				if (
					$e->getRange() instanceof AstRangeDatabase &&
					($e->getName() == $entity->getEntityName()) &&
					($e->getRange() == $entity->getRange())
				) {
					return;
				}
			}
			
			// Add the new entity to the list
			$this->entities[] = $entity;
		}
		
		/**
		 * Visit a node in the AST.
		 * @param AstInterface $node The node to visit.
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			// Check if the node is an entity and add it to the array
			if ($node instanceof AstIdentifier) {
				$this->addEntityIfNotExists($node);
			}
		}
		
		/**
		 * Get the collected entities.
		 * @return AstIdentifier[] The collected entities.
		 */
		public function getEntities(): array {
			return $this->entities;
		}
	}