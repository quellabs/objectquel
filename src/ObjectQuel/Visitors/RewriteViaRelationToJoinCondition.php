<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToMany;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
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
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;
	
	/**
	 * Transforms 'via' relationship references in the AST into direct property lookups
	 * suitable for SQL JOIN condition generation.
	 */
	class RewriteViaRelationToJoinCondition implements AstVisitorInterface {
		
		/** @var EntityStore EntityStore holds entity metadata */
		private EntityStore $entityStore;
		
		/** @var AstRangeDatabase The range we are currently handling */
		private AstRangeDatabase $range;
		
		/**
		 * RewriteViaRelationToJoinCondition constructor.
		 * @param EntityStore $entityStore
		 * @param AstRangeDatabase $range
		 */
		public function __construct(EntityStore $entityStore, AstRangeDatabase $range) {
			$this->entityStore = $entityStore;
			$this->range = $range;
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
			
			// Fetch property name
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
		
		/**
		 * Creates a JOIN condition AST from a relation annotation.
		 * Resolves the correct FK/PK columns based on the relation type by dispatching
		 * to a dedicated handler for each relation type.
		 * @param AstIdentifier $joinProperty The property node (e.g. 'addresses') whose parent is the range identifier
		 * @param ManyToOne|OneToMany|OneToOne $relation The relation annotation
		 * @return AstInterface
		 * @throws TransformationException
		 * @throws EntityResolutionException
		 */
		private function createPropertyLookupAstUsingRelation(AstIdentifier $joinProperty, ManyToOne|OneToMany|OneToOne $relation): AstInterface {
			// The parent of the property node is the range identifier (e.g. 'c')
			$entity = $joinProperty->getParent();
			
			// Error when this is the root identifier
			if (!$entity instanceof AstIdentifier) {
				throw new TransformationException('Expected parent to be an AstIdentifier');
			}
			
			// Get the range object and verify it is non-null
			$range = $entity->getRange();
			
			// Error when there is no range attached
			if ($range === null) {
				throw new TransformationException('Expected parent identifier to have an attached range');
			}
			
			// Resolve the entity name for primary key fallback
			$entityName = $entity->getEntityName();
			
			// Error when the parent has no entity attached
			if ($entityName === null) {
				throw new TransformationException('Expected parent identifier to belong to an entity range');
			}
			
			// Dispatch to a dedicated handler based on the relation type
			/** @noinspection PhpSwitchCanBeReplacedWithMatchExpressionInspection */
			switch (true) {
				case $relation instanceof ManyToOne:
					return $this->createManyToOneJoinCondition($joinProperty, $relation, $range);
				
				case $relation instanceof OneToMany:
					return $this->createOneToManyJoinCondition($relation, $range, $entityName);
				
				/** @phpstan-ignore instanceof.alwaysTrue */
				case $relation instanceof OneToOne:
					return $this->createOneToOneJoinCondition($joinProperty, $relation, $range, $entityName);
				
				default:
					return throw new TransformationException('Unknown relation. Should never happen');
			}
		}
		
		/**
		 * Returns true if the given identifier targets a relation property on the given entity.
		 * @param AstIdentifier $node
		 * @param string|null $entityName Fully qualified entity name; falls back to node's own entity name
		 * @return bool
		 * @throws TransformationException
		 * @throws EntityResolutionException
		 */
		private function isRelationProperty(AstIdentifier $node, ?string $entityName = null): bool {
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
		 */
		private function createPropertyLookupAst(string $propertyA, AstRange|AstRangeDatabase|AstRangeJsonSource $rangeB, string $propertyB): AstInterface {
			// Build identifier chain for the left side: <rangeA>.<propertyA>
			// Associate the root identifier with its source range
			// Append the property lookup to the identifier chain
			$identifierA = new AstIdentifier($this->range->getName(), IdentifierType::EntityRoot);
			$identifierA->setRange($this->range);
			$identifierA->setNext(new AstIdentifier($propertyA, IdentifierType::EntityProperty));
			
			// Build identifier chain for the right side: <rangeB>.<propertyB>
			// Associate the root identifier with its source range
			// Append the property lookup to the identifier chain
			$identifierB = new AstIdentifier($rangeB->getName(), IdentifierType::EntityRoot);
			$identifierB->setRange($rangeB);
			$identifierB->setNext(new AstIdentifier($propertyB, IdentifierType::EntityProperty));
			
			// Create equality expression
			return new AstExpression($identifierA, $identifierB, '=');
		}
		
		/**
		 * Builds a JOIN condition AST for a ManyToOne relation.
		 * The FK is on $this->range (the owning/child entity); the PK is on $range (the parent entity).
		 * @param AstIdentifier $joinProperty The property node used in the via-clause (e.g. 'customer')
		 * @param ManyToOne $relation The ManyToOne annotation
		 * @param AstRange|AstRangeDatabase|AstRangeJsonSource $range The parent (target) range
		 * @return AstInterface
		 * @throws TransformationException When inversedBy is missing, or Doctrine-style inversedBy resolves to a OneToMany missing mappedBy
		 * @throws EntityResolutionException When target entity metadata cannot be loaded
		 */
		private function createManyToOneJoinCondition(AstIdentifier $joinProperty, ManyToOne $relation, AstRange|AstRangeDatabase|AstRangeJsonSource $range): AstInterface {
			// Fetch inversedBy
			$inversedBy = $relation->getInversedBy();
			
			// InversedBy is mandatory
			if ($inversedBy === null) {
				throw new TransformationException('ManyToOne relation is missing inversedBy');
			}
			
			// Resolve the FK property name and FK column for the owning side.
			// inversedBy can be used in two ways:
			//   - Legacy: a direct FK property name on $this->range (e.g. 'customerId')
			//   - Doctrine-style: a relation property name on the target entity (e.g. 'orders'),
			//     from which the FK property and column are derived via that annotation's mappedBy
			//     and relationColumn.
			[$fkProperty, $relationColumn] = $this->entityStore->resolveManyToOneFkColumn($relation, $inversedBy, $joinProperty->getName());
			
			// Return new property lookup
			return $this->createPropertyLookupAst($fkProperty, $range, $relationColumn);
		}
		
		/**
		 * Builds a JOIN condition AST for a OneToMany relation.
		 * The FK is on $this->range (the child entity); the PK is on $range (the parent entity).
		 * @param OneToMany $relation The OneToMany annotation
		 * @param AstRange|AstRangeDatabase|AstRangeJsonSource $range The parent (target) range
		 * @param string $entityName Fully qualified class name of the parent entity, used for PK fallback
		 * @return AstInterface
		 * @throws TransformationException When mappedBy is missing, or relationColumn and PK are both unresolvable
		 * @throws EntityResolutionException When entity metadata cannot be loaded
		 */
		private function createOneToManyJoinCondition(OneToMany $relation, AstRange|AstRangeDatabase|AstRangeJsonSource $range, string $entityName): AstInterface {
			// Fetch mappedBy
			$mappedBy = $relation->getMappedBy();
			
			// MappedBy is mandatory
			if ($mappedBy === null) {
				throw new TransformationException('OneToMany relation is missing mappedBy');
			}
			
			// For OneToMany, relationColumn is the PK on the parent side
			// Default to the entity's primary key
			$relationColumn = $relation->getRelationColumn() ?? $this->entityStore->getPrimaryKey($entityName) ?? throw new TransformationException('OneToMany relation is missing relationColumn and PK');
			
			// Return the new property lookup
			return $this->createPropertyLookupAst($mappedBy, $range, $relationColumn);
		}
		
		/**
		 * Builds a JOIN condition AST for a OneToOne relation.
		 * The FK can be on either side depending on whether inversedBy or mappedBy is set.
		 * @param AstIdentifier $joinProperty The property node used in the via-clause (e.g. 'profile')
		 * @param OneToOne $relation The OneToOne annotation
		 * @param AstRange|AstRangeDatabase|AstRangeJsonSource $range The related range
		 * @param string $entityName Fully qualified class name of the parent entity, used for PK fallback
		 * @return AstInterface
		 * @throws TransformationException When neither inversedBy nor mappedBy is set, or PK is unresolvable
		 * @throws EntityResolutionException When entity metadata cannot be loaded
		 */
		private function createOneToOneJoinCondition(AstIdentifier $joinProperty, OneToOne $relation, AstRange|AstRangeDatabase|AstRangeJsonSource $range, string $entityName): AstInterface {
			// OneToOne with inversedBy
			$inversedBy = $relation->getInversedBy();
			$mappedBy = $relation->getMappedBy();
			
			if (!empty($inversedBy)) {
				$relationColumn = $relation->getRelationColumn() ?? $joinProperty->getName() . 'Id';
				return $this->createPropertyLookupAst($relationColumn, $range, $inversedBy);
			}
			
			if (!empty($mappedBy)) {
				$relationColumn = $relation->getRelationColumn() ?? $this->entityStore->getPrimaryKey($entityName) ?? throw new TransformationException('OneToOne relation is missing relationColumn and PK');
				return $this->createPropertyLookupAst($relationColumn, $range, $mappedBy);
			}
			
			throw new TransformationException('OneToOne relation has neither inversedBy nor mappedBy');
		}
		
	}