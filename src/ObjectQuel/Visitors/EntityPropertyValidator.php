<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
	/**
	 * Class EntityPropertyValidator
	 * Validates the existence of properties and methods within entities
	 */
	class EntityPropertyValidator implements AstVisitorInterface {
		
		private EntityStore $entityStore;
		
		/**
		 * EntityPropertyValidator constructor.
		 * @param EntityStore $entityStore
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Validate the property of a given entity.
		 * This function checks if a given property name exists in the column map of a specified entity.
		 * @param string $entityName The name of the entity.
		 * @param string $propertyName The name of the property to validate.
		 * @throws QuelException Thrown when the property does not exist in the given entity.
		 */
		protected function validateProperty(string $entityName, string $propertyName): void {
			// Get the column map for this entity.
			$columnMap = $this->entityStore->getColumnMap($entityName);
			
			// Check if the property exists in the entity.
			if (!isset($columnMap[$propertyName])) {
				throw new QuelException("The property {$propertyName} does not exist in entity {$entityName}. Please check for typos or verify that the correct entity is being referenced in the query.");
			}
		}
		
		/**
		 * Visit a node in the AST (Abstract Syntax Tree).
		 * This function is responsible for visiting a node in the AST and validating it. The type of node
		 * determines what kind of validation is performed.
		 * @param AstInterface $node The node to visit.
		 * @return void
		 * @throws QuelException Thrown when validation fails.
		 */
		public function visitNode(AstInterface $node): void {
			// Validate the property if the node is of type AstIdentifier.
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// If this is not a property, do nothing
			if (!$node->hasParent() || !$node->getParent() instanceof AstIdentifier) {
				return;
			}
			
			// If there's no entity name, do nothing
			if ($node->getParent()->getEntityName() === null) {
				return;
			}
			
			// Validate the property
			$this->validateProperty($node->getParent()->getEntityName(), $node->getName());
		}
	}