<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
	/**
	 * Resolves direct entity class references by creating implicit range definitions.
	 *
	 * Transforms queries with direct entity references into proper range-based queries:
	 * - Input:  retrieve(PostEntity)
	 * - Output: range of p001 is PostEntity; retrieve(p001)
	 *
	 * When users reference entity classes directly without defining range aliases,
	 * this visitor automatically:
	 * 1. Detects direct entity class references (PostEntity, UserEntity, etc.)
	 * 2. Generates unique range aliases (p001, u001, etc.)
	 * 3. Creates range definitions mapping aliases to entity classes
	 * 4. Replaces direct references with generated aliases
	 *
	 * This allows simpler query syntax by inferring required range definitions,
	 * while maintaining ObjectQuel's requirement that all entities use range aliases.
	 */
	class ImplicitRangeResolver implements AstVisitorInterface {
		
		/** Maximum sequence number before exhausting alias space for a letter */
		private const int MAX_SEQUENCE_NUMBER = 999;
		
		/** Format string for generating aliases: letter + 3-digit number */
		private const string SEQUENCE_FORMAT = '%s%03d';
		
		/** Entity store for validating entity existence */
		private EntityStore $entityStore;
		
		/** @var AstRangeDatabase[] Created ranges indexed by entity name for reuse */
		private array $entityRangeMap = [];
		
		/** @var AstRangeDatabase[] All created ranges in order of creation */
		private array $createdRanges = [];
		
		/** @var string[] Range names in use to ensure uniqueness across all entities */
		private array $usedRangeNames = [];
		
		/** @var int[] Sequence counters indexed by first letter (e.g., 'u' => 5) */
		private array $sequenceCounters = [];
		
		/**
		 * Initialize visitor with entity store for validation.
		 * @param EntityStore $entityStore Store containing registered entities
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
			$this->reset();
		}
		
		/**
		 * Reset visitor state to handle multiple traversals.
		 * Clears all tracking arrays and counters.
		 */
		public function reset(): void {
			$this->entityRangeMap = [];
			$this->createdRanges = [];
			$this->usedRangeNames = [];
			$this->sequenceCounters = [];
		}
		
		/**
		 * Visit node and assign range if it's an identifier requiring one.
		 * Reuses existing ranges for the same entity or creates new unique ranges.
		 * @param AstInterface $node The AST node to process
		 * @throws QuelException If identifier references non-existent entity
		 */
		public function visitNode(AstInterface $node): void {
			// Only process identifiers that need ranges
			if (!$this->shouldProcessNode($node)) {
				return;
			}
			
			/** @var AstIdentifier $node */
			
			// Reuse existing range if already created for this entity
			if ($existingRange = $this->getExistingRangeForNode($node)) {
				$node->setRange($existingRange);
				return;
			}
			
			// Create new range for entity
			$entityName = $node->getName();
			
			// Validate entity exists in store
			if (!$this->entityStore->exists($entityName)) {
				throw new QuelException(
					"Identifier '{$entityName}' is referenced but no range is defined for it"
				);
			}
			
			// Generate and assign new range
			$range = $this->createUniqueRange($entityName);
			$this->entityRangeMap[$entityName] = $range;
			$this->createdRanges[] = $range;
			$node->setRange($range);
		}
		
		/**
		 * Get all ranges created during traversal.
		 * @return AstRangeDatabase[] Array of created ranges in order
		 */
		public function getRanges(): array {
			return $this->createdRanges;
		}
		
		/**
		 * Determine if node requires range assignment.
		 * Filters for identifiers that: are entity references, lack ranges, and aren't chained.
		 * @param AstInterface $node The node to check
		 * @return bool True if node should be processed
		 */
		private function shouldProcessNode(AstInterface $node): bool {
			// Only process identifiers
			if (!$node instanceof AstIdentifier) {
				return false;
			}
			
			// Skip if already has a range assigned
			if ($node->hasRange()) {
				return false;
			}
			
			// Skip if part of a property chain (e.g., user.name where user is parent)
			if ($node->getParent() instanceof AstIdentifier) {
				return false;
			}
			
			// Skip if not referencing an entity
			if (!$node->isFromEntity()) {
				return false;
			}
			
			return true;
		}
		
		/**
		 * Retrieve existing range for node's entity if one was previously created.
		 * @param AstIdentifier $node The identifier node
		 * @return AstRangeDatabase|null Existing range or null if not found
		 */
		private function getExistingRangeForNode(AstIdentifier $node): ?AstRangeDatabase {
			$entityName = $node->getEntityName();
			
			// Check if we've already created a range for this entity
			if ($entityName && isset($this->entityRangeMap[$entityName])) {
				return $this->entityRangeMap[$entityName];
			}
			
			return null;
		}
		
		/**
		 * Create a new range with unique alias for entity.
		 * @param string $entityName The entity name
		 * @return AstRangeDatabase The created range
		 * @throws QuelException If unable to generate unique alias
		 */
		private function createUniqueRange(string $entityName): AstRangeDatabase {
			$alias = $this->generateUniqueAlias($entityName);
			$this->usedRangeNames[] = $alias;
			
			return new AstRangeDatabase($alias, $entityName, null);
		}
		
		/**
		 * Generate unique alias using entity's first letter and sequence number.
		 * Increments counter until unused alias is found (e.g., u001, u002, ...).
		 * @param string $entityName The entity name
		 * @return string The generated unique alias
		 * @throws QuelException If unable to generate unique alias within limits
		 */
		private function generateUniqueAlias(string $entityName): string {
			// Use first letter as alias prefix
			$firstLetter = strtolower(substr($entityName, 0, 1));
			
			// Initialize counter for this letter if needed
			if (!isset($this->sequenceCounters[$firstLetter])) {
				$this->sequenceCounters[$firstLetter] = 0;
			}
			
			$attempts = 0;
			$maxAttempts = self::MAX_SEQUENCE_NUMBER;
			
			// Increment counter until we find an unused alias
			do {
				$this->sequenceCounters[$firstLetter]++;
				$attempts++;
				
				// Prevent infinite loop if alias space is exhausted
				if ($attempts > $maxAttempts) {
					throw new QuelException(
						"Unable to generate unique alias for entity '{$entityName}' - " .
						"exceeded maximum attempts ({$maxAttempts})"
					);
				}
				
				// Format as letter + 3-digit number (e.g., u001)
				$alias = sprintf(
					self::SEQUENCE_FORMAT,
					$firstLetter,
					$this->sequenceCounters[$firstLetter]
				);
				
			} while ($this->isAliasUsed($alias));
			
			return $alias;
		}
		
		/**
		 * Check if alias is already in use.
		 * @param string $alias The alias to check
		 * @return bool True if alias is already used
		 */
		private function isAliasUsed(string $alias): bool {
			return in_array($alias, $this->usedRangeNames, true);
		}
	}