<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\InverseOf;
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
			
			// Check if the property refers to a relation (OneToOne, ManyToOne)
			// rather than a regular column — only relations need to be transformed
			if (!$this->isRelationProperty($propertyNode, $entityName)) {
				return $side;
			}
			
			// Fetch property name
			$propertyName = $propertyNode->getName();
			
			// Fetch metadata
			$metadata = $this->entityStore->getMetadata($entityName);
			
			// Collect all relation types for this entity into a single flat map
			// keyed by property name so we can look up the annotation directly.
			// InverseOf is included so via can traverse from either side of a relation.
			$relations = array_merge(
				$metadata->getOneToOneDependencies(),
				$metadata->getManyToOneDependencies(),
				$metadata->getInverseOfDependencies(),
			);
			
			// Safeguard: isRelationProperty confirmed it exists, but verify before using
			if (!isset($relations[$propertyName])) {
				return $side;
			}
			
			// When the via property is an InverseOf, resolve it to the corresponding owning-side
			// relation on the target entity. InverseOf carries no column mapping of its own —
			// the owning side (ManyToOne or OneToOne) has all the information needed to build
			// the JOIN condition. The resolved annotation is then handled identically to a
			// directly declared owning-side relation.
			$relation = $relations[$propertyName];
			$joinPropertyName = null;
			
			if ($relation instanceof InverseOf) {
				// Capture the owning-side property name (e.g. 'customer') before resolving.
				// This is the correct base for the localColumn default ('customerId').
				// The InverseOf property name (e.g. 'addresses') must not be used for this.
				$joinPropertyName = $relation->getRelation();
				$relation = $this->resolveInverseOfToOwningSide($relation);
				
				if ($relation === null) {
					throw new TransformationException(
						"InverseOf property '{$propertyName}' on '{$entityName}' could not be resolved to " .
						"a ManyToOne or OneToOne on the target entity. Ensure the 'relation' parameter points " .
						"to a valid owning-side relation."
					);
				}
			}
			
			// Replace the relation reference with a direct FK/PK property lookup
			// that SQL can understand as a JOIN condition
			return $this->createPropertyLookupAstUsingRelation($propertyNode, $relation, $joinPropertyName ?? null);
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
		 * @param ManyToOne|OneToOne $relation The relation annotation
		 * @return AstInterface
		 * @throws TransformationException
		 */
		private function createPropertyLookupAstUsingRelation(AstIdentifier $joinProperty, ManyToOne|OneToOne $relation, ?string $joinPropertyName = null): AstInterface {
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
					return $this->createManyToOneJoinCondition($joinProperty, $relation, $range, $joinPropertyName);
				
				/** @phpstan-ignore instanceof.alwaysTrue */
				case $relation instanceof OneToOne:
					return $this->createOneToOneJoinCondition($joinProperty, $relation, $range, $joinPropertyName);
				
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
			
			// Fetch metadata
			$metadata = $this->entityStore->getMetadata($entityName);
			
			// Check if the property is a key in any of the dependencies.
			// InverseOf must be included here — processNodeSide handles it by resolving
			// it to the owning-side annotation, but the gate check must agree with that map.
			return array_key_exists($propertyName, array_merge(
				$metadata->getOneToOneDependencies(),
				$metadata->getManyToOneDependencies(),
				$metadata->getInverseOfDependencies(),
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
		 * @throws TransformationException
		 * @throws EntityResolutionException
		 */
		private function createManyToOneJoinCondition(AstIdentifier $joinProperty, ManyToOne $relation, AstRange|AstRangeDatabase|AstRangeJsonSource $range, ?string $joinPropertyName = null): AstInterface {
			// referencedColumn is the FK property on the owning (child) entity.
			// When omitted, fall back to the primary key of the target entity.
			$referencedColumn = $relation->getReferencedColumn();
			
			if ($referencedColumn === null) {
				$targetEntity = $relation->getTargetEntity();
				$referencedColumn = $this->entityStore->getMetadata($targetEntity)->getPrimaryKey();
				
				if ($referencedColumn === null) {
					throw new TransformationException(
						"ManyToOne relation on '{$joinProperty->getName()}' has no referencedColumn and " .
						"target entity '{$targetEntity}' has no primary key to fall back on."
					);
				}
			}
			
			// localColumn is the FK property on the owning (child) entity, defaulting to the
			// owning-side relation property name suffixed with 'Id' (e.g. 'customer' -> 'customerId').
			$effectiveName = $joinPropertyName !== null ? $joinPropertyName : $joinProperty->getName();
			$relationColumn = $relation->getLocalColumn() ?? $effectiveName . 'Id';
			
			// When arriving via an InverseOf ($joinPropertyName is set), $this->range is the owning/child
			// entity (e.g. 'a' in "range of a via c.posts"), so the FK ($relationColumn) is on $this->range
			// and the PK ($referencedColumn) is on $range.
			// When arriving via a direct ManyToOne ($joinPropertyName is empty), $range is the owning/child
			// entity (e.g. 'p' in "range of u via p.user"), so the FK is on $range and the PK is on
			// $this->range. The sides are therefore swapped relative to the InverseOf case.
			if ($joinPropertyName !== null) {
				// InverseOf path: FK ($relationColumn) is on $this->range (the range being joined, e.g. a.userId = c.id)
				return $this->createPropertyLookupAst($relationColumn, $range, $referencedColumn);
			}

			// Direct ManyToOne path: FK ($relationColumn) is on $range (the via-source entity, e.g. u.id = p.userId)
			return $this->createPropertyLookupAst($referencedColumn, $range, $relationColumn);
		}
		
		/**
		 * Builds a JOIN condition AST for a OneToOne relation.
		 * The owning side always holds the FK column; inversedBy is always set.
		 * @param AstIdentifier $joinProperty The property node used in the via-clause (e.g. 'profile')
		 * @param OneToOne $relation The OneToOne annotation
		 * @param AstRange|AstRangeDatabase|AstRangeJsonSource $range The related range
		 * @return AstInterface
		 * @throws TransformationException When inversedBy is missing
		 */
		private function createOneToOneJoinCondition(AstIdentifier $joinProperty, OneToOne $relation, AstRange|AstRangeDatabase|AstRangeJsonSource $range, ?string $joinPropertyName = null): AstInterface {
			// All stored OneToOne relations are owning-side — inversedBy is always set.
			// The non-owning side is declared with @InverseOf and never reaches this method.
			$inversedBy = $relation->getReferencedColumn();
			
			if (empty($inversedBy)) {
				throw new TransformationException('OneToOne relation is missing inversedBy');
			}
			
			$relationColumn = $relation->getLocalColumn() ?? $joinProperty->getName() . 'Id';
			
			// Same side-assignment rule as ManyToOne: InverseOf path puts FK on $this->range;
			// direct OneToOne path puts FK on $range (the owning entity in the via clause).
			if ($joinPropertyName !== null && $joinPropertyName !== '') {
				// InverseOf path: e.g. a.profileId = c.id
				return $this->createPropertyLookupAst($relationColumn, $range, $inversedBy);
			}
			
			// Direct OneToOne path: e.g. p.profileId = u.id
			return $this->createPropertyLookupAst($inversedBy, $range, $relationColumn);
		}
		
		/**
		 * Resolves an InverseOf annotation to the owning-side ManyToOne or OneToOne annotation
		 * on the target entity. InverseOf carries no column mapping — the owning side has all
		 * the information needed to build a JOIN condition.
		 * Returns null if the named relation property does not exist or is not an owning-side relation.
		 * @param InverseOf $inverseOf
		 * @return ManyToOne|OneToOne|null
		 * @throws EntityResolutionException
		 */
		private function resolveInverseOfToOwningSide(InverseOf $inverseOf): ManyToOne|OneToOne|null {
			// Resolve the fully qualified target entity class
			$targetEntity = $this->entityStore->normalizeEntityClass($inverseOf->getTargetEntity());
			
			// The relation parameter names the property on the target entity that owns the FK.
			// getRelation() always returns a non-empty string — the constructor enforces this.
			$relationProperty = $inverseOf->getRelation();
			
			// Look up the owning-side annotation on the target entity
			$targetMetadata = $this->entityStore->getMetadata($targetEntity);
			
			return $targetMetadata->getManyToOneDependencies()[$relationProperty]
				?? $targetMetadata->getOneToOneDependencies()[$relationProperty]
				?? null;
		}
	}