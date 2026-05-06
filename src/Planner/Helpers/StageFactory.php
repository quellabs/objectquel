<?php
	
	namespace Quellabs\ObjectQuel\Planner\Helpers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseMaterialized;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseTempTable;
	use Quellabs\ObjectQuel\Planner\ExecutionStage;
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
	 *   - Promoting temp-table ranges from `FROM` to `JOIN` where appropriate
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
			return array_filter($query->getRanges(), function ($range) {
				return
					$range instanceof AstRangeDatabase ||
					$range instanceof AstRangeDatabaseMaterialized ||
					$range instanceof AstRangeDatabaseTempTable;
			});
		}
		
		/**
		 * Returns only the temporary (subquery) ranges
		 * @return array<AstRangeDatabaseTempTable|AstRangeDatabaseMaterialized>
		 */
		public function extractTemporaryRanges(AstRetrieve $query): array {
			return array_filter($query->getRanges(), function ($range) {
				if (
					!$range instanceof AstRangeDatabaseTempTable &&
					!$range instanceof AstRangeDatabaseMaterialized
				) {
					return false;
				}

				return $range->getQuery() !== null;
			});
		}
		
		/**
		 * Returns only the database projections
		 * @return AstAlias[]
		 */
		public function extractDatabaseCompatibleProjections(AstRetrieve $query): array {
			$result = [];
			$databaseRanges = $this->findDatabaseSourceRanges($query);
			
			foreach ($query->getValues() as $value) {
				foreach ($databaseRanges as $range) {
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
			
			foreach ($query->getValues() as $value) {
				if ($this->analyzer->doesConditionInvolveRangeCached($value, $range)) {
					$result[] = $value;
				}
			}
			
			return $result;
		}

		/**
		 * This method creates a version of the original query that only includes
		 * operations that can be handled directly by the database engine,
		 * removing any parts that would require in-memory processing.
		 * @param AstRetrieve $query The original query to be analyzed
		 * @param array<string, mixed> $staticParams
		 * @return ExecutionStage|null The execution stage, or null if there is none
		 */
		public function createDatabaseExecutionStage(AstRetrieve $query, array $staticParams = []): ?ExecutionStage {
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
		 * @param AstRange $range
		 * @param array<string, mixed> $staticParams
		 * @return ExecutionStage A new query containing only database-executable operations
		 */
		public function createRangeExecutionStage(AstRetrieve $query, AstRange $range, array $staticParams): ExecutionStage {
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
			
			// Assert that range of the correct type
			assert($range instanceof AstRangeDatabase || $range instanceof AstRangeJsonSource);
			
			// Return the optimized query that can be fully executed by the database
			return new ExecutionStage(uniqid(), $dbQuery, $range, $staticParams, $joinConditions);
		}
	}