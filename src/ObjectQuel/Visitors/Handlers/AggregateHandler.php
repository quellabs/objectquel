<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors\Handlers;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\QuelToSQLConvertToString;
	
	/**
	 * Converts ObjectQuel aggregate AST nodes to SQL aggregate functions and existence checks.
	 *
	 * This handler transforms ObjectQuel's aggregate operations (COUNT, SUM, AVG, MIN, MAX, ANY)
	 * into their SQL equivalents, supporting both inline aggregation and subquery-based approaches.
	 *
	 * Key capabilities:
	 * - Standard SQL aggregate functions: COUNT, SUM, AVG, MIN, MAX
	 * - DISTINCT operations: COUNT UNIQUE, SUM UNIQUE, AVG UNIQUE
	 * - Existence checks: ANY operations converted to EXISTS or CASE WHEN EXISTS
	 * - Conditional aggregation: WHERE clauses converted to CASE WHEN expressions
	 * - Subquery generation: Scalar subqueries for complex aggregation scenarios
	 * - NULL handling: Automatic COALESCE for SUM operations
	 *
	 * @package Quellabs\ObjectQuel\ObjectQuel\Visitors\Handlers
	 */
	class AggregateHandler {
		
		/** @var EntityStore Maps entity names to database table names and provides metadata */
		private EntityStore $entityStore;
		
		/** @var string Current SQL clause being constructed (SELECT, WHERE, HAVING, etc.) */
		private string $partOfQuery;
		
		/** @var SqlBuilderHelper Utility for constructing SQL fragments and join conditions */
		private SqlBuilderHelper $sqlBuilder;
		
		/** @var QuelToSQLConvertToString Converts AST nodes to their SQL string representations */
		private QuelToSQLConvertToString $convertToString;
		
		/**
		 * Initializes the aggregate handler with required dependencies.
		 * @param EntityStore $entityStore Maps entity names to table names and provides schema metadata
		 * @param string $partOfQuery Current SQL clause context (SELECT, WHERE, etc.) for output formatting
		 * @param SqlBuilderHelper $sqlBuilder Helper for building SQL components like joins and conditions
		 * @param QuelToSQLConvertToString $convertToString Converts AST expressions to SQL strings
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
		// SUBQUERY HANDLERS - MAIN ENTRY POINTS
		// ============================================================================
		
		/**
		 * Converts AstSubquery nodes to appropriate SQL subquery types.
		 *
		 * Routes to specialized handlers based on subquery type:
		 * - SCALAR: (SELECT COUNT(*) FROM ...) for aggregation in SELECT clauses
		 * - EXISTS: EXISTS(SELECT 1 FROM ...) for boolean checks in WHERE clauses
		 * - CASE_WHEN: CASE WHEN EXISTS(...) THEN 1 ELSE 0 END for numeric boolean results
		 *
		 * @param AstSubquery $subquery The subquery AST node to process
		 * @return string Complete SQL subquery expression
		 * @throws \InvalidArgumentException When subquery type is not recognized
		 */
		public function handleSubquery(AstSubquery $subquery): string {
			switch ($subquery->getType()) {
				case AstSubquery::TYPE_SCALAR:
					return $this->buildScalarSubquery($subquery);
				
				case AstSubquery::TYPE_EXISTS:
					return $this->buildExistsSubquery($subquery);
				
				case AstSubquery::TYPE_CASE_WHEN:
					return $this->buildCaseWhenExistsSubquery($subquery);
				
				case AstSubquery::TYPE_WINDOW: // ← add
					return $this->buildWindowAggregate($subquery);
				
				default:
					throw new \InvalidArgumentException("Unknown subquery type: " . $subquery->getType());
			}
		}
		
		/**
		 * Converts conditional expressions to SQL CASE WHEN statements.
		 *
		 * Transforms ObjectQuel conditional logic into SQL CASE expressions:
		 * Input:  CASE(condition, expression)
		 * Output: CASE WHEN condition THEN expression END
		 *
		 * Used primarily for conditional aggregation where aggregates have WHERE clauses.
		 *
		 * @param AstCase $case The CASE AST node containing condition and expression
		 * @return string SQL CASE WHEN expression
		 */
		public function handleCase(AstCase $case): string {
			$condition = $this->convertExpressionToSql($case->getConditions());
			$thenExpression = $this->convertExpressionToSql($case->getExpression());
			return "CASE WHEN {$condition} THEN {$thenExpression} END";
		}
		
		// ============================================================================
		// SCALAR SUBQUERY CONSTRUCTION
		// ============================================================================
		
		/**
		 * Builds scalar subqueries for aggregate functions that return single values.
		 *
		 * Creates complete SQL subqueries like:
		 * (SELECT COUNT(DISTINCT d.id) FROM departments d
		 *  INNER JOIN employees e ON e.dept_id = d.id
		 *  WHERE d.active = 1)
		 *
		 * Features:
		 * - Automatic FROM clause generation from correlated ranges
		 * - Optional WHERE clause from subquery conditions
		 * - DISTINCT support for unique operations
		 * - NULL handling with COALESCE for SUM operations
		 *
		 * @param AstSubquery $subquery Contains the aggregate function and related metadata
		 * @return string Complete SQL scalar subquery wrapped in parentheses
		 */
		private function buildScalarSubquery(AstSubquery $subquery): string {
			// Prevent double-processing of the aggregate function
			$this->markExpressionAsHandled($subquery->getAggregation());
			
			$aggNode = $subquery->getAggregation();
			$functionName = $this->aggregateToString($aggNode);
			$distinctClause = $this->isDistinct($aggNode) ? 'DISTINCT ' : '';
			
			// Convert the aggregate target (usually an identifier like d.id) to SQL
			$targetExpression = $this->convertExpressionToSql($aggNode->getIdentifier()->deepClone());
			
			// Build FROM clause with all necessary joins from correlated ranges
			$fromClause = $this->buildFromClauseForRanges($subquery->getCorrelatedRanges());
			
			// Add WHERE clause if the subquery has conditions
			$whereClause = '';
			if ($subquery->getConditions() !== null) {
				$conditionSql = trim($this->convertExpressionToSql($subquery->getConditions()));
				
				if ($conditionSql !== '') {
					$whereClause = "WHERE {$conditionSql}";
				}
			}
			
			// Build the aggregate function call
			$aggregateExpression = "{$functionName}({$distinctClause}{$targetExpression})";
			
			// Wrap SUM with COALESCE to return 0 instead of NULL for empty result sets
			if ($functionName === 'SUM') {
				$aggregateExpression = "COALESCE({$aggregateExpression}, 0)";
			}
			
			return "(
		        SELECT {$aggregateExpression}
		        FROM {$fromClause}
		        {$whereClause}
		    )";
		}
		
		/**
		 * Maps aggregate AST node types to their corresponding SQL function names.
		 *
		 * Handles both regular and UNIQUE (DISTINCT) variants:
		 * - AstCount/AstCountU → COUNT
		 * - AstSum/AstSumU → SUM
		 * - AstAvg/AstAvgU → AVG
		 * - AstMin → MIN
		 * - AstMax → MAX
		 *
		 * @param AstSubquery|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $aggregateNode
		 * @return string SQL function name in uppercase
		 */
		private function aggregateToString(
			AstSubquery|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $aggregateNode
		): string {
			return match (get_class($aggregateNode)) {
				AstCount::class, AstCountU::class => "COUNT",
				AstAvg::class, AstAvgU::class => "AVG",
				AstSum::class, AstSumU::class => "SUM",
				AstMin::class => "MIN",
				AstMax::class => "MAX",
				AstSubquery::class => "UNKNOWN",
			};
		}
		
		/**
		 * Determines whether an aggregate function should include the DISTINCT keyword.
		 *
		 * Returns true for "UNIQUE" variants of aggregate functions:
		 * - AstCountU → COUNT(DISTINCT ...)
		 * - AstSumU → SUM(DISTINCT ...)
		 * - AstAvgU → AVG(DISTINCT ...)
		 *
		 * Regular variants (AstCount, AstSum, AstAvg, AstMin, AstMax) return false.
		 *
		 * @param AstSubquery|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $aggregateNode
		 * @return bool True if DISTINCT should be included, false otherwise
		 */
		private function isDistinct(
			AstSubquery|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $aggregateNode
		): bool {
			return
				$aggregateNode instanceof AstCountU ||
				$aggregateNode instanceof AstAvgU ||
				$aggregateNode instanceof AstSumU;
		}
		
		// ============================================================================
		// INDIVIDUAL AGGREGATE FUNCTION HANDLERS
		// ============================================================================
		
		/**
		 * Converts AstCount nodes to SQL COUNT() functions.
		 *
		 * @param AstCount $count The COUNT AST node to process
		 * @return string SQL COUNT expression
		 */
		public function handleCount(AstCount $count): string {
			return $this->handleAggregateOperation($count, 'COUNT');
		}
		
		/**
		 * Converts AstCountU nodes to SQL COUNT(DISTINCT) functions.
		 *
		 * Ensures only distinct values are counted, eliminating duplicates.
		 *
		 * @param AstCountU $count The COUNT UNIQUE AST node to process
		 * @return string SQL COUNT(DISTINCT) expression
		 */
		public function handleCountU(AstCountU $count): string {
			return $this->handleAggregateOperation($count, 'COUNT', true);
		}
		
		/**
		 * Converts AstAvg nodes to SQL AVG() functions.
		 *
		 * @param AstAvg $avg The AVG AST node to process
		 * @return string SQL AVG expression
		 */
		public function handleAvg(AstAvg $avg): string {
			return $this->handleAggregateOperation($avg, 'AVG');
		}
		
		/**
		 * Converts AstAvgU nodes to SQL AVG(DISTINCT) functions.
		 *
		 * Calculates average of only unique values, eliminating duplicates before averaging.
		 *
		 * @param AstAvgU $avg The AVG UNIQUE AST node to process
		 * @return string SQL AVG(DISTINCT) expression
		 */
		public function handleAvgU(AstAvgU $avg): string {
			return $this->handleAggregateOperation($avg, 'AVG', true);
		}
		
		/**
		 * Converts AstMax nodes to SQL MAX() functions.
		 *
		 * @param AstMax $max The MAX AST node to process
		 * @return string SQL MAX expression
		 */
		public function handleMax(AstMax $max): string {
			return $this->handleAggregateOperation($max, 'MAX');
		}
		
		/**
		 * Converts AstMin nodes to SQL MIN() functions.
		 *
		 * @param AstMin $min The MIN AST node to process
		 * @return string SQL MIN expression
		 */
		public function handleMin(AstMin $min): string {
			return $this->handleAggregateOperation($min, 'MIN');
		}
		
		/**
		 * Converts AstSum nodes to SQL SUM() functions.
		 *
		 * Automatically wraps result with COALESCE to return 0 instead of NULL when no rows match.
		 *
		 * @param AstSum $sum The SUM AST node to process
		 * @return string SQL SUM expression with NULL handling
		 */
		public function handleSum(AstSum $sum): string {
			return $this->handleAggregateOperation($sum, 'SUM');
		}
		
		/**
		 * Converts AstSumU nodes to SQL SUM(DISTINCT) functions.
		 *
		 * Sums only unique values, eliminating duplicates before summation.
		 * Includes automatic NULL handling with COALESCE.
		 *
		 * @param AstSumU $sum The SUM UNIQUE AST node to process
		 * @return string SQL SUM(DISTINCT) expression with NULL handling
		 */
		public function handleSumU(AstSumU $sum): string {
			return $this->handleAggregateOperation($sum, "SUM", true);
		}
		
		// ============================================================================
		// EXISTS SUBQUERY CONSTRUCTION
		// ============================================================================
		
		/**
		 * Builds CASE WHEN EXISTS subqueries for ANY functions in SELECT clauses.
		 *
		 * Converts existence checks to numeric values for use in SELECT clauses:
		 * Input:  ANY(d.employees)
		 * Output: CASE WHEN EXISTS(SELECT 1 FROM employees e WHERE e.dept_id = d.id LIMIT 1) THEN 1 ELSE 0 END
		 *
		 * This format ensures consistent numeric results (0 or 1) regardless of database platform.
		 *
		 * @param AstSubquery $subquery Contains the ANY aggregate and related metadata
		 * @return string SQL CASE WHEN statement with EXISTS subquery
		 */
		private function buildCaseWhenExistsSubquery(AstSubquery $subquery): string {
			$ranges     = $subquery->getCorrelatedRanges();
			$fromClause = $this->buildFromClauseForRanges($ranges);
			
			$whereClause = '';

			if ($subquery->getConditions() !== null) {
				$condSql = trim($this->convertExpressionToSql($subquery->getConditions()));

				if ($condSql !== '') {
					$whereClause = "WHERE {$condSql}";
				}
			} else {
				$whereClause = $this->buildWhereClauseForRanges($ranges);
			}
			
			$existsQuery = "EXISTS (SELECT 1 FROM {$fromClause} {$whereClause})";
			return "CASE WHEN {$existsQuery} THEN 1 ELSE 0 END";
		}
		
		/**
		 * Builds a window aggregate: AGG([DISTINCT] expr) OVER ()
		 * SUM is wrapped in COALESCE(..., 0) to keep your current NULL behavior.
		 */
		private function buildWindowAggregate(AstSubquery $subquery): string {
			$this->markExpressionAsHandled($subquery->getAggregation());
			
			$aggNode = $subquery->getAggregation();
			$fn = $this->aggregateToString($aggNode);
			$distinct = $this->isDistinct($aggNode) ? 'DISTINCT ' : '';
			$argSql = $this->convertExpressionToSql($aggNode->getIdentifier()->deepClone());
			
			if ($fn === 'SUM') {
				// ✅ OVER() attaches to SUM, COALESCE wraps the whole window result
				return "COALESCE({$fn}({$distinct}{$argSql}) OVER (), 0)";
			}
			
			return "{$fn}({$distinct}{$argSql}) OVER ()";
		}
		
		
		/**
		 * Builds EXISTS subqueries for ANY functions in WHERE clauses.
		 *
		 * Creates boolean existence checks for use in WHERE conditions:
		 * Input:  ANY(d.employees) in WHERE clause
		 * Output: EXISTS(SELECT 1 FROM employees e WHERE e.dept_id = d.id LIMIT 1)
		 *
		 * Uses SELECT 1 and LIMIT 1 for optimal performance since only existence matters.
		 *
		 * @param AstSubquery $subquery Contains the ANY aggregate and related metadata
		 * @return string SQL EXISTS statement
		 */
		private function buildExistsSubquery(AstSubquery $subquery): string {
			// FROM
			$ranges     = $subquery->getCorrelatedRanges();
			$fromClause = $this->buildFromClauseForRanges($ranges);
			
			// WHERE – prefer the subquery's own conditions (what the optimizer set)
			$whereClause = '';

			if ($subquery->getConditions() !== null) {
				$condSql = trim($this->convertExpressionToSql($subquery->getConditions()));

				if ($condSql !== '') {
					$whereClause = "WHERE {$condSql}";
				}
			} else {
				// fallback for legacy callers that didn't set conditions
				$whereClause = $this->buildWhereClauseForRanges($ranges);
			}
			
			// EXISTS doesn't need LIMIT 1; it short-circuits internally
			return "EXISTS (SELECT 1 FROM {$fromClause} {$whereClause})";
		}
		
		/**
		 * Converts AstAny nodes to SQL existence checks (EXISTS or CASE WHEN EXISTS).
		 *
		 * Transforms ObjectQuel ANY operations into appropriate SQL existence checks:
		 * - In WHERE clauses: EXISTS(SELECT 1 FROM ...)
		 * - In SELECT clauses: CASE WHEN EXISTS(...) THEN 1 ELSE 0 END
		 *
		 * Includes duplicate detection to prevent processing the same ANY twice when
		 * it's already wrapped in a subquery.
		 *
		 * @param AstAny $ast The ANY AST node to process
		 * @return string SQL existence check expression, or empty string if already processed
		 */
		public function handleAny(AstAny $ast): string {
			// Check if this ANY is already processed by a parent subquery
			$parent = $ast->getParent();
			
			while ($parent !== null) {
				if ($parent instanceof AstSubquery) {
					return ""; // Already handled by subquery
				}
				
				$parent = $parent->getParent();
			}
			
			// Extract ranges from the referenced identifier
			$identifier = $ast->getIdentifier();
			$ranges = $this->extractAllRanges($identifier);
			
			// Mark identifier as processed to prevent duplicate handling
			$this->markExpressionAsHandled($identifier);
			
			// Build EXISTS subquery components
			$fromClause = $this->buildFromClauseForRanges($ranges);
			$whereClause = $this->buildWhereClauseForRanges($ranges);
			
			$existsQuery = "EXISTS (SELECT 1 FROM {$fromClause} {$whereClause} LIMIT 1)";
			
			// Return format depends on SQL clause context
			if ($this->partOfQuery !== "WHERE") {
				return "CASE WHEN {$existsQuery} THEN 1 ELSE 0 END";
			}
			
			return $existsQuery;
		}
		
		// ============================================================================
		// CORE AGGREGATE PROCESSING ENGINE
		// ============================================================================
		
		/**
		 * Core method for processing all aggregate operations with unified logic.
		 *
		 * Handles two main scenarios:
		 * 1. Conditional aggregation: Converts WHERE clauses to CASE WHEN expressions
		 *    Example: SUM(amount WHERE status = 'active') → SUM(CASE WHEN status = 'active' THEN amount END)
		 *
		 * 2. Standard aggregation: Direct function application with optional DISTINCT
		 *    Example: COUNT(DISTINCT customer_id) → COUNT(DISTINCT customer_id)
		 *
		 * Features:
		 * - Automatic NULL handling for SUM operations (COALESCE to 0)
		 * - DISTINCT support for unique operations
		 * - Conditional aggregation via CASE WHEN transformation
		 *
		 * @param AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $ast The aggregate AST node
		 * @param string $aggregateFunction SQL function name (COUNT, SUM, AVG, MIN, MAX)
		 * @param bool $distinct Whether to include DISTINCT keyword for unique operations
		 * @return string Complete SQL aggregate expression
		 */
		private function handleAggregateOperation(
			AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $ast,
			string                                                         $aggregateFunction,
			bool                                                           $distinct = false
		): string {
			// Handle conditional aggregation: aggregate WHERE condition → CASE WHEN condition
			if ($ast->getConditions() !== null) {
				$condition = $this->convertExpressionToSql($ast->getConditions());
				$expression = $this->convertExpressionToSql($ast->getIdentifier());
				$caseExpression = "CASE WHEN {$condition} THEN {$expression} END";
				
				// Build aggregate function with CASE WHEN expression
				$distinctClause = $distinct ? 'DISTINCT ' : '';
				
				if ($aggregateFunction === 'SUM') {
					return "COALESCE({$aggregateFunction}({$distinctClause}{$caseExpression}), 0)";
				} else {
					return "{$aggregateFunction}({$distinctClause}{$caseExpression})";
				}
			}
			
			// Handle standard aggregation
			$sqlExpression = $this->convertExpressionToSql($ast->getIdentifier());
			$distinctClause = $distinct ? 'DISTINCT ' : '';
			
			// Apply function-specific NULL handling
			if ($aggregateFunction === 'SUM') {
				// SUM returns NULL for empty result sets; COALESCE converts to 0
				return "COALESCE({$aggregateFunction}({$distinctClause}{$sqlExpression}), 0)";
			} else {
				// Other functions handle empty sets appropriately:
				// COUNT returns 0, AVG/MIN/MAX return NULL (which is correct behavior)
				return "{$aggregateFunction}({$distinctClause}{$sqlExpression})";
			}
		}
		
		// ============================================================================
		// UTILITY METHODS FOR AST PROCESSING AND SQL GENERATION
		// ============================================================================
		
		/**
		 * Converts AST expressions to their SQL string representations.
		 *
		 * @param AstInterface $expression The AST expression to convert
		 * @return string SQL string representation
		 */
		private function convertExpressionToSql(AstInterface $expression): string {
			return $this->convertToString->visitNodeAndReturnSQL($expression);
		}
		
		/**
		 * Marks an AST expression as already processed to prevent duplicate handling.
		 *
		 * This is important when the same expression appears in multiple contexts
		 * or when subqueries contain expressions that shouldn't be processed again.
		 *
		 * @param AstInterface $expression The AST expression to mark as handled
		 * @return void
		 */
		private function markExpressionAsHandled(AstInterface $expression): void {
			// Trigger the conversion process to mark as handled, discard the result
			$this->convertToString->visitNodeAndReturnSQL($expression);
		}
		
		// ============================================================================
		// SQL CLAUSE CONSTRUCTION METHODS
		// ============================================================================
		
		/**
		 * Constructs the FROM clause with appropriate JOIN syntax for multiple ranges.
		 *
		 * Builds complex FROM clauses by:
		 * 1. Identifying the main table (range without join property)
		 * 2. Adding INNER/LEFT JOINs for related ranges based on their join properties
		 * 3. Using entity-to-table mappings from EntityStore
		 * 4. Applying proper table aliases
		 *
		 * Example output:
		 * `departments` d INNER JOIN `employees` e ON e.dept_id = d.id LEFT JOIN `addresses` a ON a.emp_id = e.id
		 *
		 * @param array $ranges Array of AstRange objects containing entity and join information
		 * @return string Complete FROM clause content (without the "FROM" keyword)
		 * @throws \InvalidArgumentException When no main range is found
		 */
		private function buildFromClauseForRanges(array $ranges): string {
			$mainRange = null;
			$joinRanges = [];
			
			// Separate main range (no join property) from ranges that need joins
			foreach ($ranges as $range) {
				if ($range->getJoinProperty() === null) {
					$mainRange = $range;
				} else {
					$joinRanges[] = $range;
				}
			}
			
			if ($mainRange === null) {
				throw new \InvalidArgumentException("No main range found - at least one range must not have a join property");
			}
			
			// Start with the main table and its alias
			$tableName = $this->entityStore->getOwningTable($mainRange->getEntityName());
			$sql = "`{$tableName}` {$mainRange->getName()}";
			
			// Add JOIN clauses for each related range
			foreach ($joinRanges as $range) {
				$joinTableName = $this->entityStore->getOwningTable($range->getEntityName());
				$joinType = $range->isRequired() ? "INNER" : "LEFT";
				
				// Convert join property to SQL condition
				$joinProperty = $range->getJoinProperty();
				$joinCondition = $this->convertExpressionToSql($joinProperty);
				
				$sql .= " {$joinType} JOIN `{$joinTableName}` {$range->getName()} ON {$joinCondition}";
			}
			
			return $sql;
		}
		
		/**
		 * Constructs WHERE clauses from range join conditions.
		 *
		 * This method processes ranges that have join properties and converts them into
		 * WHERE conditions. It's primarily used for subqueries where join relationships
		 * need to be expressed as WHERE conditions rather than JOIN clauses.
		 *
		 * Example:
		 * Input:  ranges with join properties like "e.dept_id = d.id"
		 * Output: "WHERE e.dept_id = d.id AND e.active = 1"
		 *
		 * @param AstRange[] $ranges Array of ranges that may contain join conditions
		 * @return string Complete WHERE clause with conditions, or empty string if none exist
		 */
		private function buildWhereClauseForRanges(array $ranges): string {
			$conditions = [];
			
			foreach ($ranges as $range) {
				$joinProperty = $range->getJoinProperty();
				
				// Skip ranges without join conditions
				if ($joinProperty === null) {
					continue;
				}
				
				// Convert join property to SQL condition using immutable clone
				$joinConditionSql = $this->sqlBuilder->buildJoinCondition($joinProperty->deepClone());
				
				// Only include non-empty conditions
				if (!empty(trim($joinConditionSql))) {
					$conditions[] = $joinConditionSql;
				}
			}
			
			// Return empty string if no conditions, otherwise build WHERE clause
			return empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);
		}
		
		// ============================================================================
		// RANGE EXTRACTION AND ANALYSIS METHODS
		// ============================================================================
		
		/**
		 * Extracts all unique ranges referenced by an expression and its join properties.
		 *
		 * This method performs comprehensive range discovery by:
		 * 1. Finding all identifier nodes in the expression tree
		 * 2. Extracting ranges from those identifiers
		 * 3. Recursively extracting ranges from join properties
		 * 4. Deduplicating the final range set
		 *
		 * This ensures we capture all tables needed for the complete query, including
		 * those referenced indirectly through join relationships.
		 *
		 * @param AstInterface $expression Expression tree to analyze for range references
		 * @return AstRange[] Array of unique range objects needed for the query
		 */
		private function extractAllRanges(AstInterface $expression): array {
			$identifiers = $this->collectIdentifierNodes($expression);
			$primaryRanges = $this->getAllRanges($identifiers);
			
			// Recursively collect ranges from join properties
			$joinRanges = [];
			
			foreach ($primaryRanges as $range) {
				$joinProperty = $range->getJoinProperty();
				
				if ($joinProperty !== null) {
					$joinIdentifiers = $this->collectIdentifierNodes($joinProperty);
					$additionalRanges = $this->getAllRanges($joinIdentifiers);
					$joinRanges = array_merge($joinRanges, $additionalRanges);
				}
			}
			
			// Merge and deduplicate all discovered ranges
			return $this->deduplicateRanges(array_merge($primaryRanges, $joinRanges));
		}
		
		/**
		 * Traverses the AST tree to collect all AstIdentifier nodes.
		 *
		 * Uses the visitor pattern to efficiently walk the entire AST tree and
		 * collect all identifier nodes, which represent references to entity
		 * properties and ranges in ObjectQuel expressions.
		 *
		 * @param AstInterface $ast Root AST node to search
		 * @return AstIdentifier[] Array of all identifier nodes found in the tree
		 */
		private function collectIdentifierNodes(AstInterface $ast): array {
			$visitor = new CollectNodes(AstIdentifier::class);
			$ast->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Extracts unique database ranges from an array of identifiers.
		 *
		 * Filters identifiers to only include those with database ranges (not in-memory ranges),
		 * and ensures each range appears only once in the result set based on range name.
		 *
		 * This prevents duplicate table references in generated SQL queries.
		 *
		 * @param AstIdentifier[] $identifiers Array of identifier nodes to process
		 * @return AstRange[] Array of unique database ranges
		 */
		private function getAllRanges(array $identifiers): array {
			$result = [];
			$seen = [];
			
			foreach ($identifiers as $identifier) {
				$range = $identifier->getRange();
				
				// Only process database ranges, skip in-memory or other range types
				if (!$range instanceof AstRangeDatabase) {
					continue;
				}
				
				$rangeName = $range->getName();
				
				// Deduplicate based on range name
				if (!isset($seen[$rangeName])) {
					$seen[$rangeName] = true;
					$result[] = $range;
				}
			}
			
			return $result;
		}
		
		/**
		 * Removes duplicate ranges from an array based on range names.
		 *
		 * Used as a final deduplication step when merging ranges from multiple sources
		 * (primary expressions and join properties) to ensure each range appears only once.
		 *
		 * @param AstRange[] $ranges Array of ranges that may contain duplicates
		 * @return AstRange[] Array of unique ranges
		 */
		private function deduplicateRanges(array $ranges): array {
			$result = [];
			$seen = [];
			
			foreach ($ranges as $range) {
				$rangeName = $range->getName();
				
				if (!isset($seen[$rangeName])) {
					$seen[$rangeName] = true;
					$result[] = $range;
				}
			}
			
			return $result;
		}
		
		// ============================================================================
		// AST NAVIGATION UTILITIES
		// ============================================================================
		
		/**
		 * Searches up the AST tree to find the closest AstRetrieve parent node.
		 *
		 * AstRetrieve nodes represent the main query structure in ObjectQuel.
		 * This method is useful for understanding the broader query context
		 * when processing individual aggregate operations.
		 *
		 * @param AstInterface $ast Starting AST node to search from
		 * @return AstRetrieve|null The closest AstRetrieve ancestor, or null if none found
		 */
		public function getRetrieveNode(AstInterface $ast): ?AstRetrieve {
			$current = $ast->getParent();
			
			while ($current !== null) {
				if ($current instanceof AstRetrieve) {
					return $current;
				}
				
				$current = $current->getParent();
			}
			
			return null;
		}
	}