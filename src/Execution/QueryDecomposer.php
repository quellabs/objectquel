<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIfnull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstUnaryOperation;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearch;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearchScore;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
	/**
	 * QueryDecomposer is responsible for breaking down complex queries into simpler
	 * execution stages that can be managed by an ExecutionPlan.
	 *
	 * Temp-table detection:
	 *   When a subquery range (AstRangeDatabase with getQuery() !== null) contains
	 *   any external data source (JSON, and in the future CSV etc.), it cannot be
	 *   passed to MySQL as an inline subquery. Instead, QueryDecomposer:
	 *     1. Creates a TempTableStage for the range, which TempTableExecutor will
	 *        materialise before the outer query runs.
	 *     2. Creates the outer ExecutionStage as normal — by the time it executes,
	 *        the AstRangeDatabase will have been mutated to reference the temp table.
	 *     3. Registers a dependency edge so the TempTableStage always runs first.
	 *
	 * FROM determination for temp-table ranges:
	 *   QuelToSQL picks the FROM table as the first AstRangeDatabase with no joinProperty.
	 *   If a temp-table range also lacks a joinProperty, it would incorrectly become the
	 *   FROM instead of the real database table. To prevent this, promoteTempTableRanges()
	 *   is called on the cloned database query before it is handed to QuelToSQL: it finds
	 *   any temp-table range without a joinProperty and promotes it to a JOIN by extracting
	 *   a join condition from the WHERE clause. If no join condition exists, the ranges are
	 *   genuinely disconnected (cross join) and the ordering doesn't matter.
	 */
	class QueryDecomposer {
		
		/**
		 * @var array Cache of results for expensive operations
		 */
		private array $cache = [];
		
		
		/**
		 * Decomposes a query into separate execution stages for different data sources.
		 * This function takes a mixed-source query and creates an execution plan with
		 * appropriate stages for database and JSON sources.
		 *
		 * Stage construction order:
		 *   1. For each temporary range (AstRangeDatabase with embedded subquery):
		 *      a. If it contains external sources → add a TempTableStage first, then
		 *         register a dependency so the outer database stage runs after it.
		 *      b. If it is pure SQL → leave as-is (inline subquery handled by QuelToSQL).
		 *   2. Add the main database stage (which references temp tables as plain tables
		 *      by the time it executes, because all TempTableStages will have run first).
		 *   3. Add JSON/other non-database range stages (existing behaviour).
		 *
		 * @param AstRetrieve $query The ObjectQuel query to decompose
		 * @param array $staticParams Optional static parameters for the query
		 * @return ExecutionPlan The execution plan containing all stages
		 * @throws QuelException If the query cannot be properly decomposed
		 */
		public function buildExecutionPlan(AstRetrieve $query, array $staticParams = []): ExecutionPlan {
			$this->clearCache();
			$plan = new ExecutionPlan();
			
			// Detect which temporary ranges need materialisation as real temp tables.
			// We must process these BEFORE creating the main database stage so that
			// dependency edges can be registered correctly.
			$tempRanges = $this->extractTemporaryRanges($query);
			
			// Sort temp ranges by their inter-dependencies (a temp range's inner query
			// might reference another temp range; it must come after its dependency).
			if (!empty($tempRanges)) {
				$tempRanges = $this->sortByDependency($tempRanges);
			}
			
			// Create TempTableStages for external-source ranges.
			// Track which temp range names have a corresponding TempTableStage so we
			// can register dependencies on the outer database stage.
			$tempTableStageNames = []; // rangeName → TempTableStage name
			
			foreach ($tempRanges as $tempRange) {
				if (!$this->rangeQueryContainsExternalSource($tempRange)) {
					// Pure SQL subquery — QuelToSQL handles it inline, no stage needed
					continue;
				}
				
				$tempStageName = uniqid('tmp_stage_');
				$tempStage = new TempTableStage($tempStageName, $tempRange, $staticParams);
				$plan->addStage($tempStage);
				$tempTableStageNames[$tempRange->getName()] = $tempStageName;
				
				// If this TempTableStage itself depends on another TempTableStage
				// (because its inner query references another external-source range),
				// register that dependency so ordering is preserved.
				foreach ($tempRanges as $otherTempRange) {
					if ($otherTempRange->getName() === $tempRange->getName()) {
						continue;
					}
					
					if (isset($tempTableStageNames[$otherTempRange->getName()])) {
						$innerQuery = $tempRange->getQuery();
						
						if ($innerQuery !== null && $this->innerQueryReferencesRange($innerQuery, $otherTempRange->getName())) {
							$plan->addDependency($tempStageName, $tempTableStageNames[$otherTempRange->getName()]);
						}
					}
				}
			}
			
			// Build main database query stage
			$databaseStage = $this->createDatabaseExecutionStage($query, $staticParams, array_keys($tempTableStageNames));
			
			if ($databaseStage) {
				$plan->addStage($databaseStage);
				
				// The main database stage depends on every TempTableStage, because
				// those must be fully materialised before the outer SQL can execute.
				foreach ($tempTableStageNames as $rangeName => $tempStageName) {
					$plan->addDependency($databaseStage->getName(), $tempStageName);
				}
			}
			
			// JSON stages
			foreach ($query->getOtherRanges() as $otherRange) {
				$plan->addStage($this->createRangeExecutionStage($query, $otherRange, $staticParams));
			}
			
			return $plan;
		}
		
		// =========================================================================
		// External-source detection
		// =========================================================================
		
		/**
		 * Recursively determines whether a temporary range's embedded query contains
		 * any external (non-database) data source, such as AstRangeJsonSource.
		 *
		 * This is the primary gate for the temp-table materialisation path. A range
		 * that returns false here is a pure SQL subquery and is left to QuelToSQL to
		 * handle as an inline derived table.
		 *
		 * Extensibility: add new external source types (e.g. AstRangeCsvSource) to
		 * the instanceof check inside the loop.
		 *
		 * @param AstRangeDatabase $range The range whose embedded query is to be checked
		 * @return bool True if any range in the inner query (recursively) is external
		 */
		protected function rangeQueryContainsExternalSource(AstRangeDatabase $range): bool {
			$innerQuery = $range->getQuery();
			
			if ($innerQuery === null) {
				return false;
			}
			
			foreach ($innerQuery->getRanges() as $innerRange) {
				// Direct external source — JSON today, others in the future
				if ($innerRange instanceof AstRangeJsonSource) {
					return true;
				}
				
				// Nested subquery: recurse to check its ranges as well
				if ($innerRange instanceof AstRangeDatabase && $innerRange->getQuery() !== null) {
					if ($this->rangeQueryContainsExternalSource($innerRange)) {
						return true;
					}
				}
			}
			
			return false;
		}
		
		/**
		 * Checks whether an inner query's ranges include a reference to a specific
		 * range name. Used to detect inter-TempTableStage dependencies when one
		 * external-source subquery depends on another.
		 * @param AstRetrieve $innerQuery
		 * @param string $rangeName The range name to look for
		 * @return bool
		 */
		protected function innerQueryReferencesRange(AstRetrieve $innerQuery, string $rangeName): bool {
			foreach ($innerQuery->getRanges() as $range) {
				if ($range->getName() === $rangeName) {
					return true;
				}
			}
			
			return false;
		}
		
		// =========================================================================
		// Temp-table FROM promotion
		// =========================================================================
		
		/**
		 * Ensures that no temp-table range incorrectly ends up as the FROM table.
		 *
		 * QuelToSQL::getFrom() picks the FROM by finding the first AstRangeDatabase
		 * with no joinProperty. If a temp-table range also lacks a joinProperty, it
		 * would become the FROM instead of the real database table, which is wrong.
		 *
		 * This method promotes any such range to a JOIN by extracting a join condition
		 * from the WHERE clause. If no join condition exists in the WHERE (the ranges
		 * are genuinely disconnected), no promotion is possible and the ordering is
		 * left as-is — a cross join is the only semantically valid option in that case,
		 * and which table is FROM makes no difference.
		 *
		 * This operates on the cloned query inside createDatabaseExecutionStage,
		 * never on the original AST.
		 *
		 * @param AstRetrieve $dbQuery The cloned database query to modify in place
		 * @param string[] $tempRangeNames Names of ranges that will become temp tables
		 */
		protected function promoteTempTableRanges(AstRetrieve $dbQuery, array $tempRangeNames): void {
			if (empty($tempRangeNames)) {
				return;
			}
			
			foreach ($dbQuery->getRanges() as $range) {
				if (!($range instanceof AstRangeDatabase)) {
					continue;
				}
				
				// Only act on temp-table ranges that currently have no joinProperty.
				// Ranges that already have a joinProperty are already JOINs — correct.
				if (!in_array($range->getName(), $tempRangeNames, true)) {
					continue;
				}
				
				if ($range->getJoinProperty() !== null) {
					continue;
				}
				
				// Try to extract a join condition from the WHERE clause that connects
				// this range to another range. If found, promote the range to a JOIN.
				$joinCondition = $this->isolateJoinConditionsForRange($range, $dbQuery->getConditions());
				
				if ($joinCondition !== null) {
					$range->setJoinProperty($joinCondition);
				}
				
				// If no join condition exists, the ranges are disconnected. Leave the
				// range as-is — a cross join is correct and FROM order is irrelevant.
			}
		}
		
		// =========================================================================
		// Range classification helpers
		// =========================================================================
		
		/**
		 * Returns database ranges
		 * @param AstRetrieve $query
		 * @return AstRange[]
		 */
		protected function findDatabaseSourceRanges(AstRetrieve $query): array {
			return array_filter($query->getRanges(), function ($range) {
				return $range instanceof AstRangeDatabase;
			});
		}
		
		/**
		 * Returns only the database projections
		 * @return AstAlias[]
		 */
		protected function extractDatabaseCompatibleProjections(AstRetrieve $query): array {
			$result = [];
			$databaseRanges = $this->findDatabaseSourceRanges($query);
			
			foreach ($query->getValues() as $value) {
				foreach ($databaseRanges as $range) {
					if ($this->doesConditionInvolveRangeCached($value, $range)) {
						$result[] = $value;
					}
				}
			}
			
			return $result;
		}
		
		/**
		 * Returns only the database projections
		 * @return array<AstRangeDatabase>
		 */
		protected function extractTemporaryRanges(AstRetrieve $query): array {
			return array_filter($query->getRanges(), function ($range) {
				return
					$range instanceof AstRangeDatabase &&
					$range->getQuery() !== null;
			});
		}
		
		/**
		 * Returns only the projections for the range
		 * @return AstAlias[]
		 */
		protected function extractProjectionsForRange(AstRetrieve $query, AstRange $range): array {
			$result = [];
			
			foreach ($query->getValues() as $value) {
				if ($this->doesConditionInvolveRangeCached($value, $range)) {
					$result[] = $value;
				}
			}
			
			return $result;
		}
		
		/**
		 * Sorts temporary ranges by their dependencies.
		 * Since temp ranges can't reference other temp ranges in their range declarations,
		 * we only need to check if inner queries reference other temp ranges in WHERE/retrieve.
		 * @param AstRangeDatabase[] $temporaryRanges
		 * @return AstRangeDatabase[] Sorted array where dependencies come before ranges that use them
		 * @throws QuelException If circular dependency detected
		 */
		protected function sortByDependency(array $temporaryRanges): array {
			// Build lookup: name -> range
			$rangesByName = [];
			
			foreach ($temporaryRanges as $range) {
				$rangesByName[$range->getName()] = $range;
			}
			
			// Build dependency graph by checking WHERE/retrieve for temp range references
			$dependencies = [];
			
			foreach ($temporaryRanges as $range) {
				$rangeName = $range->getName();
				$deps = $this->findTempRangeDependenciesInConditions(
					$range->getQuery(),
					array_keys($rangesByName)
				);
				$dependencies[$rangeName] = $deps;
			}
			
			// Topological sort (rest is same as before)
			$inDegree = [];
			
			foreach ($rangesByName as $name => $range) {
				$inDegree[$name] = count($dependencies[$name]);
			}
			
			$queue = [];
			
			foreach ($inDegree as $name => $degree) {
				if ($degree === 0) {
					$queue[] = $name;
				}
			}
			
			$sorted = [];
			
			while (!empty($queue)) {
				$current = array_shift($queue);
				$sorted[] = $rangesByName[$current];
				
				foreach ($dependencies as $rangeName => $deps) {
					if (in_array($current, $deps)) {
						$inDegree[$rangeName]--;
						
						if ($inDegree[$rangeName] === 0) {
							$queue[] = $rangeName;
						}
					}
				}
			}
			
			if (count($sorted) !== count($temporaryRanges)) {
				throw new QuelException('Circular dependency detected in temporary ranges');
			}
			
			return $sorted;
		}
		
		/**
		 * Finds temp range names referenced in WHERE conditions and retrieve expressions
		 * @param AstRetrieve $query
		 * @param array $tempRangeNames List of temp range names to check for
		 * @return array Temp range names this query depends on
		 */
		protected function findTempRangeDependenciesInConditions(AstRetrieve $query, array $tempRangeNames): array {
			$dependencies = [];
			
			// Check WHERE conditions
			if ($query->getConditions() !== null) {
				$deps = $this->extractRangeNamesFromAst($query->getConditions(), $tempRangeNames);
				$dependencies = array_merge($dependencies, $deps);
			}
			
			// Check retrieve expressions
			foreach ($query->getValues() as $value) {
				$deps = $this->extractRangeNamesFromAst($value, $tempRangeNames);
				$dependencies = array_merge($dependencies, $deps);
			}
			
			return array_unique($dependencies);
		}
		
		/**
		 * Recursively extracts temp range names from an AST node
		 * @param AstInterface $node
		 * @param array $tempRangeNames
		 * @return array
		 */
		protected function extractRangeNamesFromAst(AstInterface $node, array $tempRangeNames): array {
			$found = [];
			
			if ($node instanceof AstIdentifier) {
				$range = $node->getRange();
				
				if ($range !== null && in_array($range->getName(), $tempRangeNames)) {
					$found[] = $range->getName();
				}
			}
			
			// Recursively check child nodes
			if ($node instanceof AstBinaryOperator ||
				$node instanceof AstExpression ||
				$node instanceof AstTerm ||
				$node instanceof AstFactor) {
				$found = array_merge(
					$found,
					$this->extractRangeNamesFromAst($node->getLeft(), $tempRangeNames),
					$this->extractRangeNamesFromAst($node->getRight(), $tempRangeNames)
				);
			}
			
			if ($node instanceof AstUnaryOperation || $node instanceof AstAlias) {
				$found = array_merge(
					$found,
					$this->extractRangeNamesFromAst($node->getExpression(), $tempRangeNames)
				);
			}
			
			// Add other AST node types as needed
			return $found;
		}
		
		// =========================================================================
		// Stage construction
		// =========================================================================
		
		/**
		 * This method creates a version of the original query that only includes
		 * operations that can be handled directly by the database engine,
		 * removing any parts that would require in-memory processing.
		 *
		 * When temp-table ranges are present, promoteTempTableRanges() is called on
		 * the cloned query to ensure that no temp-table range incorrectly ends up as
		 * the FROM table. See promoteTempTableRanges() for full details.
		 *
		 * @param AstRetrieve $query The original query to be analyzed
		 * @param array $staticParams
		 * @param string[] $tempRangeNames Names of ranges that will become temp tables
		 * @return ExecutionStage|null The execution stage, or null if there is none
		 */
		protected function createDatabaseExecutionStage(AstRetrieve $query, array $staticParams = [], array $tempRangeNames = []): ?ExecutionStage {
			// Clone the query to avoid modifying the original
			// This ensures we preserve the complete query for potential in-memory operations later
			$dbQuery = clone $query;
			
			// Get all database ranges (tables/views that exist in the database)
			// These are data sources that SQL can directly access
			$dbRanges = $this->findDatabaseSourceRanges($query);
			
			// Return null when there are no database ranges
			if (empty($dbRanges)) {
				return null;
			}
			
			// Remove any non-database ranges (e.g., in-memory collections, JSON data)
			// The resulting query will only reference actual database tables/views
			$dbQuery->setRanges($dbRanges);
			
			// Ensure temp-table ranges that lack a joinProperty are promoted to JOINs,
			// so that a real database table becomes the FROM instead.
			$this->promoteTempTableRanges($dbQuery, $tempRangeNames);
			
			// Get the database-compatible projections (columns/expressions to select)
			$dbProjections = $this->extractDatabaseCompatibleProjections($query);
			
			// Remove any non-database projections
			// This removes any projections that depend on in-memory operations
			$dbQuery->setValues($dbProjections);
			
			// Filter the conditions to include only those relevant to database ranges
			// This removes conditions that can't be executed by the database engine
			// and preserves the structure of AND/OR operations where possible
			$dbQuery->setConditions($this->filterDatabaseCompatibleConditions($query->getConditions(), $dbRanges));
			
			// Return the optimized query that can be fully executed by the database
			return new ExecutionStage(uniqid(), $dbQuery, null, $staticParams);
		}
		
		/**
		 * This method creates a version of the original query that only includes
		 * operations that can be handled directly by the database engine,
		 * removing any parts that would require in-memory processing.
		 * @param AstRetrieve $query The original query to be analyzed
		 * @param AstRangeDatabase|AstRangeJsonSource $range
		 * @param array $staticParams
		 * @return ExecutionStage A new query containing only database-executable operations
		 */
		protected function createRangeExecutionStage(AstRetrieve $query, AstRangeDatabase|AstRangeJsonSource $range, array $staticParams): ExecutionStage {
			// Clone the query to avoid modifying the original
			// This ensures we preserve the complete query for potential in-memory operations later
			$dbQuery = clone $query;
			
			// Remove any non-database ranges (e.g., in-memory collections, JSON data)
			// The resulting query will only reference actual database tables/views
			$dbQuery->setRanges([$range]);
			
			// Get the database-compatible projections (columns/expressions to select)
			$dbProjections = $this->extractProjectionsForRange($query, $range);
			
			// Remove any non-database projections
			// This removes any projections that depend on in-memory operations
			$dbQuery->setValues($dbProjections);
			
			// Filter the conditions to include only those relevant to database ranges
			// This removes conditions that can't be executed by the database engine
			// and preserves the structure of AND/OR operations where possible
			$dbQuery->setConditions($this->isolateFilterConditionsForRange($range, $query->getConditions()));
			
			// Extract join conditions
			$joinConditions = $this->isolateJoinConditionsForRange($range, $query->getConditions());
			
			// Return the optimized query that can be fully executed by the database
			return new ExecutionStage(uniqid(), $dbQuery, $range, $staticParams, $joinConditions);
		}
		
		// =========================================================================
		// Condition filtering
		// =========================================================================
		
		/**
		 * Extracts conditions that can be executed directly by the database engine.
		 *
		 * This function filters a condition tree to include only expressions that can be
		 * evaluated by the database (based on the provided database ranges), removing any
		 * parts that would require in-memory processing (like JSON operations).
		 * @param AstInterface|null $condition The condition AST to filter
		 * @param array $dbRanges Array of ranges that can be handled by the database
		 * @return AstInterface|null The filtered condition AST, or null if nothing can be handled by DB
		 */
		protected function filterDatabaseCompatibleConditions(?AstInterface $condition, array $dbRanges): ?AstInterface {
			// Base case: if no condition provided, return null
			if ($condition === null) {
				return null;
			}
			
			// Handle unary operations (NOT, IS NULL, etc.)
			if ($condition instanceof AstUnaryOperation) {
				// Recursively process the inner expression
				$innerCondition = $this->filterDatabaseCompatibleConditions($condition->getExpression(), $dbRanges);
				
				// If inner expression can be handled by DB, create a new unary operation with it
				if ($innerCondition !== null) {
					return new AstUnaryOperation($innerCondition, $condition->getOperator());
				}
				
				// If inner expression can't be handled by DB, return null
				return null;
			}
			
			// Handle comparison operations (e.g., =, >, <, LIKE, etc.)
			if ($condition instanceof AstExpression) {
				// Check if either side of the expression involves database fields
				$leftInvolvesDb = $this->hasReferenceToAnyRange($condition->getLeft(), $dbRanges);
				$rightInvolvesDb = $this->hasReferenceToAnyRange($condition->getRight(), $dbRanges);
				
				// Case 1: Keep expressions where both sides involve database ranges
				// (e.g., table1.column = table2.column)
				if ($leftInvolvesDb && $rightInvolvesDb) {
					return clone $condition;
				}
				
				// Case 2: Keep expressions where left side is a DB field and right side is a literal
				// (e.g., table.column = 'value')
				if ($leftInvolvesDb && !$this->containsAnyRangeReference($condition->getRight())) {
					return clone $condition;
				}
				
				// Case 3: Keep expressions where right side is a DB field and left side is a literal
				// (e.g., 'value' = table.column)
				if ($rightInvolvesDb && !$this->containsAnyRangeReference($condition->getLeft())) {
					return clone $condition;
				}
				
				// If expression involves JSON ranges or other non-DB operations, exclude it
				return null;
			}
			
			// Handle full-text search conditions — they belong to the database
			if ($condition instanceof AstSearch) {
				if ($this->hasReferenceToAnyRange($condition, $dbRanges)) {
					return clone $condition;
				}
				
				return null;
			}
			
			// Handle binary operators (AND, OR)
			if ($condition instanceof AstBinaryOperator) {
				// Recursively process both sides of the operator
				$leftCondition = $this->filterDatabaseCompatibleConditions($condition->getLeft(), $dbRanges);
				$rightCondition = $this->filterDatabaseCompatibleConditions($condition->getRight(), $dbRanges);
				
				// Case 1: If both sides have valid database conditions
				// (e.g., (table1.col = 5) AND (table2.col = 'text'))
				if ($leftCondition !== null && $rightCondition !== null) {
					$newNode = clone $condition;
					$newNode->setLeft($leftCondition);
					$newNode->setRight($rightCondition);
					return $newNode;
				}
				
				// Case 2: If left or right side has valid database conditions
				return $leftCondition !== null ? $leftCondition : $rightCondition;
			}
			
			// For literals or other standalone expressions that don't involve any ranges.
			// These can be safely pushed to the database.
			if (!$this->containsAnyRangeReference($condition)) {
				return clone $condition;
			}
			
			// Default case: condition not suitable for database execution
			return null;
		}
		
		/**
		 * Base helper method to extract conditions based on a predicate function
		 * @param AstRange $range The range to extract conditions for
		 * @param AstInterface|null $whereCondition The complete WHERE condition AST
		 * @param callable $predicate Function that determines if a condition should be included
		 * @return AstInterface|null The filtered conditions
		 */
		protected function filterConditionsByCustomCriteria(AstRange $range, ?AstInterface $whereCondition, callable $predicate): ?AstInterface {
			// Base case: no condition
			if ($whereCondition === null) {
				return null;
			}
			
			// For comparison operations
			if ($whereCondition instanceof AstExpression) {
				// Use the predicate to determine if we should include this expression
				if ($predicate($whereCondition, $range)) {
					return clone $whereCondition;
				}
				
				return null;
			}
			
			// For binary operators (AND, OR)
			if ($whereCondition instanceof AstBinaryOperator) {
				$leftConditions = $this->filterConditionsByCustomCriteria($range, $whereCondition->getLeft(), $predicate);
				$rightConditions = $this->filterConditionsByCustomCriteria($range, $whereCondition->getRight(), $predicate);
				
				// If both sides have conditions
				if ($leftConditions !== null && $rightConditions !== null) {
					$newNode = clone $whereCondition;
					$newNode->setLeft($leftConditions);
					$newNode->setRight($rightConditions);
					return $newNode;
				}
				
				// If only one side has conditions
				if ($leftConditions !== null) {
					return $leftConditions;
				} elseif ($rightConditions !== null) {
					return $rightConditions;
				}
			}
			
			return null;
		}
		
		/**
		 * Extracts just the filtering conditions for a specific range (not join conditions)
		 * @param AstRange $range The range to extract filter conditions for
		 * @param AstInterface|null $whereCondition The complete WHERE condition AST
		 * @return AstInterface|null The filter conditions for this range
		 */
		protected function isolateFilterConditionsForRange(AstRange $range, ?AstInterface $whereCondition): ?AstInterface {
			return $this->filterConditionsByCustomCriteria(
				$range,
				$whereCondition,
				function (AstExpression $expr, AstRange $range) {
					$leftInvolvesRange = $this->doesConditionInvolveRangeCached($expr->getLeft(), $range);
					$rightInvolvesRange = $this->doesConditionInvolveRangeCached($expr->getRight(), $range);
					
					// If only one side involves our range and the other doesn't involve any range,
					// it's a filter condition (e.g., x.value > 100)
					return
						($leftInvolvesRange && !$this->containsAnyRangeReference($expr->getRight())) ||
						($rightInvolvesRange && !$this->containsAnyRangeReference($expr->getLeft()));
				}
			);
		}
		
		/**
		 * Extracts the join conditions involving a specific range with any other range
		 * @param AstRange $range The range to extract join conditions for
		 * @param AstInterface|null $whereCondition The complete WHERE condition AST
		 * @return AstInterface|null The join conditions involving this range
		 */
		protected function isolateJoinConditionsForRange(AstRange $range, ?AstInterface $whereCondition): ?AstInterface {
			return $this->filterConditionsByCustomCriteria(
				$range,
				$whereCondition,
				function (AstExpression $expr, AstRange $range) {
					$leftInvolvesRange = $this->doesConditionInvolveRangeCached($expr->getLeft(), $range);
					$rightInvolvesRange = $this->doesConditionInvolveRangeCached($expr->getRight(), $range);
					
					// If one side involves our range and the other side involves a different range,
					// then it's a join condition
					return
						($leftInvolvesRange && $this->containsAnyRangeReference($expr->getRight()) && !$rightInvolvesRange) ||
						($rightInvolvesRange && $this->containsAnyRangeReference($expr->getLeft()) && !$leftInvolvesRange);
				}
			);
		}
		
		/**
		 * Determines if an AST node involves any data range (database table or other data source).
		 * This recursive method checks whether any part of the given condition references
		 * a data range, which helps identify expressions that need database or in-memory execution.
		 * @param AstInterface $condition The AST node to check
		 * @return bool True if the condition involves any data range, false otherwise
		 */
		protected function containsAnyRangeReference(AstInterface $condition): bool {
			// For identifiers (column names), check if they have an associated range
			if ($condition instanceof AstIdentifier) {
				// An identifier with a range represents a field from a table or other data source
				return $condition->getRange() !== null;
			}
			
			// For unary operations (NOT, IS NULL, etc.), check the inner expression
			if ($condition instanceof AstUnaryOperation) {
				// Recursively check if the inner expression involves any range
				return $this->containsAnyRangeReference($condition->getExpression());
			}
			
			// For binary nodes with left and right children, check both sides
			if (
				$condition instanceof AstExpression ||   // Comparison expressions (=, <, >, etc.)
				$condition instanceof AstBinaryOperator || // Logical operators (AND, OR)
				$condition instanceof AstTerm ||         // Addition, subtraction
				$condition instanceof AstFactor          // Multiplication, division
			) {
				// Return true if either the left or right side involves any range
				return
					$this->containsAnyRangeReference($condition->getLeft()) ||
					$this->containsAnyRangeReference($condition->getRight());
			}
			
			// Full-text search nodes contain identifiers that may reference ranges
			if ($condition instanceof AstSearch || $condition instanceof AstSearchScore) {
				foreach ($condition->getIdentifiers() as $identifier) {
					if ($this->containsAnyRangeReference($identifier)) {
						return true;
					}
				}
				
				return false;
			}
			
			// Literals (numbers, strings) and other node types don't involve ranges
			return false;
		}
		
		/**
		 * Checks if a condition node involves a specific range.
		 * @param AstInterface $condition The condition AST node
		 * @param AstRange $range The range to check for
		 * @return bool True if the condition involves the range
		 */
		protected function hasReferenceToRange(AstInterface $condition, AstRange $range): bool {
			// For property access, check if the base entity matches our range
			if ($condition instanceof AstIdentifier) {
				return $condition->getRange()->getName() === $range->getName();
			}
			
			// For aliases and AstUnaryOperations, check the matching identifier
			if (
				$condition instanceof AstAlias ||
				$condition instanceof AstUnaryOperation ||
				$condition instanceof AstIfnull
			) {
				return $this->hasReferenceToRange($condition->getExpression(), $range);
			}
			
			// For aggregates, check the matching identifier
			if (
				$condition instanceof AstCount ||
				$condition instanceof AstCountU ||
				$condition instanceof AstAvg ||
				$condition instanceof AstAvgU ||
				$condition instanceof AstMax ||
				$condition instanceof AstMin ||
				$condition instanceof AstSum ||
				$condition instanceof AstSumU ||
				$condition instanceof AstAny
			) {
				return $this->hasReferenceToRange($condition->getIdentifier(), $range);
			}
			
			// Full-text search nodes — check if any identifier belongs to this range
			if ($condition instanceof AstSearch || $condition instanceof AstSearchScore) {
				foreach ($condition->getIdentifiers() as $identifier) {
					if ($this->hasReferenceToRange($identifier, $range)) {
						return true;
					}
				}
				
				return false;
			}
			
			// For comparison operations, check each side
			if (
				$condition instanceof AstExpression ||
				$condition instanceof AstBinaryOperator ||
				$condition instanceof AstTerm ||
				$condition instanceof AstFactor
			) {
				$leftInvolves = $this->hasReferenceToRange($condition->getLeft(), $range);
				$rightInvolves = $this->hasReferenceToRange($condition->getRight(), $range);
				return $leftInvolves || $rightInvolves;
			}
			
			return false;
		}
		
		/**
		 * Checks if a condition involves any of the specified ranges
		 * @param AstInterface $condition The condition to check
		 * @param array $ranges Array of AstRange objects
		 * @return bool True if the condition involves any of the ranges
		 */
		protected function hasReferenceToAnyRange(AstInterface $condition, array $ranges): bool {
			foreach ($ranges as $range) {
				if ($this->doesConditionInvolveRangeCached($condition, $range)) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Cached version of doesConditionInvolveRange to avoid recalculating
		 * for the same condition and range pairs.
		 * @param AstInterface $condition The condition AST node
		 * @param AstRange $range The range to check for
		 * @return bool True if the condition involves the range
		 */
		protected function doesConditionInvolveRangeCached(AstInterface $condition, AstRange $range): bool {
			// Generate a cache key based on object identities
			$cacheKey = 'involve_' . spl_object_hash($condition) . '_' . spl_object_hash($range);
			
			// Return cached result if available
			if (isset($this->cache[$cacheKey])) {
				return $this->cache[$cacheKey];
			}
			
			// Calculate and cache the result
			$result = $this->hasReferenceToRange($condition, $range);
			$this->cache[$cacheKey] = $result;
			return $result;
		}
		
		/**
		 * Clears the internal cache.
		 * Should be called after completing a decomposition to prevent memory leaks.
		 */
		protected function clearCache(): void {
			$this->cache = [];
		}
	}