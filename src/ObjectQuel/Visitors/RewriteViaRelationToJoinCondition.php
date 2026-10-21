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
	use Quellabs\ObjectQuel\ObjectQuel\Ast\IdentifierType;
	use Quellabs\ObjectQuel\Metadata\RelationColumnMapping;
	use Quellabs\ObjectQuel\Metadata\RelationColumnPair;

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
			
			// When the via property is an InverseOf, the owning side's resolved column
			// mapping lives on the target entity instead, under the owning relation's
			// own property name (e.g. 'customer') — captured here, since the InverseOf
			// property name itself (e.g. 'addresses') is never a valid mapping key.
			// InverseOf carries no column mapping of its own; buildJoinConditionFromRelation()
			// resolves and validates the owning-side mapping from this property name.
			$owningProperty = $relation instanceof InverseOf ? $relation->getRelation() : null;

			// Replace the relation reference with a JOIN condition built from the
			// relation's fully resolved column-pair mapping. $owningProperty is non-null
			// only on the InverseOf path; it drives both which entity's mapping to look
			// up and which range holds the FK.
			return $this->buildJoinConditionFromRelation($propertyNode, $owningProperty);
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
		 * Creates a JOIN condition AST from a relation's fully resolved column-pair
		 * mapping — an AND of one equality per pair, built identically regardless of
		 * whether the relation is ManyToOne, OneToOne, single-column, or composite.
		 * @param AstIdentifier $joinProperty The property node (e.g. 'addresses') whose parent is the range identifier
		 * @param string|null $owningProperty Owning-side property name when reached via InverseOf, null on the direct path
		 * @return AstInterface
		 * @throws TransformationException
		 * @throws EntityResolutionException
		 */
		private function buildJoinConditionFromRelation(AstIdentifier $joinProperty, ?string $owningProperty): AstInterface {
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
			$entityName = $entity->getEntityName();

			if ($entityName === null) {
				throw new TransformationException('Expected parent identifier to belong to an entity range');
			}

			// $owningProperty is set only when this relation was reached through an InverseOf.
			// On that path the FK lives on $this->range (the range being joined); on the direct
			// path it lives on $range. This single flag drives both the via-relation tagging
			// below and the column side-assignment of the generated condition.
			$fkOnJoiningRange = $owningProperty !== null;

			// Tag the FK-holding (dependent) range with the owning-side relation name so the
			// hydrator can match by (entity, relation) instead of re-inspecting the rewritten
			// JOIN columns. On the InverseOf path that name is $owningProperty; on the direct
			// path it is the via-clause property itself.
			//
			// Only tag when unset: a chained via-hop off an already-joined dependent range
			// (e.g. a second hop through a range reached via InverseOf) would otherwise
			// overwrite that range's original tag and break its InverseOf match.
			$dependentRange = $fkOnJoiningRange ? $this->range : $range;

			if ($dependentRange->getViaRelation() === null) {
				$dependentRange->setViaRelation($owningProperty ?? $joinProperty->getName());
			}

			// The resolved column-pair mapping always lives on the entity that owns the
			// relation (the ManyToOne/OneToOne side), keyed by that entity's own property
			// name for it. On the InverseOf path that's $this->range's entity and
			// $owningProperty; on the direct path it's this via-clause's own entity/property.
			if ($fkOnJoiningRange) {
				$ownerEntityName = $this->range->getEntityName();
				$ownerProperty = $owningProperty;
			} else {
				$ownerEntityName = $entityName;
				$ownerProperty = $joinProperty->getName();
			}

			$mapping = $this->entityStore->getMetadata($ownerEntityName)->getRelationColumnMapping($ownerProperty);

			if ($mapping === null) {
				throw new TransformationException(
					"Relation '{$ownerProperty}' on '{$ownerEntityName}' has no resolved column mapping; " .
					"expected a ManyToOne or OneToOne annotation there."
				);
			}

			return $this->createJoinConditionFromMapping($mapping, $range, $fkOnJoiningRange);
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
		 * Builds a JOIN condition AST from a relation's resolved column-pair mapping:
		 * one equality per pair, ANDed together — a single equality for a plain
		 * single-column relation, same as before composite support existed.
		 *
		 * On the InverseOf path the FK lives on $this->range, so each pair's local
		 * property goes on side A and its target property on side B (e.g. a.userId =
		 * c.id). On the direct path the sides swap (e.g. u.id = p.userId).
		 * @param RelationColumnMapping $mapping Resolved pairs for this relation
		 * @param AstRange|AstRangeDatabase|AstRangeJsonSource $range The other range
		 * @param bool $fkOnJoiningRange True when the FK is on $this->range (InverseOf path)
		 * @return AstInterface
		 */
		private function createJoinConditionFromMapping(RelationColumnMapping $mapping, AstRange|AstRangeDatabase|AstRangeJsonSource $range, bool $fkOnJoiningRange): AstInterface {
			$pairs = $mapping->pairs;
			$condition = $this->createPairConditionAst($pairs[0], $range, $fkOnJoiningRange);

			for ($i = 1, $count = count($pairs); $i < $count; $i++) {
				$condition = new AstBinaryOperator($condition, $this->createPairConditionAst($pairs[$i], $range, $fkOnJoiningRange), 'AND');
			}

			return $condition;
		}

		/**
		 * Builds the single equality AST for one resolved column pair, choosing which
		 * range carries the FK from $fkOnJoiningRange.
		 * @param RelationColumnPair $pair
		 * @param AstRange|AstRangeDatabase|AstRangeJsonSource $range The other range
		 * @param bool $fkOnJoiningRange True when the FK is on $this->range (InverseOf path)
		 * @return AstInterface
		 */
		private function createPairConditionAst(RelationColumnPair $pair, AstRange|AstRangeDatabase|AstRangeJsonSource $range, bool $fkOnJoiningRange): AstInterface {
			if ($fkOnJoiningRange) {
				return $this->createPropertyLookupAst($pair->localProperty, $range, $pair->targetProperty);
			}

			return $this->createPropertyLookupAst($pair->targetProperty, $range, $pair->localProperty);
		}
	}