<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors\Handlers;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\QuelToSQLConvertToString;
	
	/**
	 * Handles conversion of ObjectQuel aggregate AST nodes to SQL aggregate functions and EXISTS queries.
	 *
	 * Key features:
	 * - Supports all standard SQL aggregate functions (COUNT, SUM, AVG, MIN, MAX)
	 * - Handles DISTINCT operations (COUNT UNIQUE, SUM UNIQUE, etc.)
	 * - Optimizes ANY operations using linear flow decision-making
	 * - Can generate either JOIN-based or subquery-based SQL depending on optimization opportunities
	 * - Uses QueryAnalysis value object to eliminate complex nested conditional logic
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
		
		// ============================================================================
		// PUBLIC AGGREGATE HANDLERS
		// ============================================================================
		
		/**
		 * Converts AstSubquery to SQL
		 * @param AstSubquery $subquery
		 * @return string
		 */
		public function handleSubquery(AstSubquery $subquery): string {
			switch ($subquery->getType()) {
				case AstSubquery::TYPE_SCALAR:
					return $this->buildScalarSubquery($subquery);
				
				case AstSubquery::TYPE_EXISTS:
					return $this->buildExistsSubquery($aggregation);
				
				case AstSubquery::TYPE_CASE_WHEN:
					return $this->buildCaseWhenExistsSubquery($aggregation);
				
				default:
					throw new \InvalidArgumentException("Unknown subquery type: " . $subquery->getType());
			}
		}
		
		/**
		 * Handles CASE WHEN expressions for conditional aggregation.
		 * Converts: SUM(expr WHERE condition) → SUM(CASE WHEN condition THEN expr END)
		 * @param AstCase $case The CASE AST node to process
		 * @return string Generated SQL CASE expression
		 */
		public function handleCase(AstCase $case): string {
			$condition = $this->convertExpressionToSql($case->getConditions());
			$thenExpression = $this->convertExpressionToSql($case->getExpression());
			return "CASE WHEN {$condition} THEN {$thenExpression} END";
		}
		
		/**
		 * Builds a scalar subquery for SQL aggregation functions.
		 * @param AstSubquery $subquery
		 * @return string The complete SQL subquery string wrapped in parentheses
		 */
		private function buildScalarSubquery(AstSubquery $subquery): string {
			// Mark the inner aggregation as visited to prevent duplicate processing
			$this->markExpressionAsHandled($subquery->getAggregation());
			
			// Convert the aggregation type to its SQL function name (COUNT, AVG, etc.)
			$aggregateString = $this->aggregateToString($subquery->getAggregation());
			
			// Add DISTINCT keyword for unique aggregation types (CountU, AvgU, SumU)
			$distinct = $this->isDistinct($subquery->getAggregation()) ? "DISTINCT " : "";
			
			// Convert the field identifier to SQL expression
			$aggregateExpression = $this->convertExpressionToSql($subquery->getAggregation()->getIdentifier()->deepClone());
			
			// Convert the WHERE conditions to SQL expression
			if ($subquery->getConditions() !== null) {
				$whereExpression = $this->convertExpressionToSql($subquery->getConditions());
			} else {
				$whereExpression = "";
			}
			
			// Get the main table range for the query, and the join ranges
			$mainRange = $subquery->getMainRange();
			$mainRangeName = $mainRange->getName();
			
			// Generate the joins
			$joinRanges = [];
			
			foreach($subquery->getJoinRanges() as $joinRange) {
				$joinName = $joinRange->getName();
				$tableName = $this->entityStore->getOwningTable($joinRange->getEntityName());
				$joinType = $joinRange->isRequired() ? "INNER" : "LEFT";
				$conditions = $joinRange->getJoinProperty()->deepClone();
				$joinExpression = $this->convertExpressionToSql($conditions);
				
				$joinRanges[] = "{$joinType} JOIN `{$tableName}` {$joinName} ON {$joinExpression}";
			}
			
			// Look up the actual database table name for the entity
			$tableName = $this->entityStore->getOwningTable($mainRange->getEntityName());
			$aggregateExpressionComplete = "{$aggregateString}({$distinct}{$aggregateExpression})";
			$joins = implode("\n", $joinRanges);
			
			if ($aggregateString === 'SUM') {
				$aggregateExpressionComplete = "COALESCE({$aggregateExpressionComplete}, 0)";
			}
			
			// Build and return the complete subquery
			if ($whereExpression) {
				return "(
					SELECT
						{$aggregateExpressionComplete}
					FROM `{$tableName}` {$mainRangeName}
					{$joins}
					WHERE {$whereExpression}
				)";
			} else {
				return "(
					SELECT
						{$aggregateExpressionComplete}
					FROM `{$tableName}` {$mainRangeName}
					{$joins}
				)";
			}
		}
		
		/**
		 * Converts an aggregation AST node type to its corresponding SQL function name.
		 * @param AstSubquery|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $type The aggregation AST node
		 * @return string The SQL aggregation function name (uppercase)
		 */
		private function aggregateToString(
			AstSubquery|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $type
		): string {
			return match(get_class($type)) {
				AstCount::class, AstCountU::class => "COUNT",
				AstAvg::class, AstAvgU::class => "AVG",
				AstSum::class, AstSumU::class => "SUM",
				AstMin::class => "MIN",
				AstMax::class => "MAX",
				AstSubquery::class => "UNKNOWN",
			};
		}
		
		/**
		 * Determines if an aggregation should use the DISTINCT keyword.
		 * @param AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $type The aggregation AST node
		 * @return bool True if DISTINCT should be used, false otherwise
		 */
		private function isDistinct(
			AstSubquery|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $type
		): bool {
			return
				$type instanceof AstCountU ||
				$type instanceof AstAvgU ||
				$type instanceof AstSumU;
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
			$queryAnalyzer = new QueryAnalyzer($ast->getIdentifier(), $this->entityStore);
			$strategy = $queryAnalyzer->getOptimizationStrategy();
			
			switch ($strategy->getType()) {
				case OptimizationStrategy::JOIN_BASED:
				case OptimizationStrategy::CONSTANT_TRUE:
					$this->markExpressionAsHandled($queryAnalyzer->getExpression());
					return "1";
				
				case OptimizationStrategy::SIMPLE_EXISTS:
					return $this->buildSimpleExistsQuery($queryAnalyzer);
				
				case OptimizationStrategy::NULL_CHECK:
					return $this->buildNullCheckQuery($queryAnalyzer);
				
				case OptimizationStrategy::SUBQUERY:
					return $this->buildComplexExistsQuery($queryAnalyzer);
				
				default:
					throw new \InvalidArgumentException("Unknown optimization strategy: " . $strategy->getType());
			}
		}
		
		// ============================================================================
		// CORE PROCESSING METHODS - LINEAR FLOW APPROACH
		// ============================================================================
		
		/**
		 * Replaces complex branching logic with simple two-path decision:
		 * either calculate in main query (when joins already exist) or use subquery.
		 * This eliminates the need for complex optimization analysis in aggregate functions.
		 * @param AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $ast The aggregate AST node
		 * @param string $aggregateFunction The SQL aggregate function name (COUNT, SUM, etc.)
		 * @param bool $distinct Whether to add DISTINCT clause for unique operations
		 * @return string Generated SQL aggregate expression
		 */
		private function handleAggregateOperation(
			AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $ast,
			string                                                         $aggregateFunction,
			bool                                                           $distinct = false
		): string {
			// Check if aggregate has WHERE conditions that need CASE WHEN transformation
			if ($ast->getConditions() !== null) {
				// Transform to CASE WHEN expression
				$condition = $this->convertExpressionToSql($ast->getConditions());
				$expression = $this->convertExpressionToSql($ast->getIdentifier());
				$caseExpression = "CASE WHEN {$condition} THEN {$expression} END";
				
				// Build aggregate with CASE WHEN
				$distinctClause = $distinct ? 'DISTINCT ' : '';
				
				if ($aggregateFunction === 'SUM') {
					return "COALESCE({$aggregateFunction}({$distinctClause}{$caseExpression}), 0)";
				} else {
					return "{$aggregateFunction}({$distinctClause}{$caseExpression})";
				}
			}
			
			// Convert the AST expression into its SQL string representation
			$sqlExpression = $this->convertExpressionToSql($ast->getIdentifier());
			
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
		// QUERY BUILDER
		// ============================================================================
		
		/**
		 * Builds a simple existence query for expressions without complex joins.
		 * Used when the expression can be evaluated with basic IS NOT NULL checks.
		 * @param QueryAnalyzer $queryAnalyzer Analysis containing expression and context
		 * @return string SQL fragment for simple existence check
		 */
		private function buildSimpleExistsQuery(QueryAnalyzer $queryAnalyzer): string {
			// Convert expression to SQL
			$sqlExpression = $this->convertExpressionToSql($queryAnalyzer->getExpression());
			
			// In WHERE context, return boolean condition directly
			// In other contexts (SELECT, etc.), wrap in CASE for 1/0 result
			if ($this->partOfQuery !== "WHERE") {
				return "CASE WHEN {$sqlExpression} IS NOT NULL THEN 1 ELSE 0 END";
			} else {
				return "{$sqlExpression} IS NOT NULL";
			}
		}
		
		/**
		 * Builds a NULL check query for expressions with optional ranges.
		 * Optional ranges (LEFT JOINs) can produce NULL values that need special handling.
		 * @param QueryAnalyzer $queryAnalyzer Analysis containing expression and ranges
		 * @return string SQL fragment with NULL handling
		 */
		private function buildNullCheckQuery(QueryAnalyzer $queryAnalyzer): string {
			// Convert expression to SQL
			$sqlExpression = $this->convertExpressionToSql($queryAnalyzer->getExpression());
			
			// Always use CASE statement since optional ranges require explicit NULL checking
			return "CASE WHEN {$sqlExpression} IS NOT NULL THEN 1 ELSE 0 END";
		}
		
		/**
		 * Builds a complex EXISTS subquery for scenarios requiring full join isolation.
		 * Used when the expression needs joins that aren't available in the main query
		 * or when optimization to simpler forms isn't possible.
		 * @param QueryAnalyzer $queryAnalyzer Analysis containing ranges and expression context
		 * @return string Complete EXISTS subquery with proper joins and conditions
		 */
		private function buildComplexExistsQuery(QueryAnalyzer $queryAnalyzer): string {
			// Fetch all ranges
			$ranges = $queryAnalyzer->getRanges();
			
			// Mark the expression as handled. Otherwise, parts may wrongly reemerge in the query
			$this->markExpressionAsHandled($queryAnalyzer->getExpression());
			
			// Build complete subquery with all necessary table joins
			$fromClause = $this->buildFromClauseForRanges($ranges);
			$whereClause = $this->buildWhereClauseForRanges($ranges);
			
			// Use SELECT 1 for efficiency - we only need to know if ANY record exists
			$existsQuery = "EXISTS (SELECT 1 FROM {$fromClause} {$whereClause} LIMIT 1)";
			
			// Return format depends on usage context
			if ($this->partOfQuery !== "WHERE") {
				// In SELECT context, convert boolean to 1/0 for consistency
				return "CASE WHEN {$existsQuery} THEN 1 ELSE 0 END";
			}
			
			// In WHERE context, return boolean EXISTS directly
			return $existsQuery;
		}
		
		// ============================================================================
		// HELPER METHODS
		// ============================================================================
		
		/**
		 * Builds JOIN-based aggregate expressions when subquery optimization isn't needed.
		 * Used when the aggregate can be calculated directly in the main query context.
		 * @param AstInterface $expression The expression to aggregate
		 * @param string $aggregateFunction SQL function name (COUNT, SUM, etc.)
		 * @param bool $distinct Whether to include DISTINCT clause
		 * @return string SQL aggregate expression using main query JOINs
		 */
		private function buildJoinBasedAggregate(AstInterface $expression, string $aggregateFunction, bool $distinct): string {
		}
		
		/**
		 * Constructs a complete subquery that evaluates an aggregate function over the results of a given expression.
		 *
		 * This method handles the complexity of building proper FROM and WHERE clauses based on the ranges
		 * referenced in the expression, and applies function-specific optimizations. It constructs a complete
		 * subquery that can be used independently of the main query's JOIN structure.
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
		
		// ============================================================================
		// UTILITY METHODS (PRESERVED FROM ORIGINAL)
		// ============================================================================
		
		/**
		 * Converts an AST expression to SQL string representation.
		 * @param AstInterface $expression The AST expression to convert
		 * @return string SQL string representation of the expression
		 */
		private function convertExpressionToSql(AstInterface $expression): string {
			return $this->convertToString->visitNodeAndReturnSQL($expression);
		}
		
		/**
		 * Marks the expression as handled
		 * @param AstInterface $expression The AST expression to convert
		 * @return void
		 */
		private function markExpressionAsHandled(AstInterface $expression): void {
			// Convert but ignore result - we just need the side effect
			$this->convertToString->visitNodeAndReturnSQL($expression);
		}
		
		/**
		 * Extracts all unique ranges from an expression by analyzing its AST structure.
		 * @param AstInterface $expression Expression to extract ranges from
		 * @return array Array of unique AstRange objects
		 */
		private function extractAllRanges(AstInterface $expression): array {
			$queryAnalyzer = new QueryAnalyzer($expression, $this->entityStore);
			return $queryAnalyzer->getRanges();
		}
		
		/**
		 * Builds the FROM clause portion of a SQL query based on the provided ranges.
		 * Maps entity names to their corresponding database table names and creates proper aliases.
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
			return implode(', ', $tables);
		}
		
		/**
		 * This method extracts and combines all join conditions from the ranges. Each range
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
	}
