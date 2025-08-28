<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors\Handlers;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\QuelToSQLConvertToString;
	
	/**
	 * Handles conversion of ObjectQuel aggregate AST nodes to SQL aggregate functions and EXISTS queries.
	 *
	 * This class is responsible for converting various aggregate operations (COUNT, SUM, AVG, MIN, MAX, ANY)
	 * from ObjectQuel AST format into optimized SQL queries. It supports both regular and DISTINCT variants
	 * of aggregate functions and can optimize queries using either JOINs or subqueries based on the context.
	 *
	 * Key features:
	 * - Supports all standard SQL aggregate functions
	 * - Handles DISTINCT operations (COUNT UNIQUE, SUM UNIQUE, etc.)
	 * - Optimizes ANY operations based on query context (WHERE vs VALUES)
	 * - Can generate either JOIN-based or subquery-based SQL depending on optimization opportunities
	 *
	 * @package Quellabs\ObjectQuel\ObjectQuel\Visitors\Handlers
	 */
	class AggregateHandler {
		
		/** @var EntityStore Store for entity metadata and table mappings */
		private EntityStore $entityStore;
		
		/** @var string Current part of the query being built (e.g., 'VALUES', 'WHERE') */
		private string $partOfQuery;
		
		/** @var SqlBuilderHelper Helper for building SQL components */
		private SqlBuilderHelper $sqlBuilder;
		
		/** @var QuelToSQLConvertToString Converter for AST nodes to SQL strings */
		private QuelToSQLConvertToString $convertToString;
		
		/**
		 * Initializes the aggregate handler with required dependencies.
		 * @param EntityStore $entityStore Store containing entity-to-table mappings and metadata
		 * @param string $partOfQuery Which part of the query is currently being built (VALUES, WHERE, etc.)
		 * @param SqlBuilderHelper $sqlBuilder Helper class for constructing SQL components
		 * @param QuelToSQLConvertToString $convertToString Converter for transforming AST nodes to SQL strings
		 */
		public function __construct(
			EntityStore              $entityStore,
			string                   $partOfQuery,
			SqlBuilderHelper         $sqlBuilder,
			QuelToSQLConvertToString $convertToString,
		) {
			$this->entityStore = $entityStore;
			$this->partOfQuery = $partOfQuery;
			$this->sqlBuilder = $sqlBuilder;
			$this->convertToString = $convertToString;
		}
		
		/**
		 * Converts AstCount nodes to SQL COUNT() functions.
		 * @param AstCount $count The COUNT AST node to process
		 * @return string Generated SQL COUNT expression
		 */
		public function handleCount(AstCount $count): string {
			return $this->handleAggregateOperation($count, 'COUNT');
		}
		
		/**
		 * Converts AstCountU nodes to SQL COUNT(DISTINCT) functions.
		 * Handles unique counting operations, ensuring only distinct values are counted.
		 * @param AstCountU $count The COUNT UNIQUE AST node to process
		 * @return string Generated SQL COUNT(DISTINCT) expression
		 */
		public function handleCountU(AstCountU $count): string {
			return $this->handleAggregateOperation($count, 'COUNT', true);
		}
		
		/**
		 * Converts AstAvg nodes to SQL AVG() functions.
		 * Handles average calculation operations for numeric values.
		 * @param AstAvg $avg The AVG AST node to process
		 * @return string Generated SQL AVG expression
		 */
		public function handleAvg(AstAvg $avg): string {
			return $this->handleAggregateOperation($avg, 'AVG');
		}
		
		/**
		 * Converts AstAvgU nodes to SQL AVG(DISTINCT) functions.
		 * Handles average calculation for distinct values only.
		 * @param AstAvgU $avg The AVG UNIQUE AST node to process
		 * @return string Generated SQL AVG(DISTINCT) expression
		 */
		public function handleAvgU(AstAvgU $avg): string {
			return $this->handleAggregateOperation($avg, 'AVG', true);
		}
		
		/**
		 * Converts AstMax nodes to SQL MAX() functions.
		 * Handles maximum value finding operations.
		 * @param AstMax $max The MAX AST node to process
		 * @return string Generated SQL MAX expression
		 */
		public function handleMax(AstMax $max): string {
			return $this->handleAggregateOperation($max, 'MAX');
		}
		
		/**
		 * Converts AstMin nodes to SQL MIN() functions.
		 * Handles minimum value finding operations.
		 * @param AstMin $min The MIN AST node to process
		 * @return string Generated SQL MIN expression
		 */
		public function handleMin(AstMin $min): string {
			return $this->handleAggregateOperation($min, 'MIN');
		}
		
		/**
		 * Converts AstSum nodes to SQL SUM() functions.
		 * Handles summation operations with automatic NULL handling (COALESCE to 0).
		 * @param AstSum $sum The SUM AST node to process
		 * @return string Generated SQL SUM expression
		 */
		public function handleSum(AstSum $sum): string {
			return $this->handleAggregateOperation($sum, 'SUM');
		}
		
		/**
		 * Converts AstSumU nodes to SQL SUM(DISTINCT) functions.
		 * Handles summation of distinct values only, with automatic NULL handling.
		 * @param AstSumU $sum The SUM UNIQUE AST node to process
		 * @return string Generated SQL SUM(DISTINCT) expression
		 */
		public function handleSumU(AstSumU $sum): string {
			return $this->handleAggregateOperation($sum, "SUM", true);
		}
		
		/**
		 * Converts AstAny nodes to appropriate SQL existence checks.
		 * @param AstAny $ast The ANY AST node to process
		 * @return string Generated SQL expression for existence check
		 */
		public function handleAny(AstAny $ast): string {
			return $this->handleAnyOptimized($ast->getIdentifier());
		}
		
		// ============================================================================
		// CORE AGGREGATE PROCESSING
		// ============================================================================
		
		/**
		 * Universal handler for all aggregate functions including ANY operations.
		 * @param AstAny|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $ast The aggregate AST node
		 * @param string $aggregateFunction The SQL aggregate function name (COUNT, SUM, etc.)
		 * @param bool $distinct Whether to add DISTINCT clause for unique operations
		 * @return string Generated SQL aggregate expression
		 */
		private function handleAggregateOperation(
			AstAny|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $ast,
			string                                                                $aggregateFunction,
			bool                                                                  $distinct = false
		): string {
			// Fetch the expression
			$expression = $ast->getIdentifier();
			
			// Handle ANY operations with specialized logic
			if ($aggregateFunction === 'ANY') {
				return $this->handleAnyOptimized($expression);
			}
			
			// Try to optimize standard aggregates using subqueries
			// If not possible, fall back to JOIN-based approach
			if ($this->canOptimizeToSubquery($expression)) {
				return $this->buildAggregateSubquery($expression, $aggregateFunction, $distinct);
			} else {
				return $this->buildJoinBasedAggregate($expression, $aggregateFunction, $distinct);
			}
		}
		
		/**
		 * Builds JOIN-based aggregate expressions when subquery optimization isn't possible.
		 * @param AstInterface $expression The expression to aggregate
		 * @param string $aggregateFunction SQL function name (COUNT, SUM, etc.)
		 * @param bool $distinct Whether to include DISTINCT clause
		 * @return string SQL aggregate expression using JOINs
		 */
		private function buildJoinBasedAggregate(AstInterface $expression, string $aggregateFunction, bool $distinct): string {
			// Convert the AST expression into its SQL string representation
			$sqlExpression = $this->convertExpressionToSql($expression);
			
			// Prepare DISTINCT clause if needed - adds "DISTINCT " with trailing space
			$distinctClause = $distinct ? 'DISTINCT ' : '';
			
			// Apply function-specific formatting and NULL handling
			if ($aggregateFunction === 'SUM') {
				// For SUM operations, wrap with COALESCE to convert NULL results to 0
				// This ensures SUM always returns a numeric value instead of NULL
				// when there are no matching rows or all values are NULL
				return "COALESCE({$aggregateFunction}({$distinctClause}{$sqlExpression}), 0)";
			} else {
				// For other aggregate functions (COUNT, AVG, MAX, MIN, etc.),
				// use standard formatting without NULL coalescing
				// COUNT typically returns 0 for empty sets, while AVG/MAX/MIN return NULL
				return "{$aggregateFunction}({$distinctClause}{$sqlExpression})";
			}
		}
		
		// ============================================================================
		// ANY OPERATION SPECIALIZED HANDLERS
		// ============================================================================
		
		/**
		 * This method determines the most efficient way to check for existence
		 * based on the current query's JOIN structure and requirements.
		 * @param AstInterface $expression The expression to check for existence
		 * @return string Optimized SQL existence check
		 */
		private function handleAnyOptimized(AstInterface $expression): string {
			// Collect all identifier nodes (table/column references) from the AST expression
			// This helps understand what entities are being referenced in the query
			$identifiers = $this->collectIdentifierNodes($expression);
			
			// Extract all range specifications (table sources, subqueries, etc.)
			// These define the data sources that the expression operates on
			$ranges = $this->extractAllRanges($expression);
			
			// Convert the abstract syntax tree expression into executable SQL
			// This creates the base SQL that would be used if no optimizations apply
			$sqlExpression = $this->convertExpressionToSql($expression);
			
			// Apply optimization rules if identifiers are present
			if (!empty($identifiers)) {
				// Check if this expression can be optimized to a constant true condition
				// This happens when the query structure guarantees the existence check will always pass
				// For example, when checking existence on a table that's already JOINed in the main query
				if ($this->canOptimizeToConstantTrue($expression, $identifiers, $ranges)) {
					// Return "1" as a SQL constant true value - this is much more efficient
					// than executing a complex EXISTS subquery
					return "1";
				}
			}
			
			// Fall back to complex query analysis
			// If no simple optimizations apply, use the full complex query handling
			// This will generate a proper EXISTS subquery or similar construct
			return $this->handleComplexQueryScenario($expression, $sqlExpression);
		}
		
		/**
		 * Determines if the query can be optimized to return constant true (1)
		 * based on various optimization scenarios.
		 * @param AstInterface $expression The full expression being analyzed
		 * @param array $identifiers Array of identifier nodes found in expression
		 * @param array $ranges Array of ranges extracted from expression
		 * @return bool True if query can be optimized to constant true
		 */
		private function canOptimizeToConstantTrue(AstInterface $expression, array $identifiers, array $ranges): bool {
			// 1. Single range queries - if only one range involved, existence is guaranteed
			$isSingleRange = !empty($identifiers) && $this->isSingleRangeQuery($identifiers[0]);
			
			// 2. Equivalent range scenarios (same entity with simple equality joins)
			$isEquivalentRange = $this->isEquivalentRangeScenario($expression);
			
			// 3. Base range references (no joins) - always exist in query context
			$isBaseRangeReference = $this->isBaseRangeReference($ranges);
			
			// 4. Same entity self-joins with simple equality conditions
			$isSameEntitySelfJoin = $this->isSameEntitySelfJoin($expression, $ranges);
			
			// 5. NEW: All ranges are required AND already joined in main query
			$rangesAlreadyRequiredJoined = $this->areRangesAlreadyRequiredJoined($ranges);
			
			// 6. NEW: Expression references only required relationships
			// This catches cases where all join conditions are INNER JOINs
			$allRelationshipsRequired = $this->allRelationshipsAreRequired($expression, $ranges);
			
			return $isSingleRange
				|| $isEquivalentRange
				|| $isBaseRangeReference
				|| $isSameEntitySelfJoin
				|| $rangesAlreadyRequiredJoined
				|| $allRelationshipsRequired;
		}
		
		/**
		 * NEW METHOD: Checks if all relationships in the expression are required (INNER JOIN)
		 * @param AstInterface $expression The expression to analyze
		 * @param array $ranges Array of ranges to check
		 * @return bool True if all relationships are required
		 */
		private function allRelationshipsAreRequired(AstInterface $expression, array $ranges): bool {
			// If no ranges, nothing to check
			if (empty($ranges)) {
				return false;
			}
			
			// Check if ALL ranges are either:
			// 1. Base ranges (no join property) - always required
			// 2. Required joins (inner joins)
			foreach ($ranges as $range) {
				$joinProperty = $range->getJoinProperty();
				
				// Base range - always required
				if ($joinProperty === null) {
					continue;
				}
				
				// Must be a required join
				if (!$range->isRequired()) {
					return false;
				}
			}
			
			// Additionally check if these required ranges create a path that guarantees existence
			return $this->requiredRangesGuaranteeExistence($expression, $ranges);
		}
		
		/**
		 * NEW METHOD: Checks if required ranges form a chain that guarantees existence
		 * @param AstInterface $expression The expression being analyzed
		 * @param array $ranges Array of required ranges
		 * @return bool True if the required chain guarantees existence
		 */
		private function requiredRangesGuaranteeExistence(AstInterface $expression, array $ranges): bool {
			// Get the base query to understand the full context
			$baseQuery = $this->getBaseQuery($expression);
			if (!$baseQuery) {
				return false;
			}
			
			// Check if all ranges in the expression are either:
			// 1. Already present in the base query as required joins
			// 2. Form a connected chain of required relationships
			
			foreach ($ranges as $range) {
				if (!$this->isRangeRequiredInBaseQuery($baseQuery, $range)) {
					return false;
				}
			}
			
			return true;
		}
		
		/**
		 * NEW METHOD: Checks if ranges are already required in the base query
		 * @param AstRetrieve $baseQuery The base query to check against
		 * @param AstRange $range The range to look for
		 * @return bool True if range is required in base query
		 */
		private function isRangeRequiredInBaseQuery(AstRetrieve $baseQuery, AstRange $range): bool {
			$baseRanges = $this->extractAllRanges($baseQuery);
			
			foreach ($baseRanges as $baseRange) {
				if ($baseRange->getName() === $range->getName() && $baseRange->isRequired()) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Checks if the ranges are already included as REQUIRED joins in the main query
		 * For optimization to constant true, we need both:
		 * 1. The range to be already joined in main query
		 * 2. AND it must be a required join (INNER JOIN)
		 * @param array $ranges Array of ranges to check
		 * @return bool True if ranges are already joined as REQUIRED joins in main query
		 */
		private function areRangesAlreadyRequiredJoined(array $ranges): bool {
			foreach ($ranges as $range) {
				// Must be already joined in main query
				if (!$this->isJoinAlreadyInMainQuery($range)) {
					return false;
				}
				
				// AND must be a required join (INNER JOIN)
				// Optional joins (LEFT JOIN) can return NULL, so we can't optimize to constant true
				if (!$range->isRequired()) {
					return false;
				}
			}
			return true;
		}
		
		/**
		 * Checks if the expression references only the base range (root range with no joins).
		 * Base range references can be optimized since they always exist in the context.
		 * @param array $ranges Array of ranges to analyze
		 * @return bool True if expression only references base range
		 */
		private function isBaseRangeReference(array $ranges): bool {
			// Must have exactly one range
			if (count($ranges) !== 1) {
				return false;
			}
			
			// Fetch the range
			$range = $ranges[0];
			
			// Base range has no join property - it's the root range of the query
			return $range->getJoinProperty() === null;
		}
		
		/**
		 * Detects same-entity self-join scenarios where a range joins to another range
		 * of the same entity type. These can often be optimized.
		 * @param AstInterface $expression The full expression being analyzed
		 * @param array $ranges Array of ranges to check
		 * @return bool True if this is a same-entity self-join scenario
		 */
		private function isSameEntitySelfJoin(AstInterface $expression, array $ranges): bool {
			// Must have exactly one range that is not a base range reference
			if (count($ranges) !== 1 || $this->isBaseRangeReference($ranges)) {
				return false;
			}
			
			// Must have a join property to be a joined range
			$range = $ranges[0];
			
			if (!$range->getJoinProperty()) {
				return false;
			}
			
			return $this->checkForSameEntityInBaseQuery($expression, $range);
		}
		
		/**
		 * Searches the base query for another range with the same entity type
		 * as the given range, indicating a self-join scenario.
		 * @param AstInterface $expression The expression to get base query from
		 * @param object $targetRange The range to compare against other ranges
		 * @return bool True if another range with same entity is found
		 */
		private function checkForSameEntityInBaseQuery(AstInterface $expression, object $targetRange): bool {
			$currentEntity = $targetRange->getEntityName();
			
			// Get base query to analyze all ranges
			$baseQuery = $this->getBaseQuery($expression);
			
			if (!$baseQuery) {
				return false;
			}
			
			// Look for another range with same entity but different name
			foreach ($this->extractAllRanges($baseQuery) as $otherRange) {
				if ($this->isSameEntityDifferentRange($otherRange, $targetRange, $currentEntity)) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Checks if two ranges represent the same entity but are different range instances.
		 * @param object $otherRange Range to compare
		 * @param object $targetRange Original range being checked
		 * @param string $currentEntity Entity name to match
		 * @return bool True if ranges are same entity but different instances
		 */
		private function isSameEntityDifferentRange(object $otherRange, object $targetRange, string $currentEntity): bool {
			return $otherRange->getName() !== $targetRange->getName()
				&& $otherRange->getEntityName() === $currentEntity;
		}
		
		/**
		 * Handles complex query scenarios that cannot be optimized to constant true.
		 * Analyzes query structure and applies appropriate existence check strategy.
		 * @param AstInterface $expression The expression to analyze
		 * @param string $sqlExpression The SQL representation of the expression
		 * @return string Appropriate SQL existence check
		 */
		private function handleComplexQueryScenario(AstInterface $expression, string $sqlExpression): string {
			$queryAnalysis = $this->analyzeQueryStructure($expression);
			
			// Simple case: no joins required, use subquery approach
			if (!$queryAnalysis['hasJoins']) {
				return $this->buildAnyExistsSubquery($expression);
			}
			
			// Complex case: joins present but not all ranges required
			// Use conditional check for null values
			if (!$queryAnalysis['allRangesRequired']) {
				return "CASE WHEN {$sqlExpression} IS NOT NULL THEN 1 ELSE 0 END";
			}
			
			// Most complex case: all joins required, existence guaranteed
			return "1";
		}
		
		// ============================================================================
		// SUBQUERY BUILDERS
		// ============================================================================
		
		/**
		 * This method constructs a complete subquery that evaluates an aggregate function
		 * (like SUM, COUNT, AVG, etc.) over the results of a given expression. It handles
		 * the complexity of building proper FROM and WHERE clauses based on the ranges
		 * referenced in the expression, and applies function-specific optimizations.
		 * @param AstInterface $expression The AST expression to aggregate over
		 * @param string $aggregateFunction The SQL aggregate function name (SUM, COUNT, AVG, etc.)
		 * @param bool $distinct Whether to apply DISTINCT to the aggregated values
		 * @return string Complete SQL subquery with aggregate function and NULL handling
		 */
		private function buildAggregateSubquery(AstInterface $expression, string $aggregateFunction, bool $distinct): string {
			// Step 1: Extract all ranges (table/entity references) from the expression
			// This identifies which tables need to be included in the subquery
			$ranges = $this->extractAllRanges($expression);
			
			// Step 2: Convert the AST expression into raw SQL
			// This transforms the high-level expression into database-specific SQL syntax
			$sqlExpression = $this->convertExpressionToSql($expression);
			
			// Step 3: Build DISTINCT clause if requested
			// DISTINCT eliminates duplicate values before applying the aggregate function
			// Useful for operations like counting unique customers or summing unique amounts
			$distinctClause = $distinct ? 'DISTINCT ' : '';
			
			// Step 4: Build the FROM clause with all necessary table references
			// Uses the ranges extracted earlier to include all required tables with proper aliases
			$fromClause = $this->buildFromClauseForRanges($ranges);
			
			// Step 5: Build the WHERE clause with join conditions and filters
			// Ensures proper table relationships and applies any filtering conditions
			$whereClause = $this->buildWhereClauseForRanges($ranges);
			
			// Step 6: Construct the complete aggregate subquery
			// Format: (SELECT FUNCTION(DISTINCT expression) FROM tables WHERE conditions)
			// Parentheses make it usable as a subquery in larger SQL statements
			$subquery = "(SELECT {$aggregateFunction}({$distinctClause}{$sqlExpression}) FROM {$fromClause} {$whereClause})";
			
			// Step 7: Apply function-specific NULL handling and optimizations
			return $aggregateFunction === 'SUM' ? "COALESCE({$subquery}, 0)" : $subquery;
		}
		
		/**
		 * Builds a SQL subquery to check if any records exist matching the given expression.
		 * @param AstInterface $expression The AST expression to evaluate for existence
		 * @return string SQL fragment representing the existence check
		 */
		private function buildAnyExistsSubquery(AstInterface $expression): string {
			// Extract all table/column ranges referenced in the expression
			// This analyzes the AST to determine what tables and columns are needed
			$ranges = $this->extractAllRanges($expression);
			
			// Build the FROM clause by joining all necessary tables
			// This creates the table joins needed to access the referenced data
			$fromClause = $this->buildFromClauseForRanges($ranges);
			
			// Build the WHERE clause to filter records based on the ranges
			// This applies the conditions from the expression to limit results
			$whereClause = $this->buildWhereClauseForRanges($ranges);
			
			// Construct the EXISTS subquery with SELECT 1 for efficiency
			// LIMIT 1 optimizes performance since we only need to know if ANY record exists
			$existsQuery = "EXISTS (SELECT 1 FROM {$fromClause} {$whereClause} LIMIT 1)";
			
			// Return different formats based on where this subquery will be used
			if ($this->partOfQuery !== "WHERE") {
				// If used in SELECT or other contexts, wrap in CASE to return 1/0
				return "CASE WHEN {$existsQuery} THEN 1 ELSE 0 END";
			} else {
				// If used in WHERE clause, return boolean EXISTS directly
				return $existsQuery;
			}
		}
		
		// ============================================================================
		// SQL CLAUSE BUILDERS
		// ============================================================================
		
		/**
		 * Builds the FROM clause portion of a SQL query based on the provided ranges.
		 * @param array $ranges Array of range objects containing entity and alias information
		 * @return string Complete FROM clause content (without the "FROM" keyword)
		 */
		private function buildFromClauseForRanges(array $ranges): string {
			$tables = [];
			
			foreach ($ranges as $range) {
				// Step 1: Get the logical entity name from the range
				// This is the high-level entity name (e.g., 'User', 'Order')
				$entityName = $range->getEntityName();
				
				// Step 2: Map the entity to its corresponding database table name
				// The entity store maintains the mapping between entities and physical tables
				// (e.g., 'User' entity maps to 'users' table)
				$tableName = $this->entityStore->getOwningTable($entityName);
				
				// Step 3: Get the range alias for this entity reference
				// This allows the same entity to be referenced multiple times with different aliases
				// (e.g., 'u1' and 'u2' for different User instances in a self-join)
				$rangeAlias = $range->getName();
				
				// Step 4: Build the table reference with proper SQL quoting
				// Format: `table_name` alias_name
				// Backticks protect against reserved words and special characters in table names
				$tables[] = "`{$tableName}` {$rangeAlias}";
			}
			
			// Step 5: Join all table references with commas to form complete FROM clause content
			// This creates a comma-separated list suitable for SQL FROM clauses
			// Note: This assumes simple table listing; complex JOINs would be handled differently
			return implode(', ', $tables);
		}
		
		/**
		 * This method processes an array of ranges and builds the WHERE clause portion
		 * of a SQL query by extracting and combining all join conditions. Each range
		 * may have join properties that define how it should be connected to other
		 * tables in the query.
		 * @param AstRange[] $ranges Array of ranges that need join conditions
		 * @return string Complete WHERE clause with conditions, or empty string if no conditions exist
		 */
		private function buildWhereClauseForRanges(array $ranges): string {
			$conditions = [];
			
			foreach ($ranges as $range) {
				// Step 1: Get the join property that defines how this range connects to others
				// Join properties contain the logic for relating this entity to other entities
				// (e.g., foreign key relationships, composite keys, etc.)
				$joinProperty = $range->getJoinProperty();
				
				// Step 2: Skip ranges that don't require join conditions
				// Some ranges might be standalone or already handled through other means
				if ($joinProperty === null) {
					continue;
				}
				
				// Step 3: Convert the join property into actual SQL condition
				// Use deepClone() to avoid modifying the original join property object
				// This ensures the AST remains immutable during SQL generation
				$joinConditionSql = $this->sqlBuilder->buildJoinCondition($joinProperty->deepClone());
				
				// Step 4: Validate and add the condition
				// Only include conditions that actually contain meaningful SQL
				// trim() removes whitespace to catch empty or whitespace-only conditions
				if (!empty(trim($joinConditionSql))) {
					$conditions[] = $joinConditionSql;
				}
			}
			
			// Step 5: Build the final WHERE clause
			// If no valid conditions were found, return empty string (no WHERE clause needed)
			// Otherwise, combine all conditions with AND logic and prepend "WHERE"
			return empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);
		}
		
		// ============================================================================
		// AST ANALYSIS HELPERS
		// ============================================================================
		
		/**
		 * Analyzes query structure to determine optimization strategies.
		 * @param AstInterface $expression Expression to analyze
		 * @return array Associative array with analysis results
		 */
		private function analyzeQueryStructure(AstInterface $expression): array {
			$ranges = $this->extractAllRanges($expression);
			$identifiers = $this->collectIdentifierNodes($expression);
			
			return [
				'hasJoins'          => $this->allIdentifiersIncludedAsJoin($ranges),
				'allRangesRequired' => $this->allRangesRequired($ranges),
				'isSingleRange'     => !empty($identifiers) && $this->isSingleRangeQuery($identifiers[0]),
				'ranges'            => $ranges,
				'identifiers'       => $identifiers
			];
		}
		
		/**
		 * Traverses the AST tree to find all AstIdentifier nodes, which represent
		 * references to entity properties and ranges in the query.
		 * @param AstInterface $ast Root AST node to search
		 * @return AstIdentifier[] Array of all identifier nodes found
		 */
		private function collectIdentifierNodes(AstInterface $ast): array {
			$visitor = new CollectNodes(AstIdentifier::class);
			$ast->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Extracts all unique ranges from identifier nodes.
		 * @param AstInterface $expression Expression to extract ranges from
		 * @return AstRange[] Array of unique range objects
		 */
		private function extractAllRanges(AstInterface $expression): array {
			$identifiers = $this->collectIdentifierNodes($expression);
			return $this->getAllRanges($identifiers);
		}
		
		/**
		 * Gets unique ranges from an array of identifiers.
		 * @param AstIdentifier[] $identifiers Array of identifier nodes
		 * @return AstRange[] Array of unique ranges
		 */
		private function getAllRanges(array $identifiers): array {
			$result = [];
			$seen = []; // Track range names to avoid duplicates
			
			foreach ($identifiers as $identifier) {
				$range = $identifier->getRange();
				
				// Skip identifiers without ranges
				if ($range === null) {
					continue;
				}
				
				// Only add each range once
				$rangeName = $range->getName();
				
				if (!isset($seen[$rangeName])) {
					$seen[$rangeName] = true;
					$result[] = $range;
				}
			}
			
			return $result;
		}
		
		// ============================================================================
		// QUERY OPTIMIZATION CHECKS
		// ============================================================================
		
		/**
		 * Checks whether any of the ranges are configured to be included as JOINs
		 * in the main query, which affects optimization decisions.
		 * @param AstRange[] $ranges Array of ranges to check
		 * @return bool True if any range is included as JOIN
		 */
		private function allIdentifiersIncludedAsJoin(array $ranges): bool {
			foreach ($ranges as $range) {
				if ($range->includeAsJoin()) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Determines if the aggregate should be calculated in the main query
		 * rather than a subquery.
		 */
		private function aggregateBelongsInMainQuery(AstInterface $expression, array $ranges): bool {
			// Check if all ranges are simple references without complex join conditions
			foreach ($ranges as $range) {
				$joinProperty = $range->getJoinProperty();
				
				// If there are no join conditions or they're simple,
				// the aggregate can stay in main query
				if ($joinProperty === null) {
					continue;
				}
				
				// For complex joins that aren't already established in main query,
				// use subquery approach
				if (!$this->isJoinAlreadyInMainQuery($range)) {
					return false;
				}
			}
			
			return true;
		}
		
		/**
		 * Determines if a range's join is already established in the main query.
		 * @param AstRange $range The range to check for existing joins
		 * @return bool True if the join is already available in main query
		 */
		private function isJoinAlreadyInMainQuery(AstRange $range): bool {
			// Base ranges (no join property) are always in main query
			if ($range->getJoinProperty() === null) {
				return true;
			}
			
			// If explicitly configured to be included as JOIN
			if ($range->includeAsJoin()) {
				return true;
			}
			
			// Otherwise, assume it needs a subquery
			return false;
		}
		
		/**
		 * Checks if all ranges are required (INNER JOIN vs LEFT JOIN).
		 * Required ranges use INNER JOINs and guarantee row existence,
		 * while optional ranges use LEFT JOINs and may have NULL values.
		 * @param AstRange[] $ranges Array of ranges to check
		 * @return bool True if all ranges are required
		 */
		private function allRangesRequired(array $ranges): bool {
			foreach ($ranges as $range) {
				if (!$range->isRequired()) {
					return false;
				}
			}
			
			return true;
		}
		
		/**
		 * Checks if we can optimize this aggregate to use a subquery instead of JOIN.
		 * @param AstInterface $expression Expression to check for optimization
		 * @return bool True if subquery optimization is possible
		 */
		private function canOptimizeToSubquery(AstInterface $expression): bool {
			$ranges = $this->extractAllRanges($expression);
			
			// Don't use subquery if the aggregate can be calculated in main query
			// This happens when the expression only uses ranges already available in main query
			if ($this->aggregateBelongsInMainQuery($expression, $ranges)) {
				return false;
			}
			
			// Must have required ranges only (no LEFT JOINs)
			if ($this->allRangesRequired($ranges)) {
				return true;
			}
			
			// Must not be included as JOIN in main query
			if ($this->allIdentifiersIncludedAsJoin($ranges)) {
				return false;
			}
			
			return true;
		}
		
		// ============================================================================
		// QUERY STRUCTURE ANALYSIS
		// ============================================================================
		
		/**
		 * Finds the root AstRetrieve node by traversing up the AST hierarchy.
		 * @param AstInterface $ast Starting AST node
		 * @return AstRetrieve|null The root retrieve node or null if not found
		 */
		private function getBaseQuery(AstInterface $ast): ?AstRetrieve {
			$current = $ast;
			
			// Check if current node is already the retrieve node
			if ($current instanceof AstRetrieve) {
				return $current;
			}
			
			// Traverse up the AST tree to find the AstRetrieve root
			while ($parent = $current->getParent()) {
				if ($parent instanceof AstRetrieve) {
					return $parent;
				}
				$current = $parent;
			}
			
			return null;
		}
		
		/**
		 * Determines if the given AST represents a single range query.
		 * @param AstInterface $ast The AST node to check
		 * @return bool True if this is a single range query, false otherwise
		 */
		private function isSingleRangeQuery(AstInterface $ast): bool {
			// Find the base query node
			$queryNode = $this->getBaseQuery($ast);
			
			if ($queryNode === null) {
				return false;
			}
			
			// NEW: Check if this is effectively a single-range query
			// This includes cases where multiple ranges refer to the same entity
			// with simple equality joins (like d.id = c.id)
			if ($queryNode->isSingleRangeQuery()) {
				return true;
			}
			
			// NEW: Additional check for equivalent range scenarios
			return $this->isEquivalentRangeScenario($ast);
		}
		
		/**
		 * Checks if ranges are equivalent (same entity, simple joins)
		 * @param AstInterface $ast The AST to analyze
		 * @return bool True if ranges are effectively equivalent
		 */
		private function isEquivalentRangeScenario(AstInterface $ast): bool {
			$ranges = $this->extractAllRanges($ast);
			
			// Original logic: multiple ranges within the expression
			if (count($ranges) >= 2) {
				$firstEntityName = null;
				
				foreach ($ranges as $range) {
					$entityName = $range->getEntityName();
					
					if ($firstEntityName === null) {
						$firstEntityName = $entityName;
					} elseif ($firstEntityName !== $entityName) {
						return false;
					}
					
					$joinProperty = $range->getJoinProperty();
					if ($joinProperty !== null && !$this->isSimpleEqualityJoin($joinProperty)) {
						return false;
					}
				}
				
				return true;
			}
			
			// NEW: Single range - check if equivalent to other ranges in broader query
			if (count($ranges) === 1) {
				$singleRange = $ranges[0];
				
				// Must have a join property to be equivalent to something else
				$joinProperty = $singleRange->getJoinProperty();
				if (!$joinProperty) {
					return false;
				}
				
				// Must be a simple equality join
				if (!$this->isSimpleEqualityJoin($joinProperty)) {
					return false;
				}
				
				// Get all identifiers in the join to find the "other" range
				$joinIdentifiers = $this->collectIdentifierNodes($joinProperty);
				if (count($joinIdentifiers) !== 2) {
					return false;
				}
				
				// Find the range that's NOT the single range we're analyzing
				$otherRange = null;
				foreach ($joinIdentifiers as $identifier) {
					$range = $identifier->getRange();
					if ($range && $range->getName() !== $singleRange->getName()) {
						$otherRange = $range;
						break;
					}
				}
				
				if (!$otherRange) {
					return false;
				}
				
				// Check if both ranges are the same entity type
				return $otherRange->getEntityName() === $singleRange->getEntityName();
			}
			
			return false;
		}
		
		/**
		 * NEW METHOD: Determines if a join is a simple equality join between same entity types
		 * @param AstInterface $joinProperty The join property to analyze
		 * @return bool True if it's a simple equality join between same entities
		 */
		private function isSimpleEqualityJoin(AstInterface $joinProperty): bool {
			// Collect all identifier nodes from the join condition
			$identifiers = $this->collectIdentifierNodes($joinProperty);
			
			// Must have exactly 2 identifiers for a simple equality join (left = right)
			if (count($identifiers) !== 2) {
				return false;
			}
			
			$leftIdentifier = $identifiers[0];
			$rightIdentifier = $identifiers[1];
			
			// Both identifiers must have ranges
			$leftRange = $leftIdentifier->getRange();
			$rightRange = $rightIdentifier->getRange();
			
			if ($leftRange === null || $rightRange === null) {
				return false;
			}
			
			// Check if both ranges reference the same entity type
			$leftEntity = $leftRange->getEntityName();
			$rightEntity = $rightRange->getEntityName();
			
			if ($leftEntity !== $rightEntity) {
				return false;
			}
			
			// Optional: Check if they're comparing the same field name
			// This catches patterns like c.id = d.id (same field on same entity type)
			$leftField = $leftIdentifier->getName();
			$rightField = $rightIdentifier->getName();
			
			return $leftField === $rightField;
		}
		
		// ============================================================================
		// UTILITY METHODS
		// ============================================================================
		
		/**
		 * Converts an AST expression to SQL string representation.
		 * @param AstInterface $expression The AST expression to convert
		 * @return string SQL string representation of the expression
		 */
		private function convertExpressionToSql(AstInterface $expression): string {
			return $this->convertToString->visitNodeAndReturnSQL($expression);
		}
	}