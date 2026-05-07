<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToMany;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Transforms 'via' relationship references in the AST into direct property lookups
	 * suitable for SQL JOIN condition generation.
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
		 * Returns true if the given identifier targets a relation property on the given entity.
		 * @param AstIdentifier $node
		 * @param string|null $entityName Fully qualified entity name; falls back to node's own entity name
		 * @return bool
		 * @throws TransformationException
		 * @throws EntityResolutionException
		 */
		public function isRelationProperty(AstIdentifier $node, string $entityName = null): bool {
			// Fetch the entity name
			$entityName = $entityName ?? $node->getEntityName();
			
			// If none passed, this is not a relation property
			if ($entityName === null) {
				return false;
			}
			
			// Fetch property name from node
			$propertyName = $node->getName();
			
			// Check if the property is a key in any of the dependencies
			return array_key_exists($propertyName, array_merge(
				$this->entityStore->getOneToOneDependencies($entityName),
				$this->entityStore->getManyToOneDependencies($entityName),
				$this->entityStore->getOneToManyDependencies($entityName)
			));
		}
		
		/**
		 * Builds an AstExpression representing a direct property-to-property JOIN condition.
		 * Side A is always on $this->range (the joining entity), side B is on $rangeB.
		 * @param string $propertyA Property name on $this->range
		 * @param AstRange|AstRangeDatabase|AstRangeJsonSource $rangeB The other range
		 * @param string $propertyB Property name on $rangeB
		 * @return AstInterface
		 * @throws TransformationException
		 */
		public function createPropertyLookupAst(string $propertyA, AstRange|AstRangeDatabase|AstRangeJsonSource $rangeB, string $propertyB): AstInterface {
			$entityNameA = $this->range->getEntityName();
			
			if ($entityNameA === null) {
				throw new TransformationException('Range A has no entity name');
			}
			
			$entityNameB = $rangeB->getEntityName();
			
			if ($entityNameB === null) {
				throw new TransformationException('Range B has no entity name');
			}
			
			$identifierA = new AstIdentifier($entityNameA);
			$identifierA->setRange($this->range);
			$identifierA->setNext(new AstIdentifier($propertyA));
			
			$identifierB = new AstIdentifier($entityNameB);
			$identifierB->setRange($rangeB);
			$identifierB->setNext(new AstIdentifier($propertyB));
			
			return new AstExpression($identifierA, $identifierB, '=');
		}
		
		/**
		 * Creates a JOIN condition AST from a relation annotation.
		 * Resolves the correct FK/PK columns based on the relation type.
		 * @param AstIdentifier $joinProperty The property node (e.g. 'addresses') whose parent is the range identifier
		 * @param mixed $relation The relation annotation
		 * @return AstInterface
		 * @throws TransformationException
		 * @throws EntityResolutionException
		 */
		public function createPropertyLookupAstUsingRelation(AstIdentifier $joinProperty, mixed $relation): AstInterface {
			// The parent of the property node is the range identifier (e.g. 'c')
			$entity = $joinProperty->getParent();
			
			if (!$entity instanceof AstIdentifier) {
				throw new TransformationException('Expected parent to be an AstIdentifier');
			}
			
			// Get the range object and verify it is non-null
			$range = $entity->getRange();
			
			if ($range === null) {
				throw new TransformationException('Expected parent identifier to have an attached range');
			}
			
			// Resolve the entity name for primary key fallback
			$entityName = $entity->getEntityName();
			
			if ($entityName === null) {
				throw new TransformationException('Expected parent identifier to belong to an entity range');
			}
			
			// Fall back to the first primary key of the parent entity
			$relationColumn = $relation->getRelationColumn();
			
			if ($relationColumn === null) {
				// Infer FK property name from the relation property name
				// e.g. property "user" → FK property "userId"
				$propertyName = $joinProperty->getName();  // "user"
				$relationColumn = $propertyName . 'Id';    // "userId"
			}
			
			// ManyToOne: FK is on $this->range, PK is on the parent range
			if ($relation instanceof ManyToOne) {
				$inversedBy = $relation->getInversedBy();
				
				if ($inversedBy === null) {
					throw new TransformationException('ManyToOne relation is missing inversedBy');
				}
				
				return $this->createPropertyLookupAst($inversedBy, $range, $relationColumn);
			}
			
			// OneToMany: FK is on $this->range (the child), PK is on the parent range
			if ($relation instanceof OneToMany) {
				$mappedBy = $relation->getMappedBy();
				
				if ($mappedBy === null) {
					throw new TransformationException('OneToMany relation is missing mappedBy');
				}
				
				return $this->createPropertyLookupAst($mappedBy, $range, $relationColumn);
			}
			
			// OneToOne with inversedBy
			$inversedBy = $relation->getInversedBy();
			
			if (!empty($inversedBy)) {
				return $this->createPropertyLookupAst($relationColumn, $range, $inversedBy);
			}
			
			// OneToOne with mappedBy
			$mappedBy = $relation->getMappedBy();
			
			if ($mappedBy === null) {
				throw new TransformationException('OneToOne relation has neither inversedBy nor mappedBy');
			}
			
			return $this->createPropertyLookupAst($mappedBy, $range, $relationColumn);
		}
		
		/**
		 * Processes a join property node and replaces relation references with direct
		 * property lookups. Expects a root identifier with a next node (e.g. c.addresses).
		 * @param AstInterface $side
		 * @return AstInterface
		 * @throws TransformationException
		 * @throws EntityResolutionException
		 */
		public function processNodeSide(AstInterface $side): AstInterface {
			// Only process identifier chains with at least two segments (e.g. 'c.addresses')
			// Single identifiers or non-identifiers cannot be relation references
			if (!($side instanceof AstIdentifier) || !$side->hasNext()) {
				return $side;
			}
			
			// The relation property is on the next node (e.g. 'addresses'), not the root ('c')
			// getNext() is guaranteed non-null here because hasNext() returned true
			$propertyNode = $side->getNext();
			
			// Validate property node
			if ($propertyNode === null) {
				return $side;
			}
			
			// Fetch the entity name
			$entityName = $side->getEntityName();
			
			// Not an entity range (e.g. a JSON source) — nothing to transform
			if ($entityName === null) {
				return $side;
			}
			
			// Check if the property refers to a relation (OneToOne, ManyToOne, OneToMany)
			// rather than a regular column — only relations need to be transformed
			if (!$this->isRelationProperty($propertyNode, $entityName)) {
				return $side;
			}
			
			$propertyName = $propertyNode->getName();
			
			// Collect all relation types for this entity into a single flat map
			// keyed by property name so we can look up the annotation directly
			$relations = array_merge(
				$this->entityStore->getOneToOneDependencies($entityName),
				$this->entityStore->getManyToOneDependencies($entityName),
				$this->entityStore->getOneToManyDependencies($entityName)
			);
			
			// Safeguard: isRelationProperty confirmed it exists, but verify before using
			if (!isset($relations[$propertyName])) {
				return $side;
			}
			
			// Replace the relation reference with a direct FK/PK property lookup
			// that SQL can understand as a JOIN condition
			return $this->createPropertyLookupAstUsingRelation($propertyNode, $relations[$propertyName]);
		}
		
		/**
		 * Visits a binary operator node and transforms any relation references on
		 * either side into direct property lookups.
		 * @param AstInterface $node
		 * @return void
		 * @throws TransformationException|EntityResolutionException
		 */
		public function visitNode(AstInterface $node): void {
			if (!$node instanceof AstBinaryOperator) {
				return;
			}
			
			$node->setLeft($this->processNodeSide($node->getLeft()));
			$node->setRight($this->processNodeSide($node->getRight()));
		}
	}