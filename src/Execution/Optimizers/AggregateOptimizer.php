<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	
	/**
	 * Optimizes aggregate functions by choosing the most efficient execution strategy.
	 * Handles both single-range and multi-range query optimizations.
	 *
	 * Single-range optimization focuses on moving conditions from aggregate functions
	 * to the WHERE clause and converting conditional aggregates to CASE WHEN statements.
	 *
	 * Multi-range optimization decides between subqueries and CASE WHEN transformations
	 * based on query complexity and performance considerations.
	 */
	class AggregateOptimizer {
		
		/** @var array List of all supported aggregate AST node types */
		private array $aggregateTypes;
		
		/** @var AstNodeReplacer Handles AST node replacements during optimization */
		private AstNodeReplacer $astNodeReplacer;
		
		/**
		 * Initialize the optimizer with all supported aggregate types.
		 *
		 * @param EntityManager $entityManager The entity manager (currently unused but available for future enhancements)
		 */
		public function __construct(EntityManager $entityManager) {
			$this->astNodeReplacer = new AstNodeReplacer();
			
			// Define all aggregate function types that this optimizer can handle
			$this->aggregateTypes = [
				AstCount::class,    // COUNT() function
				AstCountU::class,   // COUNT(DISTINCT) function
				AstAvg::class,      // AVG() function
				AstAvgU::class,     // AVG(DISTINCT) function
				AstSum::class,      // SUM() function
				AstSumU::class,     // SUM(DISTINCT) function
				AstMin::class,      // MIN() function
				AstMax::class,      // MAX() function
				AstAny::class       // ANY() / EXISTS-like function
			];
		}
		
		/**
		 * Main entry point for aggregate optimization.
		 * Routes to appropriate optimization strategy based on query type.
		 * @param AstRetrieve $ast The query AST to optimize
		 */
		public function optimize(AstRetrieve $ast): void {
			// Single-range queries can use more aggressive optimizations
			if ($ast->isSingleRangeQuery(true)) {
				$this->optimizeSingleRangeAggregates($ast);
			} else {
				// Multi-range queries need more careful handling to avoid conflicts
				$this->optimizeMultiRangeAggregates($ast);
			}
		}
		
		/**
		 * Optimizes aggregate functions in single-range queries.
		 * Can apply more aggressive optimizations like moving conditions to WHERE clause.
		 * @param AstRetrieve $ast The single-range query AST
		 */
		private function optimizeSingleRangeAggregates(AstRetrieve $ast): void {
			// Check if we can promote an aggregate condition to the main WHERE clause
			// This is only safe when:
			// 1. There's exactly one value being selected
			// 2. That value is an aggregate function
			// 3. The aggregate has conditions
			// 4. There's no existing WHERE clause to conflict with
			$canPromoteCondition = (
				count($ast->getValues()) === 1 &&
				$this->isAggregateNode($ast->getValues()[0]->getExpression()) &&
				$ast->getValues()[0]->getExpression()->getConditions() !== null &&
				$ast->getConditions() === null
			);
			
			if ($canPromoteCondition) {
				// Transform: SELECT SUM(value WHERE condition)
				// Into:      SELECT SUM(value) WHERE condition
				// This is more efficient as it filters rows before aggregation
				$aggregateCondition = $ast->getValues()[0]->getExpression()->getConditions();
				$ast->setConditions($aggregateCondition);
				$ast->getValues()[0]->getExpression()->setConditions(null);
			}
			
			// Transform any remaining conditional aggregates to CASE WHEN statements
			$this->transformAggregatesToCaseWhen($ast);
		}
		
		/**
		 * Optimizes aggregate functions in multi-range queries.
		 * Must be more conservative to avoid breaking cross-range relationships.
		 * @param AstRetrieve $ast The multi-range query AST
		 */
		private function optimizeMultiRangeAggregates(AstRetrieve $ast): void {
			// Find all aggregate functions in both SELECT values and WHERE conditions
			$valueAggregates = $this->findAggregatesForValues($ast->getValues());
			$conditionAggregates = $this->findAggregatesForConditions($ast->getConditions());
			$allAggregates = array_merge($valueAggregates, $conditionAggregates);
			
			// Process each aggregate function individually
			foreach ($allAggregates as $aggregateNode) {
				// Skip aggregates that don't need optimization
				if (!$this->shouldWrapAggregation($ast, $aggregateNode)) {
					continue;
				}
				
				// Choose between subquery and CASE WHEN based on efficiency
				if ($this->isSubqueryMoreEfficient($ast, $aggregateNode)) {
					// Convert to subquery for complex scenarios
					$this->convertAggregateToSubquery($ast, $aggregateNode);
				} else {
					// Use CASE WHEN for simpler transformations
					$this->transformAggregateToCase($aggregateNode);
				}
			}
		}
		
		/**
		 * Converts an aggregate node to an appropriate subquery type.
		 * The subquery type depends on context and aggregate function type.
		 *
		 * Subquery types:
		 * - SCALAR: Returns a single value (most aggregate functions)
		 * - EXISTS: Returns boolean (for ANY functions in conditions)
		 * - CASE_WHEN: Returns conditional value (for ANY functions in values)
		 *
		 * @param AstRetrieve $ast The query being optimized
		 * @param AstInterface $aggregateNode The aggregate to convert
		 */
		private function convertAggregateToSubquery(AstRetrieve $ast, AstInterface $aggregateNode): void {
			$parentNode = $aggregateNode->getParent();
			
			// Most aggregates become scalar subqueries
			if (!$aggregateNode instanceof AstAny) {
				$subquery = new AstSubquery($aggregateNode, AstSubquery::TYPE_SCALAR);
				$this->astNodeReplacer->replaceChild($parentNode, $aggregateNode, $subquery);
				return;
			}
			
			// ANY functions in WHERE conditions become EXISTS subqueries
			if ($aggregateNode->isAncestorOf($ast->getConditions())) {
				$subquery = new AstSubquery($aggregateNode, AstSubquery::TYPE_EXISTS);
				$this->astNodeReplacer->replaceChild($parentNode, $aggregateNode, $subquery);
				return;
			}
			
			// ANY functions in SELECT values become CASE WHEN subqueries
			$subquery = new AstSubquery($aggregateNode, AstSubquery::TYPE_CASE_WHEN);
			$this->astNodeReplacer->replaceChild($parentNode, $aggregateNode, $subquery);
		}
		
		/**
		 * Determines if subquery approach would be more efficient than CASE WHEN.
		 *
		 * Currently returns false (conservative approach) but should be enhanced
		 * with cost-based analysis considering:
		 * - Number of rows in source tables
		 * - Complexity of join conditions
		 * - Selectivity of aggregate conditions
		 * - Available indexes
		 *
		 * TODO: Implement cost-based analysis
		 *
		 * @param AstRetrieve $ast The query being analyzed
		 * @param AstInterface $aggregation The aggregate function to evaluate
		 * @return bool True if subquery would be more efficient
		 */
		private function isSubqueryMoreEfficient(AstRetrieve $ast, AstInterface $aggregation): bool {
			// Conservative approach: prefer CASE WHEN over subqueries
			// CASE WHEN generally has better performance characteristics and is simpler
			return false;
		}
		
		/**
		 * Transforms conditional aggregate to CASE WHEN structure.
		 *
		 * Transformation pattern:
		 * Before: SUM(expression WHERE condition)
		 * After:  SUM(CASE WHEN condition THEN expression ELSE NULL END)
		 *
		 * This transformation maintains the same semantics while using standard SQL
		 * constructs that are better optimized by most database engines.
		 *
		 * @param AstInterface $aggregation The aggregate function to transform
		 */
		private function transformAggregateToCase(AstInterface $aggregation): void {
			$condition = $aggregation->getConditions();
			
			// Skip aggregates without conditions
			if ($condition === null) {
				return;
			}
			
			// Get the expression being aggregated
			$expression = $aggregation->getIdentifier();
			
			// Create CASE WHEN structure: CASE WHEN condition THEN expression ELSE NULL END
			$caseWhenExpression = new AstCase($condition, $expression);
			
			// Replace the aggregate's identifier with the CASE expression
			$aggregation->setIdentifier($caseWhenExpression);
			
			// Remove the condition from the aggregate since it's now in the CASE
			$aggregation->setConditions(null);
		}
		
		/**
		 * Transforms all conditional aggregates in SELECT values to CASE WHEN statements.
		 * Used specifically for single-range queries after condition promotion.
		 * @param AstRetrieve $ast The query to transform
		 */
		private function transformAggregatesToCaseWhen(AstRetrieve $ast): void {
			// Find all aggregate functions in the SELECT clause
			$aggregationNodes = $this->findAggregatesForValues($ast->getValues());
			
			// Transform each conditional aggregate
			foreach ($aggregationNodes as $aggregate) {
				if ($aggregate->getConditions() !== null) {
					// Fetch conditions
					$condition = $aggregate->getConditions();
					$expression = $aggregate->getExpression();
					
					// Create CASE WHEN expression
					$caseWhen = new AstCase($condition, $expression);
					
					// Replace the aggregate's expression and remove conditions
					$aggregate->setExpression($caseWhen);
					$aggregate->setConditions(null);
				}
			}
		}
		
		/**
		 * Determines if an aggregate function should be wrapped/optimized.
		 * @param AstRetrieve $ast The query context
		 * @param AstInterface $aggregate The aggregate to evaluate
		 * @return bool True if the aggregate should be optimized
		 */
		private function shouldWrapAggregation(AstRetrieve $ast, AstInterface $aggregate): bool {
			// No conditions means no optimization needed
			if ($aggregate->getConditions() === null) {
				return false;
			}
			
			// Single-range queries use CASE WHEN instead of wrapping
			if ($ast->isSingleRangeQuery(true)) {
				return false;
			}
			
			return true;
		}
		
		/**
		 * Finds all aggregate functions within the SELECT values.
		 * @param array $values Array of value expressions to search
		 * @return array Array of aggregate AST nodes found
		 */
		private function findAggregatesForValues(array $values): array {
			// Create visitor
			$visitor = new CollectNodes($this->aggregateTypes);
			
			// Visit each value expression to find aggregates
			foreach ($values as $value) {
				$value->accept($visitor);
			}
			
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Finds all aggregate functions within WHERE conditions.
		 * @param AstInterface|null $conditions The conditions to search (null if no WHERE clause)
		 * @return array Array of aggregate AST nodes found
		 */
		private function findAggregatesForConditions(?AstInterface $conditions = null): array {
			// No conditions to search
			if ($conditions === null) {
				return [];
			}
			
			$visitor = new CollectNodes($this->aggregateTypes);
			$conditions->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Checks if an AST node is an aggregate function.
		 * @param AstInterface $item The AST node to check
		 * @return bool True if the node is an aggregate function
		 */
		private function isAggregateNode(AstInterface $item): bool {
			return $item instanceof AstCount ||
				$item instanceof AstCountU ||
				$item instanceof AstAvg ||
				$item instanceof AstAvgU ||
				$item instanceof AstSum ||
				$item instanceof AstSumU ||
				$item instanceof AstMin ||
				$item instanceof AstMax ||
				$item instanceof AstAny;
		}
	}