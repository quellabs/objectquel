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
	 * This class is responsible for converting ObjectQuel aggregate AST nodes
	 * into appropriate SQL aggregate functions and EXISTS queries. It handles
	 * both regular and DISTINCT versions of aggregate operations.
	 */
	class AggregateHandler {
		
		/** @var EntityStore Store for entity metadata and table mappings */
		private EntityStore $entityStore;
		
		/** @var string Current part of the query being built (e.g., 'VALUES', 'WHERE') */
		private string $partOfQuery;
		
		/** @var SqlBuilderHelper Helper for building SQL components */
		private SqlBuilderHelper $sqlBuilder;
		private QuelToSQLConvertToString $convertToString;
		
		/**
		 * Constructor - initializes the aggregate handler with required dependencies
		 * @param EntityStore $entityStore Store containing entity-to-table mappings
		 * @param string $partOfQuery Which part of the query is being built
		 * @param SqlBuilderHelper $sqlBuilder Helper for SQL construction
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
		 * Returns all identifier nodes
		 * @param AstInterface $ast
		 * @return array
		 */
		private function collectNodes(AstInterface $ast): array {
			$visitor = new CollectNodes(AstIdentifier::class);
			$ast->accept($visitor);
			return $visitor->getCollectedNodes();
		}
		
		/**
		 * @param AstIdentifier[] $identifiers
		 * @return AstRange[]
		 */
		private function getAllRanges(array $identifiers): array {
			$result = [];
			$seen = []; // Track range names to avoid duplicates
			
			foreach ($identifiers as $identifier) {
				$range = $identifier->getRange();
				
				if ($range === null) {
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
		 * @param AstRange[] $ranges
		 * @return bool
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
		 * @param AstRange[] $ranges
		 * @return bool
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
			$expression = $ast->getIdentifier();
			
			// Handle ANY first (it has special logic)
			if ($aggregateFunction === 'ANY') {
				return $this->handleAnyOptimized($expression);
			}
			
			// Try to optimize other aggregates to subquery
			if ($this->canOptimizeToSubquery($expression)) {
				return $this->buildAggregateSubquery($expression, $aggregateFunction, $distinct);
			}
			
			// Regular JOIN-based approach
			$sqlExpression = $this->convertToString->visitNodeAndReturnSQL($expression);
			$distinctClause = $distinct ? 'DISTINCT ' : '';
			
			return match ($aggregateFunction) {
				'SUM' => "COALESCE({$aggregateFunction}({$distinctClause}{$sqlExpression}), 0)",
				default => "{$aggregateFunction}({$distinctClause}{$sqlExpression})"
			};
		}
		
		private function handleAnyOptimized(AstInterface $expression): string {
			$allIdentifiers = $this->collectNodes($expression);
			$allRanges = $this->getAllRanges($allIdentifiers);
			$allIdentifiersIncludedAsJoin = $this->allIdentifiersIncludedAsJoin($allRanges);
			$allRangesRequired = $this->allRangesRequired($allRanges);
			
			if (!$allIdentifiersIncludedAsJoin) {
				// Use EXISTS subquery when not using JOINs
				return $this->buildAnyExistsSubquery($expression);
			} elseif (!$allRangesRequired) {
				// LEFT JOIN - check if expression has value
				$sqlExpression = $this->convertToString->visitNodeAndReturnSQL($expression);
				return "CASE WHEN {$sqlExpression} IS NOT NULL THEN 1 ELSE 0 END";
			} else {
				// INNER JOIN guarantees existence
				return "1";
			}
		}
		
		private function buildAggregateSubquery(AstInterface $expression, string $aggregateFunction, bool $distinct): string {
			$allIdentifiers = $this->collectNodes($expression);
			$allRanges = $this->getAllRanges($allIdentifiers);
			
			$sqlExpression = $this->convertToString->visitNodeAndReturnSQL($expression);
			$distinctClause = $distinct ? 'DISTINCT ' : '';
			
			// Build FROM clause with all needed tables
			$fromClause = $this->buildFromClauseForRanges($allRanges);
			
			// Build WHERE clause with all join conditions
			$whereClause = $this->buildWhereClauseForRanges($allRanges);
			
			$subquery = "(SELECT {$aggregateFunction}({$distinctClause}{$sqlExpression}) FROM {$fromClause} {$whereClause})";
			
			return match ($aggregateFunction) {
				'SUM' => "COALESCE({$subquery}, 0)",
				default => $subquery
			};
		}
		
		private function buildFromClauseForRanges(array $ranges): string {
			$tables = [];
			
			foreach ($ranges as $range) {
				$entityName = $range->getEntityName(); // You'll need this method
				$tableName = $this->entityStore->getOwningTable($entityName);
				$rangeAlias = $range->getName();
				
				$tables[] = "`{$tableName}` {$rangeAlias}";
			}
			
			return implode(', ', $tables);
		}
		
		private function buildWhereClauseForRanges(array $ranges): string {
			$conditions = [];
			
			foreach ($ranges as $range) {
				if ($joinProperty = $range->getJoinProperty()) {
					$joinConditionSql = $this->sqlBuilder->buildJoinCondition($joinProperty->deepClone());
					if (!empty(trim($joinConditionSql))) {
						$conditions[] = $joinConditionSql;
					}
				}
			}
			
			if (empty($conditions)) {
				return '';
			}
			
			return 'WHERE ' . implode(' AND ', $conditions);
		}
		
		private function buildAnyExistsSubquery(AstInterface $expression): string {
			$allIdentifiers = $this->collectNodes($expression);
			$allRanges = $this->getAllRanges($allIdentifiers);
			
			if ($this->isSingleRangeQuery($allIdentifiers[0])) {
				return "1";
			}
			
			// Build FROM clause with all needed tables
			$fromClause = $this->buildFromClauseForRanges($allRanges);
			
			// Build WHERE clause with all join conditions
			$whereClause = $this->buildWhereClauseForRanges($allRanges);
			
			$existsQuery = "EXISTS (SELECT 1 FROM {$fromClause} {$whereClause} LIMIT 1)";
			
			// Format for context (WHERE vs SELECT)
			if ($this->partOfQuery === "WHERE") {
				return $existsQuery;
			}
			
			return "CASE WHEN {$existsQuery} THEN 1 ELSE 0 END";
		}
		
		/**
		 * Handles "ANY WHERE" clauses in the query AST
		 * When ANY is used in a WHERE clause, it needs to return a boolean condition
		 * @param AstAny $ast The ANY AST node in WHERE context
		 * @return string Generated SQL boolean condition
		 */
		private function handleAnyWhere(AstAny $ast): string {
			$expression = $ast->getIdentifier();
			
			$sqlExpression = $this->convertToString->visitNodeAndReturnSQL($expression);
			
			// Check if we can optimize to EXISTS subquery
			if ($this->canOptimizeToSubquery($expression)) {
				return $this->buildAnyExistsSubquery($expression);
			}
			
			// Fall back to JOIN-based approach
			$allIdentifiers = $this->collectNodes($expression);
			$allRanges = $this->getAllRanges($allIdentifiers);
			$allIdentifiersIncludedAsJoin = $this->allIdentifiersIncludedAsJoin($allRanges);
			$allRangesRequired = $this->allRangesRequired($allRanges);
			
			// Single range query - always true
			if (!$allIdentifiersIncludedAsJoin) {
				if ($this->isSingleRangeQuery($allIdentifiers[0] ?? null)) {
					return "1 = 1";
				}
				
				// This shouldn't happen if canOptimizeToSubquery works correctly
				return $this->buildAnyExistsSubquery($expression);
			}
			
			// LEFT JOIN - check if expression has value
			if (!$allRangesRequired) {
				return "{$sqlExpression} IS NOT NULL";
			}
			
			// INNER JOIN - relationship always exists
			return "1 = 1";
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
			
			return null;
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
		
		/**
		 * Checks if we can optimize this aggregate to use a subquery instead of JOIN
		 */
		private function canOptimizeToSubquery(AstInterface $expression): bool {
			$allIdentifiers = $this->collectNodes($expression);
			$allRanges = $this->getAllRanges($allIdentifiers);
			$allRangesRequired = $this->allRangesRequired($allRanges);
			$allIdentifiersIncludedAsJoin = $this->allIdentifiersIncludedAsJoin($allRanges);
			
			// Must be mandatory ranges only
			if (!$allRangesRequired) {
				return false;
			}
			
			// Must not be included as JOIN (meaning they're not used elsewhere)
			if ($allIdentifiersIncludedAsJoin) {
				return false;
			}
			
			return true;
		}
	}