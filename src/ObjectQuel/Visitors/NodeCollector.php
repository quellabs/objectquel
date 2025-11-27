<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Collects AST nodes matching specified types during tree traversal
	 */
	class NodeCollector implements AstVisitorInterface {
		
		private array $targetTypes;
		private array $nodes = [];
		
		/**
		 * NodeCollector constructor
		 * @param string|array $types Node class name(s) to collect
		 */
		public function __construct(string|array $types) {
			$this->targetTypes = is_array($types) ? $types : [$types];
		}
		
		/**
		 * Collects node if it matches any target type
		 * @param AstInterface $node The node being visited
		 */
		public function visitNode(AstInterface $node): void {
			foreach ($this->targetTypes as $type) {
				if (is_a($node, $type)) {
					$this->nodes[] = $node;
					return;
				}
			}
		}
		
		/**
		 * Returns all collected nodes
		 * @return AstInterface[]
		 */
		public function getNodes(): array {
			return $this->nodes;
		}
	}