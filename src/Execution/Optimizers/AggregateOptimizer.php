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
			if ($this->shouldUseSingleRangeOptimization($ast)) {
				$this->optimizeSingleRangeAggregates($ast);
			} else {
				$this->optimizeMultiRangeAggregates($ast);
			}
		}
		
		private function shouldUseSingleRangeOptimization(AstRetrieve $ast): bool {
			// Don't use single-range optimization if there are multiple ranges
			if (count($ast->getRanges()) > 1) {
				return false;
			}
			
			// If truly single range, safe to use single-range optimization
			return true;
		}
		
		/**
		 * Optimizes aggregate functions in single-range queries.
		 * Can apply more aggressive optimizations like moving conditions to WHERE clause.
		 * @param AstRetrieve $ast The single-range query AST
		 */
		private function optimizeSingleRangeAggregates(AstRetrieve $ast): void {
			// Check if we can promote an aggregate condition to the main WHERE clause
			$canPromoteCondition = $this->canPromoteAggregateCondition($ast);
			
			if ($canPromoteCondition) {
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
		
		private function canPromoteAggregateCondition(AstRetrieve $ast): bool {
			// Basic structural requirements
			if (count($ast->getValues()) !== 1) {
				return false;
			}
			
			$aggregateExpression = $ast->getValues()[0]->getExpression();
			
			if (!$this->isAggregateNode($aggregateExpression)) {
				return false;
			}
			
			if ($aggregateExpression->getConditions() === null) {
				return false;
			}
			
			if ($ast->getConditions() !== null) {
				return false;
			}
			
			// CRITICAL FIX 1: Check if aggregate references ranges that would be eliminated
			if (!$this->isAggregateSafeForConditionPromotion($ast, $aggregateExpression)) {
				return false;
			}
			
			// CRITICAL FIX 2: Verify that promoting conditions won't break range dependencies
			if ($this->wouldPromotionBreakRangeDependencies($ast, $aggregateExpression)) {
				return false;
			}
			
			return true;
		}
		
		private function isAggregateSafeForConditionPromotion(AstRetrieve $ast, AstInterface $aggregate): bool {
			// Get all ranges referenced by the aggregate
			$referencedRanges = $this->getAggregateReferencedRanges($aggregate);
			
			// If aggregate references multiple ranges, condition promotion is unsafe
			// because it could eliminate necessary joins
			if (count($referencedRanges) > 1) {
				return false;
			}
			
			// If aggregate references a range other than the main range, promotion is unsafe
			$mainRange = $this->getMainRange($ast);
			if (count($referencedRanges) === 1 && $referencedRanges[0] !== $mainRange) {
				return false;
			}
			
			// ANY functions specifically require range preservation
			if ($aggregate instanceof AstAny) {
				$identifier = $aggregate->getIdentifier();
				if ($identifier instanceof AstIdentifier && $identifier->getRange() !== $mainRange) {
					return false;
				}
			}
			
			return true;
		}
		
		private function wouldPromotionBreakRangeDependencies(AstRetrieve $ast, AstInterface $aggregate): bool {
			// Get ranges that have join conditions
			$joinedRanges = [];
			foreach ($ast->getRanges() as $range) {
				if ($range->getJoinProperty() !== null) {
					$joinedRanges[] = $range;
				}
			}
			
			// If there are joined ranges, we need to ensure the aggregate
			// doesn't depend on them in a way that would break with promotion
			foreach ($joinedRanges as $range) {
				if ($this->aggregateReferencesRange($aggregate, $range)) {
					return true;
				}
			}
			
			return false;
		}
		
		private function getAggregateReferencedRanges(AstInterface $aggregate): array {
			$ranges = [];
			
			// Check identifier reference
			if (method_exists($aggregate, 'getIdentifier')) {
				$identifier = $aggregate->getIdentifier();
				if ($identifier instanceof AstIdentifier) {
					$ranges[] = $identifier->getRange();
				}
			}
			
			// Check conditions for range references
			if (method_exists($aggregate, 'getConditions') && $aggregate->getConditions() !== null) {
				$conditionRanges = $this->extractRangesFromExpression($aggregate->getConditions());
				$ranges = array_merge($ranges, $conditionRanges);
			}
			
			return array_unique($ranges, SORT_REGULAR);
		}
		
		private function aggregateReferencesRange(AstInterface $aggregate, AstRange $targetRange): bool {
			$referencedRanges = $this->getAggregateReferencedRanges($aggregate);
			
			foreach ($referencedRanges as $range) {
				if ($range === $targetRange) {
					return true;
				}
			}
			
			return false;
		}
		
		private function extractRangesFromExpression(AstInterface $expression): array {
			$ranges = [];
			
			if ($expression instanceof AstIdentifier) {
				$ranges[] = $expression->getRange();
			}
			
			// Recursively check binary operations
			if (method_exists($expression, 'getLeft') && method_exists($expression, 'getRight')) {
				$leftRanges = $this->extractRangesFromExpression($expression->getLeft());
				$rightRanges = $this->extractRangesFromExpression($expression->getRight());
				$ranges = array_merge($ranges, $leftRanges, $rightRanges);
			}
			
			// Check other expression types
			if (method_exists($expression, 'getExpression') && $expression->getExpression() !== null) {
				$subRanges = $this->extractRangesFromExpression($expression->getExpression());
				$ranges = array_merge($ranges, $subRanges);
			}
			
			return $ranges;
		}
		
		private function getMainRange(AstRetrieve $ast): ?AstRange {
			foreach ($ast->getRanges() as $range) {
				if ($range->getJoinProperty() === null) {
					return $range;
				}
			}
			return null;
		}
		
		private function isTrulySingleRange(AstRetrieve $ast): bool {
			// Check if query structurally has only one range
			if (count($ast->getRanges()) === 1) {
				return true;
			}
			
			// Check if additional ranges are actually needed for aggregates
			$aggregates = $this->findAllAggregates($ast);
			
			foreach ($aggregates as $aggregate) {
				$referencedRanges = $this->getAggregateReferencedRanges($aggregate);
				if (count($referencedRanges) > 1) {
					return false; // Multi-range dependencies exist
				}
			}
			
			return false; // Conservative: if multiple ranges exist, treat as multi-range
		}
		
		private function findAllAggregates(AstRetrieve $ast): array {
			$valueAggregates = $this->findAggregatesForValues($ast->getValues());
			$conditionAggregates = $this->findAggregatesForConditions($ast->getConditions());
			return array_merge($valueAggregates, $conditionAggregates);
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
			// ANY aggregates always need processing, even without conditions
			if ($aggregate instanceof AstAny) {
				return true;
			}
			
			// Other aggregates only need processing if they have conditions
			if ($aggregate->getConditions() === null) {
				return false;
			}
			
			// Single range queries should not wrap
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