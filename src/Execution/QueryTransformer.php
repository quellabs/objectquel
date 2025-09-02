<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Execution\RangeReferences\ReferenceAggregateWhere;
	use Quellabs\ObjectQuel\Execution\Visitors\VisitorAddRangeReferences;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\FindIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\GetMainEntityInAst;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\GetMainEntityInAstException;
	
	class QueryTransformer {
		
		private EntityStore $entityStore;
		private DatabaseAdapter $connection;
		
		/**
		 * QueryBuilder constructor
		 * @param EntityManager $entityManager
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityStore = $entityManager->getEntityStore();
			$this->connection = $entityManager->getConnection();
		}
		
		/**
		 * Transform the query
		 * @param AstRetrieve $ast
		 * @param array $parameters
		 * @return void
		 */
		public function transform(AstRetrieve $ast, array $parameters): void {
			// Set range references
			$this->setRangeReferences($ast);
			
			// Check if the query requires pagination (has window clauses)
			if (!$this->requiresPagination($ast)) {
				return;
			}
			
			// Apply pagination logic using the provided parameters
			// This may involve primary key fetching, result counting, and query modification
			// Parameters are needed for pagination to work with bound values in conditions
			$this->processPagination($ast, $parameters);
		}
		
		// ========== RANGE REFERENCE SETTER ==========
		
		/**
		 * Walk through the AST and collect all references to ranges across all query clauses.
		 * @param AstRetrieve $retrieve The root AST node containing the complete query structure
		 * @return void
		 */
		private function setRangeReferences(AstRetrieve $retrieve): void {
			foreach($retrieve->getRanges() as $range) {
				// Reset visitor state once per range to prevent cross-contamination
				// This ensures each range's references are tracked independently
				VisitorAddRangeReferences::resetVisitedNodes();
				
				// Pre-collect all aggregate functions to minimize AST traversals
				// This optimization reduces O(n²) traversals to O(n) by collecting once
				$selectAggregates = $this->collectAggregatesFromValues($retrieve->getValues());
				$whereAggregates = $retrieve->getConditions() ? $this->findAggregates($retrieve->getConditions()) : [];
				$sortAggregates = $this->collectAggregatesFromSort($retrieve->getSort());
				
				// Process aggregate WHERE clauses first - these create nested scopes
				// Example: SUM(field WHERE range.condition) needs special handling
				$this->processAggregateConditions($selectAggregates, $range, 'SELECT');
				$this->processAggregateConditions($whereAggregates, $range, 'WHERE');
				$this->processAggregateConditions($sortAggregates, $range, 'ORDER_BY');
				
				// Process main query clauses after aggregates to maintain proper scope hierarchy
				$this->processClauseExpressions($retrieve->getValues(), $range, 'SELECT');
				$this->processClauseExpressions([$retrieve->getConditions()], $range, 'WHERE');
				$this->processClauseExpressions(array_column($retrieve->getSort(), 'ast'), $range, 'ORDER_BY');
			}
		}
		
		/**
		 * Collect aggregate functions from multiple SELECT value expressions.
		 * @param array $values Array of AST nodes representing SELECT expressions
		 * @return array Flat array of all aggregate function nodes found
		 */
		private function collectAggregatesFromValues(array $values): array {
			$aggregates = [];
			
			foreach ($values as $value) {
				$aggregates = array_merge($aggregates, $this->findAggregates($value));
			}
			
			return $aggregates;
		}
		
		/**
		 * Extracts aggregate functions from sorting expressions, which may contain
		 * complex calculations like SUM(field) or COUNT(*) in ORDER BY clauses.
		 * @param array $sortExpressions Array of sort definitions, each containing an 'ast' key
		 * @return array Flat array of all aggregate function nodes found in sort expressions
		 */
		private function collectAggregatesFromSort(array $sortExpressions): array {
			$aggregates = [];
			
			foreach ($sortExpressions as $sort) {
				$aggregates = array_merge($aggregates, $this->findAggregates($sort['ast']));
			}
			
			return $aggregates;
		}
		
		/**
		 * Process WHERE conditions within aggregate functions for range references.
		 * @param array $aggregates Array of aggregate function AST nodes
		 * @param AstRange $range The range variable being tracked for references
		 * @param string $context The parent clause context ('SELECT', 'WHERE', 'ORDER_BY')
		 * @return void
		 */
		private function processAggregateConditions(array $aggregates, AstRange $range, string $context): void {
			foreach ($aggregates as $aggregate) {
				// Only process aggregates that have their own filtering conditions
				if ($aggregate->getConditions() === null) {
					continue;
				}
				
				// Create specialized visitor for aggregate WHERE context
				// The 'AGGREGATE_WHERE' type distinguishes from main query WHERE clauses
				$visitor = new VisitorAddRangeReferences($range, 'AGGREGATE_WHERE', $context);
				
				// Traverse the nested condition AST to collect range references
				$aggregate->getConditions()->accept($visitor);
			}
		}
		
		/**
		 * Process expressions from any query clause (SELECT, WHERE, ORDER BY) for range references.
		 * @param array $expressions Array of AST expression nodes to process
		 * @param AstRange $range The range variable being tracked for references
		 * @param string $context The clause context ('SELECT', 'WHERE', 'ORDER_BY')
		 * @return void
		 */
		private function processClauseExpressions(array $expressions, AstRange $range, string $context): void {
			foreach ($expressions as $expression) {
				// Skip null expressions (e.g., queries without WHERE clauses)
				if ($expression === null) {
					continue;
				}
				
				// Create visitor for this clause context
				$visitor = new VisitorAddRangeReferences($range, $context);
				
				// Apply visitor pattern to traverse expression AST and collect range references
				$expression->accept($visitor);
			}
		}
		
		/**
		 * Collect all aggregates in the AST tree
		 * @param AstInterface $ast The root AST node to search for aggregates
		 * @return array An array of aggregate AST nodes found in the tree
		 */
		private function findAggregates(AstInterface $ast): array {
			// Create a visitor that collects nodes of specific aggregate types
			// The array contains all supported aggregate function classes:
			$visitor = new CollectNodes([
				AstSum::class,    // Standard SUM aggregate
				AstSumU::class,   // SUM with UNIQUE modifier (likely SUM DISTINCT)
				AstCount::class,  // Standard COUNT aggregate
				AstCountU::class, // COUNT with UNIQUE modifier (likely COUNT DISTINCT)
				AstAvg::class,    // Standard AVERAGE aggregate
				AstAvgU::class,   // AVERAGE with UNIQUE modifier (likely AVG DISTINCT)
				AstAny::class,    // ANY aggregate (existential quantifier)
				AstMin::class,    // MINIMUM aggregate
				AstMax::class     // MAXIMUM aggregate
			]);
			
			// Traverse the AST starting from the root node
			// The visitor will collect all matching aggregate nodes during traversal
			$ast->accept($visitor);
			
			// Return the collected aggregate nodes
			return $visitor->getCollectedNodes();
		}
		
		// ========== PAGINATION METHODS ==========
		
		/**
		 * Determines if the query requires pagination processing.
		 * @param AstRetrieve $ast
		 * @return bool
		 */
		private function requiresPagination(AstRetrieve $ast): bool {
			return $ast->getWindow() !== null && !$ast->getSortInApplicationLogic();
		}
		
		/**
		 * Processes pagination for the query.
		 * @param AstRetrieve $ast
		 * @param array $parameters
		 * @return void
		 */
		private function processPagination(AstRetrieve $ast, array $parameters): void {
			// Get primary key information for the main table/range being queried
			// This is essential for pagination as we need to identify unique records
			$primaryKeyInfo = $this->entityStore->fetchPrimaryKeyOfMainRange($ast);
			
			// If we can't determine the primary key, we can't safely paginate
			// This might happen with complex queries, views, or entities without proper key definitions
			if ($primaryKeyInfo === null) {
				return;
			}
			
			// Check for query directives that might affect pagination behavior
			$directives = $ast->getDirectives();
			
			// Look for the 'InValuesAreFinal' directive which indicates that any IN conditions
			// in the query are already finalized and don't need additional validation/processing
			// This is an optimization flag that can skip the validation phase of pagination
			$skipValidation = isset($directives['InValuesAreFinal']) && $directives['InValuesAreFinal'] === true;
			
			// Choose the appropriate pagination strategy based on the directive
			if ($skipValidation) {
				// Fast path: Skip the validation step and process pagination directly
				// Used when we know the IN conditions are already properly constructed
				$this->processPaginationSkippingValidation($ast, $parameters, $primaryKeyInfo);
			} else {
				// Standard path: Use the full validation approach which fetches all primary keys first
				// This is the safer, more comprehensive method for most queries
				$this->processPaginationWithValidation($ast, $parameters, $primaryKeyInfo);
			}
		}
		
		/**
		 * Processes pagination by directly manipulating existing IN() values.
		 * @param AstRetrieve $ast
		 * @param array $parameters
		 * @param array $primaryKeyInfo
		 * @return void
		 */
		private function processPaginationSkippingValidation(AstRetrieve $ast, array $parameters, array $primaryKeyInfo): void {
			try {
				// Fetch IN() statement
				$astIdentifier = $this->createPrimaryKeyIdentifier($primaryKeyInfo);
				$visitor = new GetMainEntityInAst($astIdentifier);
				$ast->getConditions()->accept($visitor);
				
				// If no exception, fall back to validation method
				$this->processPaginationWithValidation($ast, $parameters, $primaryKeyInfo);
				
			} catch (GetMainEntityInAstException $exception) {
				$astObject = $exception->getAstObject();
				
				$filteredParams = array_slice(
					$astObject->getParameters(),
					$ast->getWindow() * $ast->getWindowSize(),
					$ast->getWindowSize()
				);
				
				$astObject->setParameters($filteredParams);
			}
		}
		
		/**
		 * Processes pagination by fetching all primary keys first.
		 * @param AstRetrieve $ast
		 * @param array $parameters
		 * @param array $primaryKeyInfo
		 * @return void
		 */
		private function processPaginationWithValidation(AstRetrieve $ast, array $parameters, array $primaryKeyInfo): void {
			// First pass: Execute a lightweight query to fetch only the primary keys
			// This avoids loading full records when we only need to determine pagination boundaries
			$primaryKeys = $this->fetchAllPrimaryKeysForPagination($ast, $parameters, $primaryKeyInfo);
			
			// Early exit if no records match the query conditions
			if (empty($primaryKeys)) {
				return;
			}
			
			// Apply pagination logic to get only the subset of primary keys for the requested page
			// Uses the window (offset) and window size (limit) from the AST
			$filteredKeys = $this->getPageSubset($primaryKeys, $ast->getWindow(), $ast->getWindowSize());
			
			// Handle edge case where pagination parameters result in no valid results
			// (e.g., requesting page 100 when there are only 50 total pages)
			if (empty($filteredKeys)) {
				// Add a condition that will never match (like "1=0") to make the query return empty results
				// This is more efficient than letting the database process the full query
				$this->addImpossibleCondition($ast);
				return;
			}
			
			// Modify the original query to include an IN condition that limits results
			// to only the primary keys we determined should be on this page
			// This ensures the final query returns exactly the records we want, in the right order
			$this->addInConditionForPagination($ast, $primaryKeyInfo, $filteredKeys);
		}
		
		/**
		 * Fetches all primary keys for pagination by temporarily modifying the query.
		 * @param AstRetrieve $ast
		 * @param array $parameters
		 * @param array $primaryKeyInfo
		 * @return array
		 */
		private function fetchAllPrimaryKeysForPagination(AstRetrieve $ast, array $parameters, array $primaryKeyInfo): array {
			// Store original state
			$originalValues = $ast->getValues();
			$originalUnique = $ast->getUnique();
			
			try {
				// Modify query to get only primary keys
				$ast->setUnique(true);
				$astIdentifier = $this->createPrimaryKeyIdentifier($primaryKeyInfo);
				$ast->setValues([new AstAlias("primary", $astIdentifier)]);
				
				// Execute modified query
				$sql = $this->convertToSQL($ast, $parameters);
				return $this->connection->GetCol($sql, $parameters);
				
			} finally {
				// Always restore original state
				$ast->setValues($originalValues);
				$ast->setUnique($originalUnique);
			}
		}

		/**
		 * Gets the subset of primary keys for the current page.
		 * @param array $primaryKeys
		 * @param int $window
		 * @param int $windowSize
		 * @return array
		 */
		private function getPageSubset(array $primaryKeys, int $window, int $windowSize): array {
			return array_slice($primaryKeys, $window * $windowSize, $windowSize);
		}
		
		/**
		 * Convert AstRetrieve node to SQL
		 * @param AstRetrieve $retrieve The AST to convert
		 * @param array $parameters Query parameters (passed by reference)
		 * @return string The generated SQL query
		 */
		private function convertToSQL(AstRetrieve $retrieve, array &$parameters): string {
			$quelToSQL = new QuelToSQL($this->entityStore, $parameters);
			return $quelToSQL->convertToSQL($retrieve);
		}
		
		/**
		 * Factory method to create primary key identifiers.
		 * @param array $primaryKeyInfo
		 * @return AstIdentifier
		 */
		private function createPrimaryKeyIdentifier(array $primaryKeyInfo): AstIdentifier {
			$astIdentifier = new AstIdentifier($primaryKeyInfo['entityName']);
			$astIdentifier->setRange(clone $primaryKeyInfo['range']);
			$astIdentifier->setNext(new AstIdentifier($primaryKeyInfo['primaryKey']));
			return $astIdentifier;
		}
		
		/**
		 * Adds IN condition for pagination filtering.
		 * @param AstRetrieve $ast
		 * @param array $primaryKeyInfo
		 * @param array $filteredKeys
		 * @return void
		 */
		private function addInConditionForPagination(AstRetrieve $ast, array $primaryKeyInfo, array $filteredKeys): void {
			$astIdentifier = $this->createPrimaryKeyIdentifier($primaryKeyInfo);
			$parameters = array_map(fn($item) => new AstNumber($item), $filteredKeys);
			
			// Check if AstIn already exists and replace its parameters
			try {
				$visitor = new GetMainEntityInAst($astIdentifier);
				$ast->getConditions()->accept($visitor);
			} catch (GetMainEntityInAstException $exception) {
				$exception->getAstObject()->setParameters($parameters);
				return;
			}
			
			// Create new AstIn condition
			$astIn = new AstIn($astIdentifier, $parameters);
			
			if ($ast->getConditions() === null) {
				$ast->setConditions($astIn);
			} else {
				$ast->setConditions(new AstBinaryOperator($ast->getConditions(), $astIn, "AND"));
			}
		}
		
		/**
		 * Adds an impossible condition (1 = 0) for empty result sets.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function addImpossibleCondition(AstRetrieve $ast): void {
			$condition = new AstBinaryOperator(new AstNumber(1), new AstNumber(0), "=");
			
			if ($ast->getConditions() === null) {
				$ast->setConditions($condition);
			} else {
				$ast->setConditions(new AstBinaryOperator($ast->getConditions(), $condition, "AND"));
			}
		}
	}