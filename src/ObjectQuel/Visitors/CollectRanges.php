<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	class CollectRanges implements AstVisitorInterface {
		
		/** @var AstRange[] All nodes */
		private array $collectedNodes;
		
		/** @var array<bool|string> All nodes */
		private array $handledRanges;
		
		/**
		 * @var bool Should we traverse into subqueries
		 */
		private bool $traverseSubqueries;
		
		/**
		 * CollectIdentifiers constructor
		 */
		public function __construct(bool $traverseSubqueries = true) {
			$this->collectedNodes = [];
			$this->handledRanges = [];
			$this->traverseSubqueries = $traverseSubqueries;
		}
		
		/**
		 * Visits a node in the AST
		 * @param AstInterface $node The current node being visited
		 */
		public function visitNode(AstInterface $node): void {
			// Only handle AstIdentifier nodes
			if (!$node instanceof AstIdentifier) {
				return;
			}

			// If any of the parents is AstSubquery, ignore
			if (!$this->traverseSubqueries && $node->parentIsOneOf([AstSubquery::class])) {
				return;
			}
			
			// Only handle root nodes
			if ($node->getParent() instanceof AstIdentifier) {
				return;
			}
			
			// Skip nodes without ranges
			if ($node->getRange() === null) {
				return;
			}
			
			// Skip ranges we already collected
			if (isset($this->handledRanges[$node->getRange()->getName()])) {
				return;
			}
			
			// Add range to the collectedNodes list
			$this->collectedNodes[] = $node->getRange();
			
			// Add range name to the handledRanges list to skip duplicates
			$this->handledRanges[$node->getRange()->getName()] = true;
		}
		
		/**
		 * Returns all collected nodes
		 * @return AstRange[]
		 */
		public function getCollectedNodes(): array {
			return $this->collectedNodes;
		}
	}