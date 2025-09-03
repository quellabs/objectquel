<?php
	
	namespace Quellabs\ObjectQuel\Execution\Optimizers;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectRanges;
	
	/**
	 * Query Optimizer for Aggregate Function Performance
	 *
	 * Transforms aggregate functions (SUM, COUNT, AVG, MIN, MAX) into optimized scalar
	 * subqueries to improve query performance. This optimization isolates aggregates with
	 * their minimal required data scope, reducing unnecessary JOINs and table scans.
	 *
	 * TRANSFORMATION EXAMPLE:
	 * Before: SELECT o.id, SUM(oi.price) FROM orders o JOIN order_items oi ON o.id = oi.order_id
	 * After:  SELECT o.id, (SELECT SUM(oi.price) FROM order_items oi WHERE oi.order_id = o.id)
	 *
	 * KEY FEATURES:
	 * - Minimizes JOIN complexity by isolating aggregate calculations
	 * - Preserves semantic correctness through correlation analysis
	 * - Handles both nullable (SUM, COUNT, AVG) and non-nullable (SUMU, COUNTU, AVGU) variants
	 * - Maintains original WHERE conditions within subquery scope
	 *
	 * LIMITATIONS:
	 * - COUNT(*) syntax not supported - use COUNT(expression) instead
	 * - Requires compatible RangeUsageAnalyzer for optimal performance
	 *
	 */
	class AggregateOptimizer {
		private EntityManager $entityManager;
		private AstUtilities $astUtilities;
		private AstNodeReplacer $astNodeReplacer;
		
		/**
		 * Registry of all supported aggregate function AST node types
		 *
		 * Includes both nullable and non-nullable variants:
		 * - SUM/SUMU: Arithmetic summation with/without null handling
		 * - COUNT/COUNTU: Row counting with/without null handling
		 * - AVG/AVGU: Arithmetic mean with/without null handling
		 * - MIN/MAX: Extrema functions (inherently null-safe)
		 * @var array<class-string>
		 */
		private array $aggregateTypes = [
			AstSum::class,
			AstSumU::class,
			AstCount::class,
			AstCountU::class,
			AstAvg::class,
			AstAvgU::class,
			AstMin::class,
			AstMax::class,
		];
		
		/**
		 * Registry of DISTINCT aggregate functions
		 *
		 * These aggregates eliminate duplicate values before calculation:
		 * - SUMU: SUM with DISTINCT values
		 * - AVGU: AVG with DISTINCT values
		 * - COUNTU: COUNT with DISTINCT values
		 *
		 * @var array<class-string>
		 */
		private array $distinctClasses = [
			AstSumU::class,
			AstAvgU::class,
			AstCountU::class
		];
		
		/**
		 * Initialize optimizer with required dependencies
		 *
		 * @param EntityManager $entityManager Provides access to entity metadata and storage layer
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityManager = $entityManager;
			$this->astUtilities = new AstUtilities();
			$this->astNodeReplacer = new AstNodeReplacer();
		}
		
		/**
		 * Main optimization entry point - processes entire AST for aggregate functions
		 *
		 * Analyzes the query structure and applies the most appropriate optimization strategy:
		 * - DIRECT: Keep aggregate in main query with potential GROUP BY
		 * - SUBQUERY: Extract aggregate into correlated subquery
		 * - WINDOW: Convert to window function for single-table queries
		 *
		 * @param AstRetrieve $ast Root query AST node to optimize
		 */
		public function optimize(AstRetrieve $ast): void {
			// Eliminate ranges
			$this->eliminateUnusedRanges($ast);
			
			// Get all aggregates in one pass using visitor pattern
			$aggregates = $this->findAllAggregateNodes($ast);
			
			// Determine and apply optimization strategy for each aggregate
			foreach ($aggregates as $aggregate) {
				switch ($this->determineStrategy($ast, $aggregate)) {
					case 'DIRECT' :
						// Keep aggregate in main query
						// Add GROUP BY if needed (mixed agg + non-agg)
						if ($this->needsGroupBy($ast)) {
							$this->addGroupByClause($ast);
						}

						break;
					
					case 'SUBQUERY':
						$this->convertToSubquery($ast, $aggregate);
						break;
					
					case 'WINDOW':
						$this->convertToWindowFunction($aggregate);
						break;
				}
			}
		}
		
		/**
		 * Converts an aggregate function into a correlated scalar subquery
		 *
		 * Process:
		 * 1. Identify which table ranges the aggregate depends on
		 * 2. Find minimal set of ranges needed for proper correlation
		 * 3. Clone ranges to avoid mutating original query structure
		 * 4. Extract aggregate conditions for subquery WHERE clause
		 * 5. Create clean aggregate without embedded conditions
		 * 6. Build correlated subquery and replace original aggregate
		 *
		 * Example transformation:
		 * FROM: SELECT o.id, SUM(oi.quantity * oi.price)
		 * TO:   SELECT o.id, (SELECT SUM(oi.quantity * oi.price) FROM order_items oi WHERE oi.order_id = o.id)
		 *
		 * @param AstRetrieve $ast The main query AST
		 * @param AstInterface $aggregate The aggregate function to convert
		 */
		private function convertToSubquery(AstRetrieve $ast, AstInterface $aggregate): void {
			// 1. Find which ranges the aggregate uses (tables/entities it references)
			$aggregateRanges = $this->getRangesUsedInExpression($aggregate);
			
			// 2. Get all available ranges from the main query context
			$allRanges = $ast->getRanges();
			
			// 3. Find the minimal set of ranges needed for the subquery
			// This includes direct dependencies and join dependencies
			$requiredRanges = $this->findMinimalRangeSet($allRanges, $aggregateRanges);
			
			// 4. Clone ranges to avoid mutating the original query structure
			// Deep cloning ensures we don't affect the main query's range objects
			$subqueryRanges = array_map(fn($r) => $r->deepClone(), $requiredRanges);
			
			// 5. Extract aggregate's conditions for subquery WHERE clause
			// These conditions will be applied within the subquery scope
			$subqueryWhere = $aggregate->getConditions();
			
			// 6. Create clean aggregate without embedded conditions
			// The conditions are moved to the subquery WHERE clause
			$cleanAggregate = $this->deepCloneAggregateWithoutConditions($aggregate);
			
			// 7. Create correlated scalar subquery and replace original aggregate
			$subquery = new AstSubquery(
				AstSubquery::TYPE_SCALAR,    // Scalar subquery returns single value
				$cleanAggregate,             // The aggregate function to execute
				$subqueryRanges,            // Tables/ranges needed for the subquery
				$subqueryWhere              // WHERE conditions for the subquery
			);
			
			// Replace the original aggregate with the subquery in the AST
			$this->astNodeReplacer->replaceChild($aggregate->getParent(), $aggregate, $subquery);
		}
		
		/**
		 * Converts an aggregate function into a window function
		 *
		 * Window functions are ideal for single-table queries where we want
		 * to compute aggregates while preserving individual row details.
		 *
		 * Example transformation:
		 * FROM: SELECT emp_id, salary, AVG(salary) FROM employees
		 * TO:   SELECT emp_id, salary, AVG(salary) OVER() FROM employees
		 *
		 * @param AstInterface $aggregate The aggregate function to convert
		 */
		private function convertToWindowFunction(AstInterface $aggregate): void {
			// Create a window function subquery wrapping the clean aggregate
			$cleanAggregate = $this->deepCloneAggregateWithoutConditions($aggregate);
			
			$windowFunction = new AstSubquery(
				AstSubquery::TYPE_WINDOW,   // Window function type
				$cleanAggregate,            // The aggregate function
				[],                        // No ranges needed - window operates on result set
				null                       // No WHERE clause - conditions stay in outer query
			);
			
			// Replace the original aggregate with the window function
			$this->astNodeReplacer->replaceChild($aggregate->getParent(), $aggregate, $windowFunction);
		}
		
		/**
		 * Creates a deep clone of an aggregate node without its conditions
		 *
		 * This is essential for subquery conversion where conditions need to be
		 * moved to the subquery's WHERE clause rather than embedded in the aggregate.
		 *
		 * @param AstInterface $aggregate The aggregate to clone
		 * @return AstInterface Clean aggregate clone without conditions
		 */
		private function deepCloneAggregateWithoutConditions(AstInterface $aggregate): AstInterface {
			// Create a deep clone of the aggregate node to avoid mutation
			$clone = $aggregate->deepClone();
			
			// Remove any embedded conditions - these will be handled separately
			$clone->setConditions(null);
			
			return $clone;
		}
		
		/**
		 * Determines the minimal set of ranges (tables) needed for a subquery
		 *
		 * This analysis is crucial for creating efficient subqueries that include
		 * all necessary tables while avoiding unnecessary JOINs. The algorithm
		 * recursively follows JOIN dependencies to ensure semantic correctness.
		 *
		 * @param array $allRanges All ranges available in the main query
		 * @param array $aggregateRanges Ranges directly used by the aggregate
		 * @return array Minimal set of ranges needed for the subquery
		 */
		private function findMinimalRangeSet(array $allRanges, array $aggregateRanges): array {
			$required = [];      // Ranges that must be included
			$processed = [];     // Ranges we've already analyzed (prevents infinite loops)
			
			// Start with ranges directly used by the aggregate
			foreach ($aggregateRanges as $range) {
				$this->addRangeWithDependencies($range, $allRanges, $required, $processed);
			}
			
			return $required;
		}
		
		/**
		 * Recursively adds a range and all its JOIN dependencies to the required set
		 *
		 * This method ensures that if we include a table in our subquery, we also
		 * include any tables it depends on through JOIN conditions. This maintains
		 * referential integrity and prevents broken queries.
		 *
		 * @param AstRange $range The range to add
		 * @param array $allRanges All available ranges for dependency resolution
		 * @param array &$required Reference to required ranges array (modified)
		 * @param array &$processed Reference to processed ranges array (prevents loops)
		 */
		private function addRangeWithDependencies(AstRange $range, array $allRanges, array &$required, array &$processed): void {
			// Avoid infinite loops in circular JOIN dependencies
			if (in_array($range, $processed, true)) {
				return;
			}
			
			// Mark this range as processed
			$processed[] = $range;
			
			// Add the range itself to required set (avoid duplicates)
			if (!in_array($range, $required, true)) {
				$required[] = $range;
			}
			
			// If this range has a JOIN condition, analyze its dependencies
			if ($range->getJoinProperty()) {
				$joinCondition = $range->getJoinProperty();
				
				// Find what other ranges this JOIN condition references
				$referencedRanges = $this->getRangesUsedInExpression($joinCondition);
				
				// Recursively add each referenced range and its dependencies
				foreach ($referencedRanges as $referencedRange) {
					if ($referencedRange !== $range) { // Prevent self-reference
						$this->addRangeWithDependencies($referencedRange, $allRanges, $required, $processed);
					}
				}
			}
		}
		
		/**
		 * Finds all aggregate function nodes in the query AST
		 *
		 * Uses the visitor pattern to traverse the entire AST and collect
		 * all instances of aggregate functions (SUM, COUNT, AVG, MIN, MAX, etc.).
		 *
		 * @param AstRetrieve $ast The query AST to search
		 * @return array All aggregate nodes found in the query
		 */
		private function findAllAggregateNodes(AstRetrieve $ast): array {
			// Use visitor pattern to collect all aggregate nodes
			$visitor = new CollectNodes($this->aggregateTypes);
			$ast->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Determines the optimal optimization strategy for an aggregate function
		 *
		 * Strategy Selection Rules (in priority order):
		 * 1. SUBQUERY: If aggregate has embedded conditions (highest priority)
		 * 2. DIRECT: If query contains only aggregates (pure aggregation)
		 * 3. DIRECT: If aggregate and non-aggregate ranges overlap (shared tables)
		 * 4. SUBQUERY: If aggregate and non-aggregate ranges are disjoint
		 * 5. WINDOW: If single table with no conditions (performance optimization)
		 * 6. SUBQUERY: Default fallback for complex cases
		 *
		 * @param AstRetrieve $ast The main query AST
		 * @param AstInterface $aggregate The aggregate function to analyze
		 * @return string Strategy name: 'DIRECT', 'SUBQUERY', or 'WINDOW'
		 */
		private function determineStrategy(AstRetrieve $ast, AstInterface $aggregate): string {
			// Rule 0: Aggregate has conditions = must be subquery (highest priority)
			// Conditions need to be isolated in their own WHERE clause
			if ($aggregate->getConditions() !== null) {
				return 'SUBQUERY';
			}
			
			// Rule 1: Pure aggregation query (only aggregates in SELECT)
			// No need for complex optimization when everything is an aggregate
			if ($this->isOnlyAggregatesInSelect($ast)) {
				return 'DIRECT';
			}
			
			// Rule 2: Mixed SELECT with aggregates + non-aggregates
			// Need to analyze range relationships to determine strategy
			$nonAggregates = $this->getNonAggregateSelectItems($ast);
			
			if (!empty($nonAggregates)) {
				// Get ranges used by aggregate and non-aggregate expressions
				$aggRanges = $this->getRangesUsedInExpression($aggregate);
				$nonAggRanges = $this->getRangesUsedInExpressions($nonAggregates);
				
				// If ranges overlap, keep together in main query (DIRECT)
				if ($this->hasRangeOverlap($aggRanges, $nonAggRanges)) {
					return 'DIRECT';
				}
				
				// If ranges are completely separate, isolate aggregate (SUBQUERY)
				if ($this->areRangesDisjoint($aggRanges, $nonAggRanges)) {
					return 'SUBQUERY';
				}
			}
			
			// Rule 3: Window function candidate
			// Single table queries with simple aggregates benefit from window functions
			if ($this->canBeWindowFunction($ast, $aggregate)) {
				return 'WINDOW';
			}
			
			// Default fallback for complex or ambiguous cases
			return 'SUBQUERY';
		}
		
		/**
		 * Gets all non-aggregate SELECT items from the query
		 *
		 * Identifies SELECT expressions that are NOT aggregate functions.
		 * This is used to analyze mixed queries that contain both aggregates
		 * and regular column references.
		 *
		 * @param AstRetrieve $retrieve The query AST
		 * @return array Non-aggregate SELECT items
		 */
		private function getNonAggregateSelectItems(AstRetrieve $retrieve): array {
			$result = [];
			
			// Check each SELECT item's expression type
			foreach ($retrieve->getValues() as $value) {
				// If it's not one of our known aggregate types, include it
				if (
					!$value->getExpression() instanceof AstSum &&
					!$value->getExpression() instanceof AstSumU &&
					!$value->getExpression() instanceof AstAvg &&
					!$value->getExpression() instanceof AstAvgU &&
					!$value->getExpression() instanceof AstCount &&
					!$value->getExpression() instanceof AstCountU &&
					!$value->getExpression() instanceof AstMax &&
					!$value->getExpression() instanceof AstMin
				) {
					$result[] = $value;
				}
			}
			
			return $result;
		}
		
		/**
		 * Checks if the query SELECT clause contains only aggregate functions
		 *
		 * Pure aggregation queries (e.g., "SELECT SUM(x), COUNT(y) FROM table")
		 * can be handled more efficiently with the DIRECT strategy since there
		 * are no individual row details to preserve.
		 *
		 * @param AstRetrieve $retrieve The query AST
		 * @return bool True if all SELECT items are aggregates
		 */
		private function isOnlyAggregatesInSelect(AstRetrieve $retrieve): bool {
			foreach ($retrieve->getValues() as $value) {
				if (!$this->isAggregateExpression($value->getExpression())) {
					return false;
				}
			}
			
			return true;
		}
		
		/**
		 * Determines if an expression is an aggregate function
		 *
		 * @param AstInterface $expression The expression to check
		 * @return bool True if the expression is a supported aggregate function
		 */
		private function isAggregateExpression(AstInterface $expression): bool {
			return in_array(get_class($expression), $this->aggregateTypes, true);
		}
		
		/**
		 * Finds all table ranges (entities) referenced by an expression
		 * @param AstInterface $expression The expression to analyze
		 * @return array Array of AstRange objects referenced by the expression
		 */
		private function getRangesUsedInExpression(AstInterface $expression): array {
			$visitor = new CollectRanges();
			$expression->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Finds all table ranges referenced by multiple expressions
		 *
		 * Aggregates range collection across multiple expressions, useful for
		 * analyzing the combined range usage of SELECT items or other expression lists.
		 *
		 * @param array $expressions Array of expressions to analyze
		 * @return array Array of all unique AstRange objects referenced
		 */
		private function getRangesUsedInExpressions(array $expressions): array {
			$visitor = new CollectRanges();
			
			// Visit each expression to collect all ranges
			foreach ($expressions as $expression) {
				$expression->accept($visitor);
			}
			
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Determines if two sets of ranges have any overlap or relationships
		 *
		 * This method checks for:
		 * 1. Direct overlap: Same range objects used in both sets
		 * 2. Related ranges: Ranges connected through JOIN conditions
		 *
		 * Range relationships are critical for optimization decisions since
		 * related ranges should typically be kept together in the same query scope.
		 *
		 * @param array $aggRanges Ranges used by aggregate functions
		 * @param array $nonAggRanges Ranges used by non-aggregate expressions
		 * @return bool True if ranges overlap or are related through JOINs
		 */
		private function hasRangeOverlap(array $aggRanges, array $nonAggRanges): bool {
			// Direct overlap: same range objects used in both sets
			foreach ($aggRanges as $aggRange) {
				foreach ($nonAggRanges as $nonAggRange) {
					if ($aggRange === $nonAggRange) {
						return true;
					}
				}
			}
			
			// Related ranges: connected via JOIN conditions
			return $this->areRangesRelated($aggRanges, $nonAggRanges);
		}
		
		/**
		 * Checks if two sets of ranges are completely disjoint (no overlap or relationships)
		 *
		 * Disjoint ranges indicate that aggregate and non-aggregate expressions
		 * operate on completely separate table sets, making subquery isolation beneficial.
		 *
		 * @param array $aggRanges Ranges used by aggregate functions
		 * @param array $nonAggRanges Ranges used by non-aggregate expressions
		 * @return bool True if ranges are completely disjoint
		 */
		private function areRangesDisjoint(array $aggRanges, array $nonAggRanges): bool {
			// If they overlap or are related, they're NOT disjoint
			return !$this->hasRangeOverlap($aggRanges, $nonAggRanges);
		}
		
		/**
		 * Determines if two sets of ranges are related through JOIN conditions
		 *
		 * Analyzes JOIN relationships to determine if ranges in different sets
		 * are connected. This is crucial for maintaining query semantics when
		 * deciding whether to separate aggregates into subqueries.
		 *
		 * @param array $ranges1 First set of ranges
		 * @param array $ranges2 Second set of ranges
		 * @return bool True if any ranges are connected through JOINs
		 */
		private function areRangesRelated(array $ranges1, array $ranges2): bool {
			// Check all combinations of ranges from both sets
			foreach ($ranges1 as $range1) {
				foreach ($ranges2 as $range2) {
					if ($this->rangesAreJoined($range1, $range2)) {
						return true;
					}
				}
			}

			return false;
		}
		
		/**
		 * Checks if two specific ranges are connected through JOIN conditions
		 *
		 * Examines the JOIN properties of both ranges to determine if they
		 * reference each other. JOIN relationships can be directional, so
		 * both directions are checked.
		 *
		 * @param AstRange $range1 First range to check
		 * @param AstRange $range2 Second range to check
		 * @return bool True if ranges are joined together
		 */
		private function rangesAreJoined(AstRange $range1, AstRange $range2): bool {
			// Check if range2 joins to range1
			$joinCondition = $range2->getJoinProperty();
			
			if ($joinCondition && $this->joinReferences($joinCondition, $range1)) {
				return true;
			}
			
			// Check reverse direction: range1 joins to range2
			$joinCondition = $range1->getJoinProperty();
			
			if ($joinCondition && $this->joinReferences($joinCondition, $range2)) {
				return true;
			}
			
			return false;
		}
		
		/**
		 * Checks if a JOIN condition references a specific range
		 *
		 * Analyzes the identifiers used in a JOIN condition to determine
		 * if any of them belong to the target range. This helps establish
		 * JOIN relationships between ranges.
		 *
		 * @param AstInterface $joinCondition The JOIN condition to analyze
		 * @param AstRange $targetRange The range to check for references
		 * @return bool True if the JOIN condition references the target range
		 */
		private function joinReferences(AstInterface $joinCondition, AstRange $targetRange): bool {
			// Get all identifiers used in the JOIN condition
			$identifiers = $this->astUtilities->collectIdentifiersFromAst($joinCondition);
			
			// Check if any identifier belongs to the target range
			foreach ($identifiers as $identifier) {
				if ($identifier->getRange() === $targetRange) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Determines if an aggregate can be converted to a window function
		 *
		 * Window functions are an optimization for single-table queries where we want
		 * to compute aggregates while preserving individual row details. They're more
		 * efficient than correlated subqueries for this use case.
		 *
		 * Requirements for window function conversion:
		 * 1. Passes basic compatibility checks (no conditions, supported type, etc.)
		 * 2. Single-table query (no meaningful JOINs)
		 * 3. Aggregate references only the single table
		 * 4. All SELECT items reference the same single table
		 *
		 * @param AstRetrieve $ast The main query AST
		 * @param AstInterface $aggregate The aggregate function to check
		 * @return bool True if the aggregate can be converted to a window function
		 */
		private function canBeWindowFunction(AstRetrieve $ast, AstInterface $aggregate): bool {
			// 1. Basic compatibility checks (type, conditions, database support)
			if (!$this->passesBasicWindowChecks($aggregate)) {
				return false;
			}
			
			// 2. Must be a single-table query (no meaningful JOINs for window context)
			$ranges = $ast->getRanges();
			
			if (count($ranges) !== 1) {
				return false;
			}
			
			// 3. The aggregate must reference the same single range
			$aggRanges = $this->getRangesUsedInExpression($aggregate);
			
			if (count($aggRanges) !== 1 || $aggRanges[0] !== $ranges[0]) {
				return false;
			}
			
			// 4. All SELECT items must reference the same range (no cross-table mixing)
			$selectItems = $ast->getValues();
			
			foreach ($selectItems as $item) {
				// Skip the aggregate we're analyzing
				if ($item->getExpression() === $aggregate) {
					continue;
				}
				
				$itemRanges = $this->getRangesUsedInExpression($item);
				
				// Each SELECT item must reference exactly the same single range
				if (count($itemRanges) !== 1 || $itemRanges[0] !== $ranges[0]) {
					return false;
				}
			}
			
			return true;
		}
		
		/**
		 * Performs basic compatibility checks for window function conversion
		 *
		 * Validates that an aggregate function meets the fundamental requirements
		 * for window function conversion before performing more complex analysis.
		 *
		 * @param AstInterface $aggregate The aggregate function to check
		 * @return bool True if aggregate passes basic window function checks
		 */
		private function passesBasicWindowChecks(AstInterface $aggregate): bool {
			// Check 1: No aggregate-level conditions (WHERE/HAVING clauses)
			// Window functions can't have their own filtering conditions
			if ($aggregate->getConditions() !== null) {
				return false;
			}
			
			// Check 2: No DISTINCT variants (SUMU, COUNTU, AVGU)
			// Most databases don't support DISTINCT in window function context
			if (in_array(get_class($aggregate), $this->distinctClasses, true)) {
				return false;
			}
			
			// Check 3: Database must support window functions
			// Older MySQL versions, some SQLite configurations don't support them
			if (!$this->entityManager->getConnection()->supportsWindowFunctions()) {
				return false;
			}
			
			// Check 4: Must be a supported aggregate type for window functions
			// Some custom aggregates might not work as window functions
			if (!$this->isSupportedWindowAggregate($aggregate)) {
				return false;
			}
			
			return true;
		}
		
		/**
		 * Checks if an aggregate type is supported for window function conversion
		 *
		 * Not all aggregate functions work well as window functions. This method
		 * maintains a whitelist of aggregate types that are known to work correctly
		 * and efficiently as window functions across different database systems.
		 *
		 * Supported types are the standard aggregates without DISTINCT modifiers:
		 * - SUM: Arithmetic summation
		 * - COUNT: Row counting
		 * - AVG: Arithmetic mean
		 * - MIN: Minimum value
		 * - MAX: Maximum value
		 *
		 * @param AstInterface $aggregate The aggregate function to check
		 * @return bool True if the aggregate type is supported for window functions
		 */
		private function isSupportedWindowAggregate(AstInterface $aggregate): bool {
			// Standard aggregates that work well as window functions
			// Excludes DISTINCT variants which have limited window function support
			$supportedTypes = [
				AstSum::class,    // SUM(expression) OVER(...)
				AstCount::class,  // COUNT(expression) OVER(...)
				AstAvg::class,    // AVG(expression) OVER(...)
				AstMin::class,    // MIN(expression) OVER(...)
				AstMax::class,    // MAX(expression) OVER(...)
			];
			
			return in_array(get_class($aggregate), $supportedTypes, true);
		}
		
		
		private function needsGroupBy(AstRetrieve $ast): bool {
			return !$this->isOnlyAggregatesInSelect($ast);
		}
		
		private function addGroupByClause(AstRetrieve $ast): void {
			// Add GROUP BY for all non-aggregate SELECT items
			$nonAggregates = $this->getNonAggregateSelectItems($ast);
			
			// Set the group by clause
			$ast->setGroupBy($nonAggregates);
		}
		
		private function eliminateUnusedRanges(AstRetrieve $ast): void {
			if ($this->isOnlyAggregatesInSelect($ast)) {
				$usedRanges = $this->getAllRangesUsedInSelect($ast);
				
				$unusedRanges = array_udiff(
					$ast->getRanges(),
					$usedRanges,
					static function ($a, $b): int {
						return $a == $b ? 0 : -1; // or use === if instances must match exactly
					}
				);
				
				foreach ($unusedRanges as $unused) {
					$ast->removeRange($unused);
				}
				
				// Move range conditions to query conditions
				if (count($ast->getRanges()) == 1) {
					$queryConditions = $ast->getConditions();
					$rangeConditions = $ast->getRanges()[0]->getJoinProperty();
					
					if ($rangeConditions !== null) {
						if ($queryConditions !== null) {
							$queryConditions = new AstBinaryOperator($queryConditions, $rangeConditions, "AND");
						} else {
							$queryConditions = $rangeConditions;
						}
						
						$ast->setConditions($queryConditions);
						$ast->getRanges()[0]->setJoinProperty(null);
					}
				}
			}
		}
		
		/**
		 * Finds all table ranges (entities) referenced by an expression
		 * @param AstRetrieve $retrieve
		 * @return array Array of AstRange objects referenced by the expression
		 */
		private function getAllRangesUsedInSelect(AstRetrieve $retrieve): array {
			$visitor = new CollectRanges();
			
			foreach($retrieve->getValues() as $value) {
				$value->accept($visitor);
			}
			
			return $visitor->getCollectedNodes();
		}
		
	}