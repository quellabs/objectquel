<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;
	
	/**
	 * Validates that all unresolved bare identifiers can be unambiguously mapped
	 * to a single range property.
	 *
	 * Runs during semantic checking, after UnqualifiedDatabasePropertyResolver has
	 * already rewritten all identifiers it could resolve. Any bare identifier that
	 * is still Unresolved at this point is either unknown (no range owns it) or
	 * ambiguous (multiple ranges own it). This validator detects both cases and
	 * throws a SemanticException with a precise error message.
	 */
	class ValidateUnambiguousProperty extends FindPropertyRange implements AstVisitorInterface {
		
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
		 * Visit a node. Bare Unresolved identifiers are validated against all ranges.
		 * @param AstInterface $node
		 * @throws SemanticException If the property is unknown or ambiguous
		 * @throws EntityResolutionException
		 */
		public function visitNode(AstInterface $node): void {
			// Only AstIdentifier nodes are relevant
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Only check bare unresolved identifiers — resolved ones were already
			// handled by UnqualifiedDatabasePropertyResolver
			if ($node->getType() !== IdentifierType::Unresolved) {
				return;
			}
			
			// Only act on base identifiers (not property segments inside a chain)
			if ($node->getParent() instanceof AstIdentifier) {
				return;
			}
			
			// Only bare single-segment identifiers are candidates
			if ($node->getNext() !== null) {
				return;
			}
			
			// Fetch the property name
			$propertyName = $node->getName();
			
			// Collect matches
			$matches = $this->findRanges($propertyName, $this->ranges);
			
			// More than one candidate for replacing. Throw ambiguous error
			$rangeNames = implode(', ', array_map(fn($r) => $r->getName(), $matches));
			
			throw new SemanticException(
				"Unqualified property '{$propertyName}' is ambiguous: " .
				"it exists in multiple ranges ({$rangeNames}). " .
				"Use a range prefix to disambiguate."
			);
		}
	}