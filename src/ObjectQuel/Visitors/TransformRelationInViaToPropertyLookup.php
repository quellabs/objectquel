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
		 */
		public function isRelationProperty(AstIdentifier $node, string $entityName = null): bool {
			$entityName = $entityName ?? $node->getEntityName();
			$propertyName = $node->getName();
			
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
		 */
		public function createPropertyLookupAst(string $propertyA, AstRange|AstRangeDatabase|AstRangeJsonSource $rangeB, string $propertyB): AstInterface {
			$identifierA = new AstIdentifier($this->range->getEntityName());
			$identifierA->setRange($this->range);
			$identifierA->setNext(new AstIdentifier($propertyA));
			
			$identifierB = new AstIdentifier($rangeB->getEntityName());
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
		 */
		public function createPropertyLookupAstUsingRelation(AstIdentifier $joinProperty, mixed $relation): AstInterface {
			// The parent of the property node is the range identifier (e.g. 'c')
			$entity = $joinProperty->getParent();
			
			if (!$entity instanceof AstIdentifier) {
				throw new \InvalidArgumentException('Expected parent to be an AstIdentifier');
			}
			
			// Get the range object and resolve the relation column
			$range = $entity->getRange();
			$relationColumn = $relation->getRelationColumn();
			
			// Fall back to the first primary key of the parent entity using the fully qualified name
			if ($relationColumn === null) {
				$identifierKeys = $this->entityStore->getIdentifierKeys($entity->getEntityName());
				$relationColumn = $identifierKeys[0];
			}
			
			// ManyToOne: FK is on $this->range, PK is on the parent range
			if ($relation instanceof ManyToOne) {
				return $this->createPropertyLookupAst($relationColumn, $range, $relation->getInversedBy());
			}
			
			// OneToMany: FK is on $this->range (the child), PK is on the parent range
			if ($relation instanceof OneToMany) {
				return $this->createPropertyLookupAst($relation->getMappedBy(), $range, $relationColumn);
			}
			
			// OneToOne with inversedBy
			if (!empty($relation->getInversedBy())) {
				return $this->createPropertyLookupAst($relationColumn, $range, $relation->getInversedBy());
			}
			
			// OneToOne with mappedBy
			return $this->createPropertyLookupAst($relation->getMappedBy(), $range, $relationColumn);
		}
		
		/**
		 * Processes a join property node and replaces relation references with direct
		 * property lookups. Expects a root identifier with a next node (e.g. c.addresses).
		 * @param AstInterface $side
		 * @return AstInterface
		 */
		public function processNodeSide(AstInterface $side): AstInterface {
			// Only process identifier chains with at least two segments (e.g. 'c.addresses')
			// Single identifiers or non-identifiers cannot be relation references
			if (!($side instanceof AstIdentifier) || !$side->hasNext()) {
				return $side;
			}
			
			// The relation property is on the next node (e.g. 'addresses'), not the root ('c')
			// The root node is the range alias; the next node is the property being accessed
			$propertyNode = $side->getNext();
			$entityName = $side->getEntityName();
			
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
		 */
		public function visitNode(AstInterface $node): void {
			if (!$node instanceof AstBinaryOperator) {
				return;
			}
			
			$node->setLeft($this->processNodeSide($node->getLeft()));
			$node->setRight($this->processNodeSide($node->getRight()));
		}
	}