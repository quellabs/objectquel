<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\PropertyRangeFinder;
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
	 * that name. If exactly one match is found, the node is rewritten in-place so
	 * the existing node becomes the range prefix and a new child node holds the
	 * property name.
	 *
	 * If zero or multiple matches are found the node is left as-is with type
	 * Unresolved. UnqualifiedPropertyValidator in the semantic checking phase
	 * will detect these and throw the appropriate SemanticException.
	 *
	 * This visitor must run AFTER EntityProcessRange (so ranges are attached to
	 * qualified identifiers) and BEFORE EntityNameNormalizer (so the rewritten
	 * nodes are normalized together with the rest of the AST).
	 */
	class ResolveUnqualifiedDatabaseProperty extends PropertyRangeFinder implements AstVisitorInterface {
		
		/** @var AstRange[] */
		private array $ranges;
		
		/**
		 * @param EntityStore $entityStore Store containing entity/property metadata
		 * @param AstRange[] $ranges All ranges from the AstRetrieve node
		 */
		public function __construct(EntityStore $entityStore, array $ranges) {
			parent::__construct($entityStore);
			$this->ranges = $ranges;
		}
		
		/**
		 * Visit a node. Only bare base identifiers (no range attached, no next chain)
		 * are candidates for rewriting; everything else is left untouched.
		 * @param AstInterface $node
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
			
			// Already has a child → it's at least a two-segment chain like "a.b".
			// EntityProcessRange will have attached a range to the base if "a" is a
			// known alias, so this case is handled. If it wasn't resolved, the
			// existing validators will catch it.
			if ($node->getNext() !== null) {
				return;
			}
			
			// We have a bare, single-segment identifier: candidate for auto-resolution.
			$propertyName = $node->getName();
			
			// If the identifier matches a range name it is a range reference, not a
			// property — leave it alone for the range resolvers to handle.
			foreach ($this->ranges as $range) {
				if ($range->getName() === $propertyName) {
					return;
				}
			}
			
			// Find all ranges that expose this property name
			$matches = $this->findRanges($propertyName, $this->ranges);
			
			// Property cannot be resolved here — leave as Unresolved
			// so UnqualifiedPropertyValidator can produce a meaningful error message.
			if (count($matches) !== 1) {
				return;
			}
			
			// Fetch the one match
			$matchingRange = $matches[0];
			
			// Mutate the existing node in-place so that any parent pointers already
			// established in the AST remain valid. The node becomes the range root
			// and a new child node carries the property name.
			$node->setName($matchingRange->getName());
			$node->setRange($matchingRange);
			$node->setType(IdentifierType::EntityRoot);
			$node->setNext(new AstIdentifier($propertyName, IdentifierType::EntityProperty));
		}
	}