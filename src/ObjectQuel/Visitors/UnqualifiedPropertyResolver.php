<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
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
	class UnqualifiedPropertyResolver implements AstVisitorInterface {
		
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
		 * @throws QuelException
		 */
		public function visitNode(AstInterface $node): void {
			// Only AstIdentifier nodes are relevant
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Only act on base identifiers (not property segments inside a chain)
			if (!$node->isBaseIdentifier()) {
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
			
			// Mutate the existing node rather than replacing it so that any parent
			// pointers already established in the AST remain valid.
			$node->setName($matchingRange->getName());
			$node->setRange($matchingRange);
			$propertyNode = new AstIdentifier($propertyName);
			$node->setNext($propertyNode);
		}
		
		/**
		 * Search all concrete entity ranges for one that exposes the given property.
		 * Returns the single matching range, or throws if zero or more than one match.
		 * @param string $propertyName The bare property name to look up
		 * @return AstRangeDatabase   The unique range that owns this property
		 * @throws QuelException      On ambiguity or when no range owns the property
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
				
				// Also check one-to-many relations so that relation properties work too
				$relations = $this->entityStore->getOneToManyDependencies($entityName);
				
				if (isset($relations[$propertyName])) {
					$matches[] = $range;
				}
			}
			
			if (count($matches) === 0) {
				throw new QuelException(
					"Unknown property '{$propertyName}': it does not exist in any of the ranges defined in this query."
				);
			}
			
			if (count($matches) > 1) {
				$rangeNames = implode(', ', array_map(fn($r) => $r->getName(), $matches));
				
				throw new QuelException(
					"Unqualified property '{$propertyName}' is ambiguous: " .
					"it exists in multiple ranges ({$rangeNames}). " .
					"Use a range prefix to disambiguate."
				);
			}
			
			return $matches[0];
		}
	}