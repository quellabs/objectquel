<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	class CollectRanges implements AstVisitorInterface {
		
		/** @var array All nodes */
		private array $collectedNodes;
		
		/** @var array All nodes */
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
			if (!$node instanceof AstIdentifier) {
				return;
			}
			
			if (!$this->traverseSubqueries && $node->parentIsOneOf([AstSubquery::class])) {
				return;
			}
			
			if (!$node->isBaseIdentifier()) {
				return;
			}
			
			if ($node->getRange() === null) {
				return;
			}
			
			if (in_array($node->getRange()->getName(), $this->handledRanges)) {
				return;
			}
			
			$this->collectedNodes[] = $node->getRange();
			$this->handledRanges[] = $node->getRange()->getName();
		}
		
		/**
		 * Returns all collected nodes
		 * @return array
		 */
		public function getCollectedNodes(): array {
			return $this->collectedNodes;
		}
	}