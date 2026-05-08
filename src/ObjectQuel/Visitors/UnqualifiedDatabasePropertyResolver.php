<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\TransformationException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;
	
	/**
	 * Resolves unqualified property names to their fully-qualified range-prefixed form.
	 *
	 * Allows queries like:
	 *   retrieve (name)
	 * as shorthand for:
	 *   retrieve (p.name)
	 *
	 * When a bare identifier (no range prefix, no chained property) is encountered,
	 * this visitor searches all ranges to find exactly one that owns a property with
	 * that name. If found, it rewrites the node in-place so the existing node becomes
	 * the range prefix and a new child node holds the property name. If the property
	 * name is ambiguous (exists in more than one range) or cannot be found in any
	 * range, a QuelException is thrown.
	 *
	 * This visitor must run AFTER EntityProcessRange (so ranges are attached to
	 * qualified identifiers) and BEFORE EntityNameNormalizer (so the rewritten nodes
	 * are normalised together with the rest of the AST).
	 */
	class UnqualifiedDatabasePropertyResolver implements AstVisitorInterface {
		
		/**
		 * Entity store used to look up which properties belong to which entity.
		 * @var EntityStore
		 */
		private EntityStore $entityStore;
		
		/**
		 * All ranges defined in the query, used to search for property ownership.
		 * Only AstRangeDatabase entries that reference a concrete entity are considered;
		 * subquery ranges and JSON ranges are skipped because they have no EntityStore
		 * metadata to inspect.
		 * @var AstRange[]
		 */
		private array $ranges;
		
		/**
		 * Constructor
		 * @param EntityStore $entityStore Store containing entity/property metadata
		 * @param AstRange[] $ranges All ranges from the AstRetrieve node
		 */
		public function __construct(EntityStore $entityStore, array $ranges) {
			$this->entityStore = $entityStore;
			$this->ranges = $ranges;
		}
		
		/**
		 * Visit a node. Only bare base identifiers (no range attached, no next chain)
		 * are candidates for rewriting; everything else is left untouched.
		 * @param AstInterface $node
		 * @throws TransformationException
		 * @throws EntityResolutionException
		 */
		public function visitNode(AstInterface $node): void {
			// Only AstIdentifier nodes are relevant
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Only act on base identifiers (not property segments inside a chain)
			if ($node->getParent() instanceof AstIdentifier) {
				return;
			}
			
			// Already has a range attached → it was written as "p.name" and resolved
			// by EntityProcessRange; nothing to do
			if ($node->hasRange()) {
				return;
			}
			
			// Already has a child → it's at least a two-segment chain like "a.b".
			// EntityProcessRange will have attached a range to the base if "a" is a
			// known alias, so this case is handled. If it wasn't resolved, the
			// existing validators will catch it.
			if ($node->hasNext()) {
				return;
			}
			
			// We have a bare, single-segment identifier: candidate for auto-resolution.
			$propertyName = $node->getName();
			
			// Fetch the unique range for this property
			$matchingRange = $this->findUniqueRangeForProperty($propertyName);
			
			// Resolve whether the property is a scalar column or a relation so that
			// downstream phases (semantic checking, post-semantic rewrite) don't need
			// to re-query the entity store for information we already have here.
			$propertyType = $this->resolvePropertyType($matchingRange, $propertyName);

			// Create the property node
			$propertyNode = new AstIdentifier($propertyName, $propertyType);
			
			// Mutate the existing node. It's now an entity root
			$node->setName($matchingRange->getName());
			$node->setRange($matchingRange);
			$node->setType(IdentifierType::EntityRoot);
			$node->setNext($propertyNode);
		}
		
		/**
		 * Search all concrete entity ranges for one that exposes the given property.
		 * Returns the single matching range, or throws if zero or more than one match.
		 * @param string $propertyName The bare property name to look up
		 * @return AstRangeDatabase   The unique range that owns this property
		 * @throws TransformationException|EntityResolutionException On ambiguity or when no range owns the property
		 */
		private function findUniqueRangeForProperty(string $propertyName): AstRangeDatabase {
			$matches = [];
			
			foreach ($this->ranges as $range) {
				// Only concrete entity ranges have EntityStore metadata
				if (!$range instanceof AstRangeDatabase) {
					continue;
				}
				
				// Subquery ranges (temporary tables) have no entity name
				$entityName = $range->getEntityName();
				
				if ($entityName === null) {
					continue;
				}
				
				// Check scalar columns
				$columnMap = $this->entityStore->getColumnMap($entityName);
				
				if (isset($columnMap[$propertyName])) {
					$matches[] = $range;
					continue;
				}
				
				// Also check relations so that relation properties work too
				// Check if the property is a key in any of the dependencies
				$relations = array_merge(
					$this->entityStore->getOneToOneDependencies($entityName),
					$this->entityStore->getManyToOneDependencies($entityName),
					$this->entityStore->getOneToManyDependencies($entityName)
				);
				
				if (isset($relations[$propertyName])) {
					$matches[] = $range;
				}
			}
			
			// No match found. Throw 'not found' error
			if (count($matches) === 0) {
				throw new TransformationException(
					"Unknown property '{$propertyName}': it does not exist in any of the ranges defined in this query."
				);
			}
			
			// More than one match found. Throw ambiguity error
			if (count($matches) > 1) {
				$rangeNames = implode(', ', array_map(fn($r) => $r->getName(), $matches));
				
				throw new TransformationException(
					"Unqualified property '{$propertyName}' is ambiguous: " .
					"it exists in multiple ranges ({$rangeNames}). " .
					"Use a range prefix to disambiguate."
				);
			}
			
			// Return the match
			return $matches[0];
		}
		
		/**
		 * Determines the IdentifierType of a property on a concrete entity range.
		 *
		 * Checks scalar columns first (@Column-mapped properties), then relations
		 * (@OneToOne, @ManyToOne, @OneToMany). The caller guarantees that the property
		 * exists on the range — this method is only called after findUniqueRangeForProperty
		 * has already confirmed ownership, so the fallback to Unresolved should never
		 * be reached in practice.
		 *
		 * @param AstRangeDatabase $range The range whose entity metadata to inspect
		 * @param string $propertyName The property name to classify
		 * @return IdentifierType EntityProperty for scalar columns, EntityRelation for relations, Unresolved as fallback
		 * @throws EntityResolutionException
		 */
		private function resolvePropertyType(AstRangeDatabase $range, string $propertyName): IdentifierType {
			// Retrieve the entity name from the range — guaranteed non-null here because
			// findUniqueRangeForProperty already filtered out ranges without entity names.
			$entityName = $range->getEntityName();
			
			// Check scalar columns first — @Column-annotated properties map directly
			// to database columns and are the most common case.
			$columnMap = $this->entityStore->getColumnMap($entityName);

			if (isset($columnMap[$propertyName])) {
				return IdentifierType::EntityProperty;
			}

			// Check all relation types — @OneToOne, @ManyToOne, @OneToMany.
			// Relations are navigation properties that reference other entities rather
			// than mapping to a scalar database column.
			$relations = array_merge(
				$this->entityStore->getOneToOneDependencies($entityName),
				$this->entityStore->getManyToOneDependencies($entityName),
				$this->entityStore->getOneToManyDependencies($entityName)
			);

			if (isset($relations[$propertyName])) {
				return IdentifierType::EntityRelation;
			}

			// Should never be reached: findUniqueRangeForProperty confirmed this property
			// exists on this range before resolvePropertyType was called. If we land here,
			// it indicates a bug in the lookup logic above.
			return IdentifierType::Unresolved;
		}
	}