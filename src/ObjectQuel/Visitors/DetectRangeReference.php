<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Visitor that checks whether a specific range is referenced anywhere in an AST subtree.
	 */
	class DetectRangeReference implements AstVisitorInterface {
		
		/** @var string The name of the range to search for */
		private string $rangeName;
		
		/** @var bool True once a matching range reference has been found */
		private bool $found = false;
		
		/**
		 * @param string $rangeName The name of the range to search for
		 */
		public function __construct(string $rangeName) {
			$this->rangeName = $rangeName;
		}
		
		/**
		 * Visits a node and records whether it references the target range.
		 * @param AstInterface $node The current node being visited
		 */
		public function visitNode(AstInterface $node): void {
			// Already found. Escape the recursion
			if ($this->found) {
				return;
			}
			
			// Only handle identifier nodes
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			// Skip identifiers without a range
			if ($node->getRange() === null) {
				return;
			}
			
			// If the range is the given range name, we found it
			if ($node->getRange()->getName() === $this->rangeName) {
				$this->found = true;
			}
		}
		
		/**
		 * Returns true if the target range was found during traversal.
		 * @return bool
		 */
		public function isFound(): bool {
			return $this->found;
		}
	}