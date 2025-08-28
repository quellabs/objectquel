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
			
			// Convert to SQL. This only serves to mark the nodes as handled
			$this->convertExpressionToSql($expression);
			
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
			$sqlExpression = $this->convertExpressionToSql($expression);
			$distinctClause = $distinct ? 'DISTINCT ' : '';
			
			// Apply function-specific formatting and NULL handling
			if ($aggregateFunction === 'SUM') {
				return "COALESCE({$aggregateFunction}({$distinctClause}{$sqlExpression}), 0)";
			} else {
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
			$identifiers = $this->collectIdentifierNodes($expression);
			$ranges = $this->extractAllRanges($expression);
			
			$sqlExpression = $this->convertExpressionToSql($expression);
			// Check for optimizations
			if (!empty($identifiers)) {
				$isSingleRange = $this->isSingleRangeQuery($identifiers[0]);
				$isEquivalent = $this->isEquivalentRangeScenario($expression);
				
				// NEW: Direct check without counting identifiers
				$isSameEntitySelfJoin = false;
				if (count($ranges) === 1) {
					$range = $ranges[0];
					$joinProp = $range->getJoinProperty();
					
					if ($joinProp) {
						// Convert join to SQL and check if it's a simple same-entity equality
						$joinSQL = $this->convertExpressionToSql($joinProp);
						
						// Check if the current range entity matches other ranges in query
						$currentEntity = $range->getEntityName();
						
						// Get all ranges from the base query
						$baseQuery = $this->getBaseQuery($expression);
						if ($baseQuery) {
							$allRanges = $this->extractAllRanges($baseQuery);
							
							// Look for another range with same entity
							foreach ($allRanges as $otherRange) {
								if ($otherRange->getName() !== $range->getName() &&
									$otherRange->getEntityName() === $currentEntity) {
									// Found same entity - this is likely a self-join
									$isSameEntitySelfJoin = true;
									break;
								}
							}
						}
					}
				}
				
				// Apply optimizations
				if ($isSingleRange || $isEquivalent || $isSameEntitySelfJoin) {
					return "1";
				}
			}
			
			// Fall back to existing logic
			$queryAnalysis = $this->analyzeQueryStructure($expression);
			
			if (!$queryAnalysis['hasJoins']) {
				return $this->buildAnyExistsSubquery($expression);
			}
			
			if (!$queryAnalysis['allRangesRequired']) {
				return "CASE WHEN {$sqlExpression} IS NOT NULL THEN 1 ELSE 0 END";
			}
			
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
			$ranges = $this->extractAllRanges($expression);
			$fromClause = $this->buildFromClauseForRanges($ranges);
			$whereClause = $this->buildWhereClauseForRanges($ranges);
			
			$existsQuery = "EXISTS (SELECT 1 FROM {$fromClause} {$whereClause} LIMIT 1)";
			
			return match ($this->partOfQuery) {
				"WHERE" => $existsQuery,
				default => "CASE WHEN {$existsQuery} THEN 1 ELSE 0 END"
			};
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
			
			// Must have required ranges only (no LEFT JOINs)
			if (!$this->allRangesRequired($ranges)) {
				return false;
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