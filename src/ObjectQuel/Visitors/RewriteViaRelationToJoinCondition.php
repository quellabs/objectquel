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

			// hasNext() guarantees non-null, but getNext() returns AstIdentifier|null;
			// this guard satisfies PHPStan's flow analysis without altering runtime behaviour
			if ($propertyNode === null) {
				return $side;
			}
			
			// Fetch the entity name
			$entityName = $side->getEntityName();
			
			// Not an entity range (e.g. a JSON source) — nothing to transform
			if ($entityName === null) {
				return $side;
			}
			
			// Look up the relation annotation for this property. Returns null for a plain
			// column (only relations need transforming) or for an unknown property, in which
			// case the node is left untouched. OneToOne, ManyToOne and InverseOf are all
			// considered, so via can traverse from either side of a relation.
			$relation = $this->findRelation($entityName, $propertyNode->getName());
			
			if ($relation === null) {
				return $side;
			}
			
			// When the via property is an InverseOf, resolve it to the corresponding owning-side
			// relation on the target entity. InverseOf carries no column mapping of its own —
			// the owning side (ManyToOne or OneToOne) has all the information needed to build
			// the JOIN condition. The resolved annotation is then handled identically to a
			// directly declared owning-side relation.
			$owningProperty = null;
			
			if ($relation instanceof InverseOf) {
				// Capture the owning-side property name (e.g. 'customer') before resolving.
				// This is the correct base for the localColumn default ('customerId').
				// The InverseOf property name (e.g. 'addresses') must not be used for this.
				$owningProperty = $relation->getRelation();
				$relation = $this->resolveInverseOfToOwningSide($relation);
				
				if ($relation === null) {
					throw new TransformationException(
						"InverseOf property '{$propertyNode->getName()}' on '{$entityName}' could not be resolved to " .
						"a ManyToOne or OneToOne on the target entity. Ensure the 'relation' parameter points " .
						"to a valid owning-side relation."
					);
				}
			}
			
			// Replace the relation reference with a direct FK/PK property lookup that SQL can
			// understand as a JOIN condition. $owningProperty is non-null only on the InverseOf
			// path; the dispatcher derives $fkOnJoiningRange from it to pick the FK-holding side.
			return $this->buildJoinConditionFromRelation($propertyNode, $relation, $owningProperty);
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
		 * Looks up the relation annotation declared for a property on an entity.
		 * Returns null when the property is a plain column or does not exist.
		 *
		 * OneToOne, ManyToOne and InverseOf are all considered so a 'via' clause can traverse
		 * a relation from either side. If a property name appears in more than one dependency
		 * map, precedence is InverseOf > ManyToOne > OneToOne, matching the array_merge order
		 * this lookup previously relied on.
		 * @param string $entityName Fully qualified entity name
		 * @param string $propertyName Property to resolve
		 * @return ManyToOne|OneToOne|InverseOf|null
		 * @throws EntityResolutionException
		 */
		private function findRelation(string $entityName, string $propertyName): ManyToOne|OneToOne|InverseOf|null {
			$metadata = $this->entityStore->getMetadata($entityName);
			
			return $metadata->getInverseOfDependencies()[$propertyName]
				?? $metadata->getManyToOneDependencies()[$propertyName]
				?? $metadata->getOneToOneDependencies()[$propertyName]
				?? null;
		}
		
		/**
		 * Creates a JOIN condition AST from a relation annotation by dispatching to a
		 * dedicated handler for each relation type.
		 * @param AstIdentifier $joinProperty The property node (e.g. 'addresses') whose parent is the range identifier
		 * @param ManyToOne|OneToOne $relation The owning-side relation annotation (InverseOf already resolved)
		 * @param string|null $owningProperty Owning-side property name when reached via InverseOf, null on the direct path
		 * @return AstInterface
		 * @throws TransformationException
		 * @throws EntityResolutionException
		 */
		private function buildJoinConditionFromRelation(AstIdentifier $joinProperty, ManyToOne|OneToOne $relation, ?string $owningProperty): AstInterface {
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
			
			// Error when the parent has no entity attached
			if ($entity->getEntityName() === null) {
				throw new TransformationException('Expected parent identifier to belong to an entity range');
			}
			
			// $owningProperty is set only when this relation was reached through an InverseOf.
			// On that path the FK lives on $this->range (the range being joined); on the direct
			// path it lives on $range. This single flag drives both the via-relation tagging
			// below and the column side-assignment performed by the handlers.
			$fkOnJoiningRange = $owningProperty !== null;
			
			// Tag the FK-holding (dependent) range with the owning-side relation name so the
			// hydrator can match by (entity, relation) instead of re-inspecting the rewritten
			// JOIN columns. On the InverseOf path that name is $owningProperty; on the direct
			// path it is the via-clause property itself.
			$dependentRange = $fkOnJoiningRange ? $this->range : $range;
			$dependentRange->setViaRelation($owningProperty ?? $joinProperty->getName());
			
			// Dispatch on relation type. The parameter type is ManyToOne|OneToOne, so these
			// two branches are exhaustive.
			if ($relation instanceof ManyToOne) {
				return $this->createManyToOneJoinCondition($joinProperty, $relation, $range, $owningProperty, $fkOnJoiningRange);
			}
			
			return $this->createOneToOneJoinCondition($joinProperty, $relation, $range, $owningProperty, $fkOnJoiningRange);
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
		 * Assembles the two property sides of a JOIN equality from the resolved columns,
		 * choosing which range carries the FK from $fkOnJoiningRange.
		 *
		 * On the InverseOf path the FK lives on $this->range, so the local FK column goes on
		 * side A and the referenced column on side B (e.g. a.userId = c.id). On the direct
		 * path the sides swap (e.g. u.id = p.userId).
		 * @param string $localColumn FK column on the owning/dependent entity
		 * @param string $referencedColumn Referenced (usually PK) column on the other entity
		 * @param AstRange|AstRangeDatabase|AstRangeJsonSource $range The other range
		 * @param bool $fkOnJoiningRange True when the FK is on $this->range (InverseOf path)
		 * @return AstInterface
		 */
		private function createJoinConditionAst(string $localColumn, string $referencedColumn, AstRange|AstRangeDatabase|AstRangeJsonSource $range, bool $fkOnJoiningRange): AstInterface {
			if ($fkOnJoiningRange) {
				return $this->createPropertyLookupAst($localColumn, $range, $referencedColumn);
			}
			
			return $this->createPropertyLookupAst($referencedColumn, $range, $localColumn);
		}
		
		/**
		 * Builds a JOIN condition AST for a ManyToOne relation.
		 * The FK is on $this->range (the owning/child entity); the PK is on $range (the parent entity).
		 * @param AstIdentifier $joinProperty The property node used in the via-clause (e.g. 'customer')
		 * @param ManyToOne $relation The ManyToOne annotation
		 * @param AstRange|AstRangeDatabase|AstRangeJsonSource $range The parent (target) range
		 * @param string|null $owningProperty Owning-side property name on the InverseOf path, null otherwise
		 * @param bool $fkOnJoiningRange True when the FK is on $this->range (InverseOf path)
		 * @return AstInterface
		 * @throws TransformationException
		 * @throws EntityResolutionException
		 */
		private function createManyToOneJoinCondition(AstIdentifier $joinProperty, ManyToOne $relation, AstRange|AstRangeDatabase|AstRangeJsonSource $range, ?string $owningProperty, bool $fkOnJoiningRange): AstInterface {
			// referencedColumn is the column on the target (parent) entity that the FK
			// points at — not the FK itself. When omitted, fall back to the target
			// entity's primary key.
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
			// On the InverseOf path that base is $owningProperty (the resolved owning property);
			// on the direct path it is the via-clause property itself. The InverseOf property
			// name (e.g. 'addresses') must not be used as the base.
			$localColumnBase = $owningProperty ?? $joinProperty->getName();
			$relationColumn = $relation->getLocalColumn() ?? $localColumnBase . 'Id';
			
			return $this->createJoinConditionAst($relationColumn, $referencedColumn, $range, $fkOnJoiningRange);
		}
		
		/**
		 * Builds a JOIN condition AST for a OneToOne relation.
		 * The owning side always holds the FK column; referencedColumn is always set.
		 * @param AstIdentifier $joinProperty The property node used in the via-clause (e.g. 'profile')
		 * @param OneToOne $relation The OneToOne annotation
		 * @param AstRange|AstRangeDatabase|AstRangeJsonSource $range The related range
		 * @param string|null $owningProperty Owning-side property name on the InverseOf path, null otherwise
		 * @param bool $fkOnJoiningRange True when the FK is on $this->range (InverseOf path)
		 * @return AstInterface
		 * @throws TransformationException When referencedColumn is missing
		 */
		private function createOneToOneJoinCondition(AstIdentifier $joinProperty, OneToOne $relation, AstRange|AstRangeDatabase|AstRangeJsonSource $range, ?string $owningProperty, bool $fkOnJoiningRange): AstInterface {
			// All stored OneToOne relations are owning-side; the non-owning side is declared
			// with @InverseOf and never reaches this method. referencedColumn is the column on
			// the target entity that the FK points at (usually its primary key).
			$referencedColumn = $relation->getReferencedColumn();
			
			if (empty($referencedColumn)) {
				throw new TransformationException('OneToOne relation is missing referencedColumn');
			}
			
			// localColumn is the FK column on the owning entity, defaulting to the owning-side
			// relation property name suffixed with 'Id' (e.g. 'user' -> 'userId'). On the InverseOf
			// path that base is $owningProperty; on the direct path it is the via-clause property.
			// The InverseOf property name (e.g. 'profile') must not be used as the base.
			// This mirrors the ManyToOne handler.
			$localColumnBase = $owningProperty ?? $joinProperty->getName();
			$relationColumn = $relation->getLocalColumn() ?? $localColumnBase . 'Id';
			return $this->createJoinConditionAst($relationColumn, $referencedColumn, $range, $fkOnJoiningRange);
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