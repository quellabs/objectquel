<?php
	
	namespace Quellabs\ObjectQuel\Execution\Decomposer;
	
	use Quellabs\ObjectQuel\Execution\ExecutionStage;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	
	/**
	 * Builds ExecutionStage objects from query ASTs.
	 *
	 * Responsible for:
	 *   - Classifying ranges by type (database, temporary, JSON)
	 *   - Extracting compatible projections for each range type
	 *   - Promoting temp-table ranges from FROM to JOIN where appropriate
	 *   - Constructing the final ExecutionStage for each data source
	 *
	 * StageFactory operates on cloned query objects — it never modifies the
	 * original AST passed in by the caller.
	 */
	class StageFactory {
		
		/**
		 * @var ConditionAnalyzer Used to check which ranges a condition references
		 */
		private ConditionAnalyzer $analyzer;
		
		/**
		 * @var ConditionFilter Used to extract and filter condition subtrees
		 */
		private ConditionFilter $filter;
		
		/**
		 * @param ConditionAnalyzer $analyzer
		 * @param ConditionFilter $filter
		 */
		public function __construct(ConditionAnalyzer $analyzer, ConditionFilter $filter) {
			$this->analyzer = $analyzer;
			$this->filter = $filter;
		}
		
		/**
		 * Returns database ranges
		 * @param AstRetrieve $query
		 * @return AstRange[]
		 */
		public function findDatabaseSourceRanges(AstRetrieve $query): array {
			return array_filter($query->getRanges(), function($range) {
				return $range instanceof AstRangeDatabase;
			});
		}
		
		/**
		 * Returns only the temporary (subquery) ranges
		 * @return array<AstRangeDatabase>
		 */
		public function extractTemporaryRanges(AstRetrieve $query): array {
			return array_filter($query->getRanges(), function($range) {
				return
					$range instanceof AstRangeDatabase &&
					$range->getQuery() !== null;
			});
		}
		
		/**
		 * Returns only the database projections
		 * @return AstAlias[]
		 */
		public function extractDatabaseCompatibleProjections(AstRetrieve $query): array {
			$result = [];
			$databaseRanges = $this->findDatabaseSourceRanges($query);
			
			foreach($query->getValues() as $value) {
				foreach($databaseRanges as $range) {
					if ($this->analyzer->doesConditionInvolveRangeCached($value, $range)) {
						$result[] = $value;
					}
				}
			}
			
			return $result;
		}
		
		/**
		 * Returns only the projections for the range
		 * @return AstAlias[]
		 */
		public function extractProjectionsForRange(AstRetrieve $query, AstRange $range): array {
			$result = [];
			
			foreach($query->getValues() as $value) {
				if ($this->analyzer->doesConditionInvolveRangeCached($value, $range)) {
					$result[] = $value;
				}
			}
			
			return $result;
		}
		
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
		 * and which table is FROM makes no difference. If no real database table is
		 * present at all, the temp table legitimately becomes the FROM and is left
		 * untouched.
		 *
		 * This operates on the cloned query inside createDatabaseExecutionStage,
		 * never on the original AST.
		 *
		 * @param AstRetrieve $dbQuery The cloned database query to modify in place
		 * @param string[] $tempRangeNames Names of ranges that will become temp tables
		 */
		public function promoteTempTableRanges(AstRetrieve $dbQuery, array $tempRangeNames): void {
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
				$joinCondition = $this->filter->isolateJoinConditionsForRange($range, $dbQuery->getConditions());
				
				if ($joinCondition !== null) {
					$range->setJoinProperty($joinCondition);
				}
				
				// If no join condition exists, the ranges are disconnected. Leave the
				// range as-is — a cross join is correct and FROM order is irrelevant.
			}
		}
		
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
		public function createDatabaseExecutionStage(AstRetrieve $query, array $staticParams = [], array $tempRangeNames = []): ?ExecutionStage {
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
			$dbQuery->setConditions($this->filter->filterDatabaseCompatibleConditions($query->getConditions(), $dbRanges));
			
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
		public function createRangeExecutionStage(AstRetrieve $query, AstRangeDatabase|AstRangeJsonSource $range, array $staticParams): ExecutionStage {
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
			$dbQuery->setConditions($this->filter->isolateFilterConditionsForRange($range, $query->getConditions()));
			
			// Extract join conditions
			$joinConditions = $this->filter->isolateJoinConditionsForRange($range, $query->getConditions());
			
			// Return the optimized query that can be fully executed by the database
			return new ExecutionStage(uniqid(), $dbQuery, $range, $staticParams, $joinConditions);
		}
	}