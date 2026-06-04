<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;
	
	/**
	 * Class EntityPropertyValidator
	 * Validates the existence of properties and methods within entities
	 */
	class ValidateEntityPropertyExists implements AstVisitorInterface {
		
		/** @var EntityStore EntityStore holds entity metadata */
		private EntityStore $entityStore;
		
		/**
		 * EntityPropertyValidator constructor.
		 * @param EntityStore $entityStore
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Visit a node in the AST (Abstract Syntax Tree).
		 * This function is responsible for visiting a node in the AST and validating it. The type of node
		 * determines what kind of validation is performed.
		 * @param AstInterface $node The node to visit.
		 * @return void
		 * @throws SemanticException Thrown when validation fails.
		 * @throws EntityResolutionException
		 */
		public function visitNode(AstInterface $node): void {
			// Validate the property if the node is of type AstIdentifier.
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// If this is not a property, do nothing
			if ($node->getType() !== IdentifierType::EntityProperty) {
				return;
			}
			
			// Fetch the parent. This will always be an entity right now
			// We still have to check for AstIdentifier to please phpstan
			$parentNode = $node->getParent();
			
			if (!$parentNode instanceof AstIdentifier) {
				throw new SemanticException("Parent should be AstIdentifier but isn't");
			}
			
			// Check if the parent is the EntityRoot. Should always be the case.
			if ($parentNode->getType() !== IdentifierType::EntityRoot) {
				throw new SemanticException("Parent should be EntityRoot but isn't");
			}
			
			// Fetch the entity name
			$entityName = $parentNode->getEntityName();
			
			// Throw if none was attached. Never happens, but again, to please phpstan
			if ($entityName === null) {
				throw new SemanticException("Missing entity name in AstIdentifier property");
			}
			
			// Fetch column map and relations
			$propertyName = $node->getName();
			$metadata = $this->entityStore->getMetadata($entityName);
			$relations = $metadata->getInverseOfRelations();
			
			// Check if the property exists in the entity.
			if (!isset($metadata->columnMap[$propertyName]) && !isset($relations[$propertyName])) {
				throw new SemanticException("The property {$propertyName} does not exist in entity {$entityName}. Please check for typos or verify that the correct entity is being referenced in the query.");
			}
		}
	}