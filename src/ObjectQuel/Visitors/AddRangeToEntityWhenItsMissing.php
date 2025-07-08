<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	class AddRangeToEntityWhenItsMissing implements AstVisitorInterface {
		
		private array $ranges;
		private array $range_names;
		private array $created_ranges;
		private array $counters;
		
		/**
		 * AddRangeToEntityWhenItsMissing constructor
		 */
		public function __construct() {
			$this->ranges = [];
			$this->range_names = [];
			$this->counters = [];
			$this->created_ranges = [];
		}
		
		/**
		 * Adds a unique range to the entity if it is missing
		 * @param AstInterface $node
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			// This visitor only handles AstEntity
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Skip this node if it already has an alias
			if ($node->hasRange()) {
				return;
			}
			
			// Skip this node if it is part of a chain
			if ($node->getParent() instanceof AstIdentifier) {
				return;
			}
			
			// If we have already created an alias for this entity, use it
			if (isset($this->created_ranges[$node->getName()])) {
				$node->setRange($this->created_ranges[$node->getName()]);
				return;
			}
			
			// Get the first letter of the entity name
			$firstLetter = substr($node->getName(), 0, 1);
			
			// Initialize or increment the sequence number for this entity
			if (!isset($this->counters[$firstLetter])) {
				$this->counters[$firstLetter] = 0;
			}
			
			do {
				// Increment the sequence number
				$this->counters[$firstLetter]++;
				
				// Create the new alias
				$newAlias = sprintf('%s%03d', $firstLetter, $this->counters[$firstLetter]);
			} while (in_array($newAlias, $this->range_names));
			
			// Create a new range
			$newRange = new AstRangeDatabase($newAlias, $node->getEntityName(), null);
			
			// Add the new, unique alias to the list of existing aliases
			$this->created_ranges[$node->getName()] = $newRange;
			$this->range_names[] = $newAlias;
			
			// Add the new AstRange
			$this->ranges[] = $newRange;
			
			// Set the new alias for the AstEntity
			$node->setRange($newRange);
		}
		
		/**
		 * Returns the ranges this visitor created
		 * @return array
		 */
		public function getRanges(): array {
			return $this->ranges;
		}
	}