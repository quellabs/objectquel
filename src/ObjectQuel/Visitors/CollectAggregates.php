<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * AST visitor that collects aggregate function nodes (Sum, Count, Avg, Min, Max).
	 */
	class CollectAggregates implements AstVisitorInterface {
		
		/**
		 * @var array All collected aggregate function nodes
		 */
		private array $collectedNodes;
		
		/**
		 * @var bool Should we traverse into subqueries to collect aggregate nodes
		 */
		private bool $traverseSubqueries;
		
		/**
		 * CollectRanges constructor
		 * @param bool $traverseSubqueries Whether to include aggregate functions from subqueries
		 */
		public function __construct(bool $traverseSubqueries = true) {
			$this->collectedNodes = [];
			$this->traverseSubqueries = $traverseSubqueries;
		}
		
		/**
		 * Visits a node in the AST and collects it if it's an aggregate function
		 * @param AstInterface $node The current node being visited during AST traversal
		 */
		public function visitNode(AstInterface $node): void {
			// Check if the node is one of the aggregate function types we're interested in
			// This uses negative logic which could be refactored to positive logic for clarity
			if (
				!$node instanceof AstSum &&      // Regular SUM function
				!$node instanceof AstSumU &&     // Unique SUM function (SUM DISTINCT)
				!$node instanceof AstCount &&    // Regular COUNT function
				!$node instanceof AstCountU &&   // Unique COUNT function (COUNT DISTINCT)
				!$node instanceof AstAvg &&      // Regular AVERAGE function
				!$node instanceof AstAvgU &&     // Unique AVERAGE function (AVG DISTINCT)
				!$node instanceof AstMin &&      // MINIMUM function
				!$node instanceof AstMax         // MAXIMUM function
			) {
				// Not an aggregate function, skip this node
				return;
			}
			
			// If we're not traversing subqueries, check if this node is inside a subquery
			if (!$this->traverseSubqueries && $node->parentIsOneOf([AstSubquery::class])) {
				return;
			}
			
			// This is an aggregate function node and should be collected
			$this->collectedNodes[] = $node;
		}
		
		/**
		 * Returns all collected aggregate function nodes
		 * @return array<AstInterface> Array of AST nodes representing aggregate functions
		 */
		public function getCollectedNodes(): array {
			return $this->collectedNodes;
		}
	}