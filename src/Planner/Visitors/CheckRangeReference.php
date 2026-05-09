<?php
	
	namespace Quellabs\ObjectQuel\Planner\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Visitor that checks whether a specific range is referenced anywhere in an AST subtree.
	 * Short-circuits on first match by throwing, avoiding unnecessary further traversal.
	 */
	class CheckRangeReference implements AstVisitorInterface {
		
		/** @var string The name of the range to search for */
		private string $rangeName;
		
		/** @var bool True once a matching range reference has been found */
		private bool $found = false;
		
		/**
		 * @param AstRange $range The range to search for
		 */
		public function __construct(AstRange $range) {
			// Store the name rather than the object — range identity is name-based,
			// since the same logical range can be represented by different instances
			$this->rangeName = $range->getName();
		}
		
		/**
		 * @param AstInterface $node The current node being visited
		 */
		public function visitNode(AstInterface $node): void {
			// Skip remaining nodes once a match is already recorded
			if ($this->found) {
				return;
			}
			
			// Only identifiers carry range references; all other node types are irrelevant
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// getRange() returns null for unqualified identifiers (e.g. bare column names
			// that haven't been resolved yet), so the null-safe call guards against that
			if ($node->getRange()?->getName() === $this->rangeName) {
				$this->found = true;
			}
		}
		
		/**
		 * Returns true if the target range was referenced during traversal.
		 */
		public function isFound(): bool {
			return $this->found;
		}
	}