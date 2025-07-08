<?php
	
	namespace Quellabs\ObjectQuel\Visitor;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Visitor class that detects if an AST node contains references to JSON sources
	 * This visitor is used to identify mixed data source references in query conditions
	 */
	class GatherReferencedRanges implements AstVisitorInterface {
		
		private array $ranges;
		
		/**
		 * Returns a list of range name used in the  query
		 * @param AstInterface $node The current node being visited in the AST
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			// We only care about identifier nodes, since these reference fields from ranges
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			/**
			 * Skip nodes without ranges
			 */
			if (!$node->hasRange()) {
				return;
			}
			
			// If we reached here, we found a JSON reference
			// Throw an exception to signal this to the calling method
			$this->ranges[] = $node->getRange()->getName();
		}
		
		/**
		 * Returns the gathered ranges
		 * @return string[]
		 */
		public function getRanges(): array {
			return array_unique($this->ranges);
		}
	}