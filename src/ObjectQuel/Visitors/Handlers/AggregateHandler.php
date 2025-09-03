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
					return $this->buildExistsSubquery($subquery);
				
				case AstSubquery::TYPE_CASE_WHEN:
					return $this->buildCaseWhenExistsSubquery($subquery);
				
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
		 * @param AstSubquery|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $type The aggregation AST node
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
		 * Builds a CASE WHEN EXISTS subquery for ANY functions in SELECT clauses.
		 * Converts ANY(d.id) to CASE WHEN EXISTS(SELECT 1 FROM ...) THEN 1 ELSE 0 END
		 * @param AstSubquery $subquery The subquery containing the ANY aggregate
		 * @return string SQL CASE WHEN statement with EXISTS subquery
		 */
		private function buildCaseWhenExistsSubquery(AstSubquery $subquery): string {
			// Extract all ranges needed for the subquery
			$ranges = $subquery->getCorrelatedRanges();
			
			// Build the EXISTS subquery components
			$fromClause = $this->buildFromClauseForRanges($ranges);
			
			if ($subquery->getConditions() !== null) {
				$conditions = $this->convertExpressionToSql($subquery->getConditions());
				$whereClause = "WHERE {$conditions}";
			} else {
				$whereClause = "";
			}
			
			// Build the complete EXISTS subquery
			// We use SELECT 1 for efficiency since we only care about existence
			$existsQuery = "EXISTS (SELECT 1 FROM {$fromClause} {$whereClause} LIMIT 1)";
			
			// Wrap in CASE WHEN to return 1/0 instead of boolean
			return "CASE WHEN {$existsQuery} THEN 1 ELSE 0 END";
		}

		/**
		 * Builds an EXISTS subquery for ANY functions in WHERE clauses.
		 * Converts ANY(d.id) to EXISTS(SELECT 1 FROM ...)
		 * @param AstSubquery $subquery The subquery containing the ANY aggregate
		 * @return string SQL EXISTS statement
		 */
		private function buildExistsSubquery(AstSubquery $subquery): string {
			$aggregation = $subquery->getAggregation();
			
			// Ensure this is an ANY aggregation
			if (!$aggregation instanceof AstAny) {
				throw new \InvalidArgumentException("EXISTS subquery type requires AstAny aggregation");
			}
			
			// Mark the aggregation as handled to prevent duplicate processing
			$this->markExpressionAsHandled($aggregation);
			
			// Get the identifier being checked for existence
			$identifier = $aggregation->getIdentifier();
			
			// Extract all ranges needed for the subquery
			$ranges = $this->extractAllRanges($identifier);
			
			// Build the EXISTS subquery components
			$fromClause = $this->buildFromClauseForRanges($ranges);
			$whereClause = $this->buildWhereClauseForRanges($ranges);
			
			// Build and return the EXISTS subquery
			// LIMIT 1 optimizes performance since we only need to know if any record exists
			return "EXISTS (SELECT 1 FROM {$fromClause} {$whereClause} LIMIT 1)";
		}
		
		/**
		 * Converts AstAny nodes to appropriate SQL existence checks.
		 * @param AstAny $ast The ANY AST node to process
		 * @return string Generated SQL expression for existence check
		 */
		public function handleAny(AstAny $ast): string {
			// Check if this ANY is contained within a subquery that already processed it
			$parent = $ast->getParent();
			
			while ($parent !== null) {
				if ($parent instanceof AstSubquery) {
					error_log("Skipping ANY - already processed by subquery wrapper");
					return ""; // Return empty - subquery already handled this
				}
				
				$parent = $parent->getParent();
			}
			
			// Convert to EXISTS check with the referenced identifier
			$identifier = $ast->getIdentifier();
			$ranges = $this->extractAllRanges($identifier);
			
			// Mark as handled to prevent duplicate processing
			$this->markExpressionAsHandled($identifier);
			
			// Build EXISTS subquery
			$fromClause = $this->buildFromClauseForRanges($ranges);
			$whereClause = $this->buildWhereClauseForRanges($ranges);
			
			$existsQuery = "EXISTS (SELECT 1 FROM {$fromClause} {$whereClause} LIMIT 1)";
			
			// Return format depends on context
			if ($this->partOfQuery !== "WHERE") {
				return "CASE WHEN {$existsQuery} THEN 1 ELSE 0 END";
			}
			
			return $existsQuery;
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
		 * Builds the FROM clause portion of a SQL query based on the provided ranges.
		 * Maps entity names to their corresponding database table names and creates proper aliases.
		 * @param array $ranges Array of range objects containing entity and alias information
		 * @return string Complete FROM clause content (without the "FROM" keyword)
		 */
		private function buildFromClauseForRanges(array $ranges): string {
			$mainRange = null;
			$joinRanges = [];
			
			// Separate main range from join ranges
			foreach ($ranges as $range) {
				if ($range->getJoinProperty() === null) {
					$mainRange = $range;
				} else {
					$joinRanges[] = $range;
				}
			}
			
			if ($mainRange === null) {
				throw new \InvalidArgumentException("No main range found");
			}
			
			// Start with main table
			$tableName = $this->entityStore->getOwningTable($mainRange->getEntityName());
			$sql = "`{$tableName}` {$mainRange->getName()}";
			
			// Add proper JOIN syntax for each joined range
			foreach ($joinRanges as $range) {
				$joinTableName = $this->entityStore->getOwningTable($range->getEntityName());
				$joinType = $range->isRequired() ? "INNER" : "LEFT";
				
				// Debug the join property
				$joinProperty = $range->getJoinProperty();
				$joinCondition = $this->convertExpressionToSql($joinProperty);

				// Put in HTML
				$sql .= " {$joinType} JOIN `{$joinTableName}` {$range->getName()} ON {$joinCondition}";
			}
			
			return $sql;
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
		
		/**
		 * Extracts all unique ranges from the expression by analyzing identifier nodes.
		 * Also extracts ranges from join properties to get complete range set.
		 * @param AstInterface $expression Expression to extract ranges from
		 * @return AstRange[] Array of unique range objects
		 */
		private function extractAllRanges(AstInterface $expression): array {
			$identifiers = $this->collectIdentifierNodes($expression);
			$allRanges = $this->getAllRanges($identifiers);
			
			// Also collect ranges from join properties
			$additionalRanges = [];
			
			foreach ($allRanges as $range) {
				$joinProperty = $range->getJoinProperty();
				
				if ($joinProperty !== null) {
					$joinIdentifiers = $this->collectIdentifierNodes($joinProperty);
					$joinRanges = $this->getAllRanges($joinIdentifiers);
					$additionalRanges = array_merge($additionalRanges, $joinRanges);
				}
			}
			
			// Merge and deduplicate all ranges
			return $this->deduplicateRanges(array_merge($allRanges, $additionalRanges));
		}
		
		/**
		 * Traverses the AST tree to find all AstIdentifier nodes.
		 * Identifiers represent references to entity properties and ranges in the query.
		 * @param AstInterface $ast Root AST node to search
		 * @return AstIdentifier[] Array of all identifier nodes found
		 */
		private function collectIdentifierNodes(AstInterface $ast): array {
			$visitor = new CollectNodes(AstIdentifier::class);
			$ast->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * Gets unique ranges from an array of identifiers, filtering for database ranges only.
		 * Ensures each range appears only once in the result set.
		 * @param AstIdentifier[] $identifiers Array of identifier nodes
		 * @return AstRange[] Array of unique ranges
		 */
		private function getAllRanges(array $identifiers): array {
			$result = [];
			$seen = [];
			
			foreach ($identifiers as $identifier) {
				$range = $identifier->getRange();
				
				if (!$range instanceof AstRangeDatabase) {
					continue;
				}
				
				$rangeName = $range->getName();
				
				if (!isset($seen[$rangeName])) {
					$seen[$rangeName] = true;
					$result[] = $range;
				}
			}
			
			return $result;
		}
		
		/**
		 * Removes duplicate ranges based on range name.
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
		
		/**
		 * Returns the parent aggregate if any
		 * @return AstInterface|null
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