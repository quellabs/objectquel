<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
	/**
	 * Class EntityExistenceValidator
	 * Validates the existence of entities within an AST.
	 */
	class ValidateRelationInViaValid implements AstVisitorInterface {
		
		private EntityStore $entityStore;
		private string $entityName;
		
		/**
		 * ValidateRelationInViaValid constructor.
		 * @param EntityStore $entityStore
		 * @param string $entityName
		 */
		public function __construct(EntityStore $entityStore, string $entityName) {
			$this->entityStore = $entityStore;
			$this->entityName = $entityName;
		}
		
		/**
		 * Visit a node in the AST (Abstract Syntax Tree).
		 * This function is responsible for visiting a node in the AST and validating it. The type of node
		 * determines what kind of validation is performed.
		 * @param AstInterface $node The node to visit.
		 * @return void
		 * @throws QuelException
		 */
		public function visitNode(AstInterface $node): void {
			// First we check if the node is of type AstIdentifier.
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Do nothing if the identifier is part of a chain
			if (!$node->isRoot()) {
				return;
			}
			
			// Do nothing if the identifier has no properties
			if (!$node->hasNext()) {
				return;
			}
			
			// Skip validation if this identifier references a temporary table (subquery range)
			if (!$node->isFromEntity()) {
				return;  // Skip temporary tables
			}
			
			// Get all dependencies.
			$entityName = $node->getEntityName();
			$rangeName = $node->getRange()->getName();
			$propertyName = $node->getNext()->getName();
			
			$dependencies = [
				'oneToOne'  => $this->entityStore->getOneToOneDependencies($entityName),
				'manyToOne' => $this->entityStore->getManyToOneDependencies($entityName),
				'oneToMany' => $this->entityStore->getOneToManyDependencies($entityName),
			];
			
			// Loop through all dependency types.
			foreach ($dependencies as $dependency) {
				if (isset($dependency[$propertyName])) {
					$relation = $dependency[$propertyName];
					$targetEntity = $relation->getTargetEntity();
					
					if ($targetEntity !== $this->entityName) {
						throw new QuelException("Failed to join {$targetEntity} via {$rangeName}.{$propertyName} from {$this->entityName}. This is not a valid relationship path.");
					}
				}
			}
		}
	}