<?php
	
	namespace Quellabs\ObjectQuel\Planner\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Visitor that checks whether any range at all is referenced in an AST subtree.
	 * Used to distinguish conditions that touch database data from those that can
	 * be evaluated in pure PHP.
	 */
	class CheckAnyRangeReference implements AstVisitorInterface {
		
		/** @var bool True once any range-bearing identifier has been found */
		private bool $found = false;
		
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
			
			// Any non-null range means this identifier is bound to a data source —
			// as opposed to a literal or parameter, which have no range association
			if ($node->getRange() !== null) {
				$this->found = true;
			}
		}
		
		/**
		 * Returns true if any range reference was found during traversal.
		 */
		public function isFound(): bool {
			return $this->found;
		}
	}