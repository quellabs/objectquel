<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	class NodeCollector implements AstVisitorInterface {
		
		/** @var array The node types to search for */
		private array $types;
		
		/** @var array All nodes */
		private array $collectedNodes;
		
		/**
		 * CollectNodes constructor
		 * @param array|string $types
		 */
		public function __construct(array|string $types) {
			if (is_string($types)) {
				$this->types = [$types];
			} else {
				$this->types = $types;
			}

			$this->collectedNodes = [];
		}
		
		/**
		 * Visits a node in the AST
		 * @param AstInterface $node The current node being visited
		 */
		public function visitNode(AstInterface $node): void {
			// Skip if not an AstIdentifier node
			foreach($this->types as $type) {
				if (is_a($node, $type)) {
					$this->collectedNodes[] = $node;
					return;
				}
			}
		}
		
		/**
		 * Returns all collected nodes
		 * @return array
		 */
		public function getCollectedNodes(): array {
			return $this->collectedNodes;
		}
	}