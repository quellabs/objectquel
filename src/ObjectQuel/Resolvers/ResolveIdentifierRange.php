<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Resolvers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;
	
	/**
	 * Implements the Visitor pattern to process AST nodes and identify ranges (aliases).
	 * When an identifier in the AST matches a defined range name, this visitor attaches
	 * the corresponding range object to that identifier node.
	 */
	class ResolveIdentifierRange implements AstVisitorInterface {
		
		/**
		 * Array of available ranges (aliases) that can be matched against identifiers
		 * @var AstRange[] Array containing AstRange objects that represent predefined ranges
		 */
		private array $ranges;
		
		/**
		 * EntityProcessRange constructor.
		 * @param AstRetrieve $retrieve Root query
		 */
		public function __construct(AstRetrieve $retrieve) {
			$this->ranges = $retrieve->getRanges();
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
			if (
				$node->getType() !== IdentifierType::EntityRoot &&
				$node->getType() !== IdentifierType::EntityReference &&
				$node->getType() !== IdentifierType::JsonRoot &&
				$node->getType() !== IdentifierType::SubqueryRoot
			) {
				return;
			}
			
			// Get the identifier's name for range lookup
			$name = $node->getName();
			
			// Search for a matching range by name
			$range = $this->getRange($name);
			
			// Attach it to the identifier node
			$node->setRange($range);
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