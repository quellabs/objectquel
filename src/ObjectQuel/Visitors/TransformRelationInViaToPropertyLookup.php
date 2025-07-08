<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToMany;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class TransformRelationInViaToPropertyLookup
	 * Validates the existence of entities within an AST.
	 */
	class TransformRelationInViaToPropertyLookup implements AstVisitorInterface {
		
		private EntityStore $entityStore;
		private AstRangeDatabase $range;
		
		/**
		 * TransformRelationInViaToPropertyLookup constructor.
		 * @param EntityStore $entityStore
		 * @param AstRangeDatabase $range
		 */
		public function __construct(EntityStore $entityStore, AstRangeDatabase $range) {
			$this->entityStore = $entityStore;
			$this->range = $range;
		}
		
		/**
		 * Returns true if the given identifier targets a relation, false if not
		 * @param AstIdentifier $node
		 * @return bool
		 */
		public function isRelationProperty(AstIdentifier $node): bool {
			$entityName = $node->getEntityName();
			$propertyName = $node->getName();
			
			return array_key_exists($propertyName, array_merge(
				$this->entityStore->getOneToOneDependencies($entityName),
				$this->entityStore->getManyToOneDependencies($entityName),
				$this->entityStore->getOneToManyDependencies($entityName)
			));
		}
		
		/**
		 * Returns a new expression
		 * @param string $propertyA
		 * @param AstRangeDatabase $rangeB
		 * @param string $propertyB
		 * @return AstExpression
		 */
		public function createPropertyLookupAst(string $propertyA, AstRange $rangeB, string $propertyB): AstInterface {
			$identifierA = new AstIdentifier($this->range->getEntityName());
			$identifierA->setNext(new AstIdentifier($propertyA));
			
			$identifierB = new AstIdentifier($rangeB->getEntityName());
			$identifierB->setNext(new AstIdentifier($propertyB));
			
			return new AstExpression($identifierA, $identifierB, '=');
		}
		
		/**
		 * Creates a Property Lookup AST (Abstract Syntax Tree) using a relation.
		 * @param AstIdentifier $joinProperty The join property with which the relation is made.
		 * @param mixed $relation The relation used to create the property lookup.
		 * @return AstInterface The generated AST object.
		 */
		public function createPropertyLookupAstUsingRelation(AstIdentifier $joinProperty, mixed $relation): AstInterface {
			// Get the entity and range from the join property
			$entity = $joinProperty->getParent();
			$range = $entity->getRange();
			$relationColumn = $relation->getRelationColumn();
			
			// If the relation column is null, use the first identifier key of the entity
			if ($relationColumn === null) {
				$identifierKeys = $this->entityStore->getIdentifierKeys($entity->getName());
				$relationColumn = $identifierKeys[0];
			}
			
			// For ManyToOne relations, use the 'inversedBy' value
			if ($relation instanceof ManyToOne) {
				return $this->createPropertyLookupAst($relationColumn, $range, $relation->getInversedBy());
			}
			
			// For OneToMany relations, use the 'mappedBy' value
			if ($relation instanceof OneToMany) {
				return $this->createPropertyLookupAst($relationColumn, $range, $relation->getMappedBy());
			}
			
			// For relations with an 'inversedBy' value, use this
			if (!empty($relation->getInversedBy())) {
				return $this->createPropertyLookupAst($relationColumn, $range, $relation->getInversedBy());
			}
			
			// For all other cases, use the 'mappedBy' value
			return $this->createPropertyLookupAst($relationColumn, $range, $relation->getMappedBy());
		}
		
		/**
		 * Processes a single side (left or right) of the node and adjusts it if necessary.
		 * If the side is an AstIdentifier and matches a relation property, it is
		 * replaced by a new node that represents the relation. Otherwise, the original
		 * side is returned unchanged.
		 * @param AstInterface $side The left or right side of the node to be processed.
		 * @return AstInterface The original or modified side of the node.
		 */
		public function processNodeSide(AstInterface $side): AstInterface {
			// Check if the side is an AstIdentifier and represents a relation property.
			if (!($side instanceof AstIdentifier) || !$this->isRelationProperty($side)) {
				return $side; // No adjustments needed if it's not an AstIdentifier or not a relation property.
			}
			
			// Get the entity name and property name from the side.
			$entityName = $side->getEntityName();
			$propertyName = $side->getName();
			
			// Combine all relation dependencies for the given entity name.
			$relations = array_merge(
				$this->entityStore->getOneToOneDependencies($entityName),
				$this->entityStore->getManyToOneDependencies($entityName),
				$this->entityStore->getOneToManyDependencies($entityName)
			);
			
			// Check if the property name exists in the relations before using it.
			if (!isset($relations[$propertyName])) {
				return $side; // No adjustments needed if the property doesn't exist in the relations.
			}
			
			// Replace the side with a new node that represents the relation.
			return $this->createPropertyLookupAstUsingRelation($side, $relations[$propertyName]);
		}
		
		/**
		 * Visit a node in the AST (Abstract Syntax Tree).
		 * This function is responsible for visiting a node in the AST and validating it. The type of node
		 * determines what kind of validation is performed.
		 * @param AstInterface $node The node to visit.
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			// If the node is not of type AstBinaryOperator, we do nothing.
			if (!$node instanceof AstBinaryOperator) {
				return;
			}
			
			// Process and update the left side of the node if necessary
			$node->setLeft($this->processNodeSide($node->getLeft()));
			
			// Process and update the right side of the node if necessary
			$node->setRight($this->processNodeSide($node->getRight()));
		}
	}