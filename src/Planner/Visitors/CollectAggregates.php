<?php
	
	namespace Quellabs\ObjectQuel\Planner\Visitors;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAggregate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * AST visitor that collects aggregate function nodes (Sum, Count, Avg, Min, Max).
	 */
	class CollectAggregates implements AstVisitorInterface {
		
		/**
		 * @var array<int, AstAggregate> All collected aggregate function nodes
		 */
		private array $collectedNodes;
		
		/**
		 * @var array<int, bool> Tracks object IDs of already-collected nodes to prevent
		 * duplicates when the same node is reachable via multiple traversal paths
		 * (e.g. once through AstAlias::$expression and once through AstRetrieve::$macros).
		 */
		private array $seen;
		
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
			$this->seen = [];
			$this->traverseSubqueries = $traverseSubqueries;
		}
		
		/**
		 * Visits a node in the AST and collects it if it's an aggregate function
		 * @param AstInterface $node The current node being visited during AST traversal
		 */
		public function visitNode(AstInterface $node): void {
			// Not an aggregate function, skip this node
			if (!$node instanceof AstAggregate) {
				return;
			}
			
			/**
			 * Skip Any aggregate. This one gets special handling
			 */
			if ($node instanceof AstAny) {
				return;
			}

			// If we're not traversing subqueries, check if this node is inside a subquery
			if (!$this->traverseSubqueries && $node->parentIsOneOf([AstSubquery::class])) {
				return;
			}
			
			// Skip nodes already collected — the same object can be visited more than once
			// when it is reachable via multiple paths (e.g. AstAlias::$expression and
			// AstRetrieve::$macros both reference the same aggregate instance).
			$id = spl_object_id($node);
			
			if (isset($this->seen[$id])) {
				return;
			}
			
			$this->seen[$id] = true;
			$this->collectedNodes[] = $node;
		}
		
		/**
		 * Returns all collected aggregate function nodes
		 * @return AstAggregate[] Array of AST nodes representing aggregate functions
		 */
		public function getCollectedNodes(): array {
			return $this->collectedNodes;
		}
	}