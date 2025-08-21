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
	
	/**
	 * This class is responsible for converting ObjectQuel aggregate AST nodes
	 * into appropriate SQL aggregate functions and EXISTS queries. It handles
	 * both regular and DISTINCT versions of aggregate operations.
	 */
	class AggregateHandler {
		
		/** @var EntityStore Store for entity metadata and table mappings */
		private EntityStore $entityStore;
		
		/** @var array Reference to query parameters array for parameterized queries */
		private array $parameters;
		
		/** @var string Current part of the query being built (e.g., 'VALUES', 'WHERE') */
		private string $partOfQuery;
		
		/** @var SqlBuilderHelper Helper for building SQL components */
		private SqlBuilderHelper $sqlBuilder;
		
		/**
		 * Constructor - initializes the aggregate handler with required dependencies
		 * @param EntityStore $entityStore Store containing entity-to-table mappings
		 * @param array $parameters Reference to parameters array for the query
		 * @param string $partOfQuery Which part of the query is being built
		 * @param SqlBuilderHelper $sqlBuilder Helper for SQL construction
		 */
		public function __construct(
			EntityStore      $entityStore,
			array            &$parameters,
			string           $partOfQuery,
			SqlBuilderHelper $sqlBuilder
		) {
			$this->entityStore = $entityStore;
			$this->parameters = &$parameters;  // Pass by reference to allow modification
			$this->partOfQuery = $partOfQuery;
			$this->sqlBuilder = $sqlBuilder;
		}
		
		/**
		 * Handles COUNT operations
		 * Converts AstCount nodes to SQL COUNT() functions
		 * @param AstCount $count The COUNT AST node to process
		 * @return string Generated SQL COUNT expression
		 */
		public function handleCount(AstCount $count): string {
			return $this->handleAggregateOperation($count, 'COUNT');
		}
		
		/**
		 * Handles COUNT UNIQUE operations
		 * Converts AstCountU nodes to SQL COUNT(DISTINCT) functions
		 * @param AstCountU $count The COUNT UNIQUE AST node to process
		 * @return string Generated SQL COUNT(DISTINCT) expression
		 */
		public function handleCountU(AstCountU $count): string {
			return $this->handleAggregateOperation($count, 'COUNT', true);
		}
		
		/**
		 * Handles AVG operations
		 * Converts AstAvg nodes to SQL AVG() functions
		 * @param AstAvg $avg The AVG AST node to process
		 * @return string Generated SQL AVG expression
		 */
		public function handleAvg(AstAvg $avg): string {
			return $this->handleAggregateOperation($avg, 'AVG');
		}
		
		/**
		 * Handles AVG UNIQUE operations
		 * Converts AstAvgU nodes to SQL AVG(DISTINCT) functions
		 * @param AstAvgU $avg The AVG UNIQUE AST node to process
		 * @return string Generated SQL AVG(DISTINCT) expression
		 */
		public function handleAvgU(AstAvgU $avg): string {
			return $this->handleAggregateOperation($avg, 'AVG', true);
		}
		
		/**
		 * Handles MAX operations
		 * Converts AstMax nodes to SQL MAX() functions
		 * @param AstMax $max The MAX AST node to process
		 * @return string Generated SQL MAX expression
		 */
		public function handleMax(AstMax $max): string {
			return $this->handleAggregateOperation($max, 'MAX');
		}
		
		/**
		 * Handles MIN operations
		 * Converts AstMin nodes to SQL MIN() functions
		 * @param AstMin $min The MIN AST node to process
		 * @return string Generated SQL MIN expression
		 */
		public function handleMin(AstMin $min): string {
			return $this->handleAggregateOperation($min, 'MIN');
		}
		
		/**
		 * Handles SUM operations
		 * Converts AstSum nodes to SQL SUM() functions
		 * @param AstSum $sum The SUM AST node to process
		 * @return string Generated SQL SUM expression
		 */
		public function handleSum(AstSum $sum): string {
			return $this->handleAggregateOperation($sum, 'SUM');
		}
		
		/**
		 * Handles SUM UNIQUE operations
		 * Converts AstSumU nodes to SQL SUM(DISTINCT) functions
		 * @param AstSumU $sum The SUM UNIQUE AST node to process
		 * @return string Generated SQL SUM(DISTINCT) expression
		 */
		public function handleSumU(AstSumU $sum): string {
			return $this->handleAggregateOperation($sum, "SUM", true);
		}
		
		/**
		 * Handles ANY operations
		 *
		 * ANY operations behave differently based on context:
		 * - In VALUES: Returns 1/0 indicating existence
		 * - In WHERE: Returns boolean condition for filtering
		 *
		 * @param AstAny $ast The ANY AST node to process
		 * @return string Generated SQL expression for existence check
		 */
		public function handleAny(AstAny $ast): string {
			return match ($this->partOfQuery) {
				"WHERE" => $this->handleAnyWhere($ast),
				default => $this->handleAggregateOperation($ast, "ANY"),
			};
		}
		
		/**
		 * Universal handler for all aggregate functions including ANY values
		 * This method handles the core logic for converting aggregate operations
		 * into SQL. It supports both regular and DISTINCT variants.
		 * @param AstAny|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $ast The aggregate AST node
		 * @param string $aggregateFunction The SQL aggregate function name (COUNT, SUM, etc.)
		 * @param bool $distinct Whether to add DISTINCT clause
		 * @return string Generated SQL aggregate expression
		 */
		private function handleAggregateOperation(
			AstAny|AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum|AstSumU $ast,
			string                                                                $aggregateFunction,
			bool                                                                  $distinct = false
		): string {
			// Extract the identifier (field/column reference) from the AST node
			$identifier = $ast->getIdentifier();
			
			// Validate that we have a proper identifier
			if (!$identifier instanceof AstIdentifier) {
				return '';  // Return empty string for invalid identifiers
			}
			
			// Build the column name/reference for this identifier
			$column = $this->sqlBuilder->buildColumnName($identifier);
			
			switch ($aggregateFunction) {
				case 'ANY':
					// Handle ANY operations - check for existence of related records
					if (!$identifier->getRange()->includeAsJoin()) {
						// Use EXISTS subquery when not using JOINs
						return $this->handleAnyWithExists($identifier, $identifier->getRange());
					} elseif (!$identifier->getRange()->isRequired()) {
						// LEFT JOIN - check if column has value
						return "CASE WHEN {$column} IS NOT NULL THEN 1 ELSE 0 END";
					} else {
						// INNER JOIN guarantees existence
						return "1";
					}
				
				default:
					// Handle standard aggregate functions (COUNT, SUM, AVG, MIN, MAX)
					$distinctClause = $distinct ? 'DISTINCT ' : '';
					return "{$aggregateFunction}({$distinctClause}{$column})";
			}
		}
		
		/**
		 * Handles "ANY WHERE" clauses in the query AST
		 * When ANY is used in a WHERE clause, it needs to return a boolean
		 * condition rather than a 1/0 value.
		 * @param AstAny $ast The ANY AST node in WHERE context
		 * @return string Generated SQL boolean condition
		 */
		private function handleAnyWhere(AstAny $ast): string {
			// Extract identifier from the ANY operation
			$identifier = $ast->getIdentifier();
			
			if (!$identifier instanceof AstIdentifier) {
				return '';  // Invalid identifier
			}
			
			$range = $identifier->getRange();
			
			if (!$range->includeAsJoin()) {
				// Not using JOINs - need to handle with EXISTS or simple condition
				if ($this->isSingleRangeQuery($identifier)) {
					// Single range query - always true
					return "1 = 1";
				} else {
					// Multi-range query - use EXISTS subquery
					return $this->handleAnyWhereExists($identifier, $range);
				}
			} elseif (!$range->isRequired()) {
				// LEFT JOIN - check if the joined column has a value
				$column = $this->sqlBuilder->buildColumnName($identifier);
				return "{$column} IS NOT NULL";
			} else {
				// INNER JOIN - relationship always exists
				return "1 = 1"; // Always true with INNER JOIN
			}
		}
		
		/**
		 * Handles ANY operations with EXISTS subqueries
		 * When relationships aren't handled via JOINs, we need to use
		 * EXISTS subqueries to check for related record existence.
		 * @param AstIdentifier $identifier The identifier referencing related data
		 * @param AstRange $range The range defining the relationship
		 * @return string Generated SQL with EXISTS subquery or simple value
		 */
		private function handleAnyWithExists(AstIdentifier $identifier, AstRange $range): string {
			if ($this->isSingleRangeQuery($identifier)) {
				// Single range query - simplified logic
				return "1";
			}
			
			// Generate EXISTS subquery and format for current context
			$existsQuery = $this->generateExistsSubquery($identifier, $range);
			return $this->formatQueryForContext($existsQuery);
		}
		
		/**
		 * Generates an EXISTS subquery for ANY WHERE clauses
		 * Similar to handleAnyWithExists but specifically for WHERE clause context.
		 * @param AstIdentifier $identifier The identifier for the relationship
		 * @param AstRange $range The range defining how to join
		 * @return string Generated EXISTS subquery or simple condition
		 */
		private function handleAnyWhereExists(AstIdentifier $identifier, AstRange $range): string {
			// Single range - always true in WHERE context
			if ($this->isSingleRangeQuery($identifier)) {
				return "1 = 1";
			}
			
			// Generate the EXISTS subquery
			return $this->generateExistsSubquery($identifier, $range);
		}
		
		/**
		 * Generates the core EXISTS subquery
		 * Creates a subquery that checks for the existence of related records
		 * based on the relationship defined in the range.
		 * @param AstIdentifier $identifier The field identifier
		 * @param AstRange $range The range defining the relationship
		 * @return string Complete EXISTS subquery
		 */
		private function generateExistsSubquery(AstIdentifier $identifier, AstRange $range): string {
			// Get entity and table information
			$entityName = $identifier->getEntityName();
			$tableName = $this->entityStore->getOwningTable($entityName);
			$rangeAlias = $range->getName();  // Table alias for the subquery
			
			// Initialize WHERE clause
			$whereClause = '';
			$joinProperty = $range->getJoinProperty();
			
			if ($joinProperty) {
				// Build the join condition for relating records
				$joinCondition = $joinProperty->deepClone();  // Clone to avoid side effects
				$joinConditionSql = $this->sqlBuilder->buildJoinCondition($joinCondition);
				
				// Only add WHERE clause if we have a valid join condition
				if (!empty($joinConditionSql) && trim($joinConditionSql) !== '') {
					$whereClause = "WHERE {$joinConditionSql}";
				}
			}
			
			// Return the complete EXISTS subquery
			// LIMIT 1 optimizes performance since we only care about existence
			return "EXISTS (
		        SELECT 1
		        FROM `{$tableName}` {$rangeAlias}
		        {$whereClause}
		        LIMIT 1
		    )";
		}
		
		/**
		 * Formats the EXISTS query based on the current query context
		 *
		 * Different parts of a SQL query expect different formats:
		 * - WHERE clauses expect boolean conditions
		 * - SELECT clauses expect values (1/0)
		 *
		 * @param string $existsQuery The EXISTS subquery to format
		 * @return string Properly formatted query for the current context
		 */
		private function formatQueryForContext(string $existsQuery): string {
			if ($this->partOfQuery === "WHERE") {
				// WHERE context - return the EXISTS query directly as boolean condition
				return $existsQuery;
			}
			
			// Other contexts (like SELECT) - convert to 1/0 value
			return "CASE WHEN {$existsQuery} THEN 1 ELSE 0 END";
		}
		
		/**
		 * Finds the base AstRetrieve node by traversing up the AST hierarchy
		 * The AstRetrieve node represents the main query and contains information
		 * about whether this is a single-range query or more complex.
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
			
			return null;  // No AstRetrieve found in the hierarchy
		}
		
		/**
		 * Determines if the given AST represents a single range query
		 * Single range queries are simpler and can use optimized logic
		 * compared to multi-range queries that require complex JOINs.
		 * @param AstInterface $ast The AST node to check
		 * @return bool True if this is a single range query, false otherwise
		 */
		private function isSingleRangeQuery(AstInterface $ast): bool {
			// Find the base query node
			$queryNode = $this->getBaseQuery($ast);
			
			// Check if it's a single range query (null-safe with ?? operator)
			return $queryNode?->isSingleRangeQuery() ?? false;
		}
	}