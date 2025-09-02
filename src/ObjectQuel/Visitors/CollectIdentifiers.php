<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	class CollectIdentifiers implements AstVisitorInterface {
		
		/** @var array All nodes */
		private array $collectedNodes;
		
		/**
		 * CollectIdentifiers constructor
		 */
		public function __construct() {
			$this->collectedNodes = [];
		}
		
		/**
		 * Visits a node in the AST
		 * @param AstInterface $node The current node being visited
		 */
		public function visitNode(AstInterface $node): void {
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			if (!$node->isBaseIdentifier()) {
				return;
			}
			
			if ($node->getRange() === null) {
				return;
			}
			
			$this->collectedNodes[] = $node;
		}
		
		/**
		 * Returns all collected nodes
		 * @return array
		 */
		public function getCollectedNodes(): array {
			return $this->collectedNodes;
		}
	}