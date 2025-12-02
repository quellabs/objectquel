<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Implements the Visitor pattern to process AST nodes and identify ranges (aliases).
	 * When an identifier in the AST matches a defined range name, this visitor attaches
	 * the corresponding range object to that identifier node.
	 */
	class EntityProcessRange implements AstVisitorInterface {
		
		/**
		 * Array of available ranges (aliases) that can be matched against identifiers
		 * @var array Array containing AstRange objects that represent predefined ranges
		 */
		private array $ranges;
		
		/**
		 * EntityProcessRange constructor.
		 * @param array $ranges Table of ranges (should contain AstRangeDatabase objects)
		 */
		public function __construct(array $ranges) {
			$this->ranges = $ranges;
		}
		
		/**
		 * This is the main visitor method that implements the Visitor pattern.
		 * It checks if the current node is an AstIdentifier, and if so, attempts
		 * to match its name against available ranges. If a match is found, the
		 * range object is attached to the identifier node.
		 * @param AstInterface $node The AST node to visit and potentially process
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			// Only process AstIdentifier nodes, skip all others
			if (!$node instanceof AstIdentifier) {
				return;
			}

			// Only handle base identifiers
			if (!$node->isBaseIdentifier()) {
				return;
			}
			
			// Get the identifier's name for range lookup
			$name = $node->getName();
			
			// Search for a matching range by name
			$range = $this->getRange($name);
			
			// If a matching range is found, attach it to the identifier node
			if ($range !== null) {
				$node->setRange($range);
			}
		}

		/**
		 * This method performs a linear search through all available ranges to find
		 * one that matches the given name. Used to determine if an identifier
		 * corresponds to a predefined range.
		 * @param string $range The name of the range to search for
		 * @return AstRange|null Returns the matching range object or null if not found
		 */
		protected function getRange(string $range): ?AstRange {
			// Iterate through all available ranges
			foreach ($this->ranges as $astRange) {
				// Check if the current range name matches the search term
				if ($astRange->getName() == $range) {
					return $astRange;
				}
			}
			
			// Return null if no matching range is found
			return null;
		}
	}