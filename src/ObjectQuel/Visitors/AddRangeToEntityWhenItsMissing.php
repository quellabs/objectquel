<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
	/**
	 * This visitor traverses the AST and assigns unique range aliases to AstIdentifier nodes
	 * that lack ranges. It generates aliases in the format {FirstLetter}{SequenceNumber}
	 * (e.g., u001, u002 for "users").
	 */
	class AddRangeToEntityWhenItsMissing implements AstVisitorInterface {

		private const int MAX_SEQUENCE_NUMBER = 999;
		private const string SEQUENCE_FORMAT = '%s%03d';
		
		/** @var AstRangeDatabase[] Created ranges indexed by entity name */
		private array $entityRangeMap = [];
		
		/** @var string[] List of all created range names to ensure uniqueness */
		private array $usedRangeNames = [];
		
		/** @var AstRangeDatabase[] All created ranges in order */
		private array $createdRanges = [];
		
		/** @var int[] Sequence counters indexed by first letter */
		private array $sequenceCounters = [];
		
		/**
		 * @var EntityStore EntityStore
		 */
		private EntityStore $entityStore;
		
		/**
		 * Initialize the visitor with empty state.
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
			$this->reset();
		}
		
		/**
		 * Reset the visitor state to handle multiple traversals.
		 * @return void
		 */
		public function reset(): void {
			$this->entityRangeMap = [];
			$this->usedRangeNames = [];
			$this->createdRanges = [];
			$this->sequenceCounters = [];
		}
		
		/**
		 * Visit a node and add a range if it's an identifier that needs one.
		 * @param AstInterface $node The AST node to process
		 * @return void
		 * @throws QuelException If the identifier has no entity name
		 */
		public function visitNode(AstInterface $node): void {
			// Skip node if needed
			if (!$this->shouldProcessNode($node)) {
				return;
			}
			
			/** @var AstIdentifier $node */
			$entityName = $node->getEntityName();
			
			// Reuse existing range for this entity if available
			if ($entityName && isset($this->entityRangeMap[$entityName])) {
				$node->setRange($this->entityRangeMap[$entityName]);
				return;
			}
			
			// Get the identifier name and check if it's an entity
			$entityName = $node->getName();
			
			// Create new range for this entity
			if ($this->entityStore->exists($entityName)) {
				$range = $this->createUniqueRange($entityName);
				$this->entityRangeMap[$entityName] = $range;
				$this->createdRanges[] = $range;
				$node->setRange($range);
				return;
			}
			
			throw new QuelException(
				"Identifier '{$node->getName()}' is referenced but no range is defined for it"
			);
		}
		
		/**
		 * Get all ranges created by this visitor.
		 * @return AstRangeDatabase[] Array of created ranges
		 */
		public function getRanges(): array {
			return $this->createdRanges;
		}
		
		/**
		 * Check if a node should be processed by this visitor.
		 * @param AstInterface $node The node to check
		 * @return bool True if the node should be processed, false otherwise
		 */
		private function shouldProcessNode(AstInterface $node): bool {
			// Only process AstIdentifier nodes
			if (!$node instanceof AstIdentifier) {
				return false;
			}
			
			// Skip if already has a range
			if ($node->hasRange()) {
				return false;
			}
			
			// Skip if part of a chain (has AstIdentifier parent)
			if ($node->getParent() instanceof AstIdentifier) {
				return false;
			}
			
			return true;
		}
		
		/**
		 * Create a unique range for the given entity.
		 * @param string $entityName The name of the entity
		 * @return AstRangeDatabase The created range
		 * @throws QuelException If unable to generate a unique alias
		 */
		private function createUniqueRange(string $entityName): AstRangeDatabase {
			$alias = $this->generateUniqueAlias($entityName);
			
			$this->usedRangeNames[] = $alias;
			
			return new AstRangeDatabase($alias, $entityName, null);
		}
		
		/**
		 * Generate a unique alias for the entity.
		 * @param string $entityName The name of the entity
		 * @return string The generated unique alias
		 * @throws QuelException If unable to generate a unique alias within limits
		 */
		private function generateUniqueAlias(string $entityName): string {
			$firstLetter = strtolower(substr($entityName, 0, 1));
			
			if (!isset($this->sequenceCounters[$firstLetter])) {
				$this->sequenceCounters[$firstLetter] = 0;
			}
			
			$attempts = 0;
			$maxAttempts = self::MAX_SEQUENCE_NUMBER;
			
			do {
				$this->sequenceCounters[$firstLetter]++;
				$attempts++;
				
				if ($attempts > $maxAttempts) {
					throw new QuelException(
						"Unable to generate unique alias for entity '{$entityName}' - " .
						"exceeded maximum attempts ({$maxAttempts})"
					);
				}
				
				$alias = sprintf(
					self::SEQUENCE_FORMAT,
					$firstLetter,
					$this->sequenceCounters[$firstLetter]
				);
				
			} while ($this->isAliasUsed($alias));
			
			return $alias;
		}
		
		/**
		 * Check if an alias is already in use.
		 * @param string $alias The alias to check
		 * @return bool True if the alias is already used, false otherwise
		 */
		private function isAliasUsed(string $alias): bool {
			return in_array($alias, $this->usedRangeNames, true);
		}
	}