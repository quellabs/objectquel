<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	
	/**
	 * Represents a pre-execution stage that materialises a mixed-source inner query
	 * (one containing JSON or other external data sources) into a real MySQL temporary
	 * table before the outer database stage runs.
	 *
	 * Lifecycle:
	 *   1. QueryDecomposer creates a TempTableStage for each AstRangeDatabase whose
	 *      embedded query contains external (non-SQL) source ranges.
	 *   2. TempTableExecutor executes the inner AstRetrieve through the full plan
	 *      pipeline (including JSON stages), then creates a temporary table and inserts
	 *      the resulting rows.
	 *   3. TempTableExecutor mutates the AstRangeDatabase: setQuery(null) and
	 *      setTableName('tmp_...'), so that QuelToSQL sees a plain table reference.
	 *   4. The outer ExecutionStage runs and QuelToSQL generates correct SQL.
	 *   5. TempTableExecutor drops the temporary table in a finally block.
	 *
	 * The interface contract for hasResultProcessor / getResultProcessor / getStaticParams
	 * is fulfilled here with trivial implementations because temp-table materialisation
	 * does not participate in the PlanExecutor result-combination flow — it executes
	 * as a side-effect before the outer stage runs, not as a result-producing stage.
	 */
	class TempTableStage implements ExecutionStageInterface {
		
		/**
		 * Unique identifier for this stage within the execution plan
		 * @var string
		 */
		private string $name;
		
		/**
		 * The AstRangeDatabase node whose embedded query must be materialised.
		 * This is the SAME object reference held by the outer ExecutionStage's query
		 * range list, so mutations made by TempTableExecutor are visible to QuelToSQL.
		 * @var AstRangeDatabase
		 */
		private AstRangeDatabase $range;
		
		/**
		 * Static parameters forwarded to the inner query execution
		 * @var array
		 */
		private array $staticParams;
		
		/**
		 * @param string $name Unique stage name
		 * @param AstRangeDatabase $range The range whose inner query must be materialised
		 * @param array $staticParams Parameters passed through to inner query execution
		 */
		public function __construct(string $name, AstRangeDatabase $range, array $staticParams = []) {
			$this->name = $name;
			$this->range = $range;
			$this->staticParams = $staticParams;
		}
		
		/**
		 * Returns the unique name of this stage
		 * @return string
		 */
		public function getName(): string {
			return $this->name;
		}
		
		/**
		 * Returns the AstRangeDatabase node to be materialised.
		 * Used by TempTableExecutor to access getInnerQuery() and to mutate the range
		 * after the temporary table has been created.
		 * @return AstRangeDatabase
		 */
		public function getRange(): AstRangeDatabase {
			return $this->range;
		}
		
		/**
		 * Returns the inner AstRetrieve query that must be executed to produce
		 * rows for insertion into the temporary table.
		 * Guaranteed non-null: QueryDecomposer only creates a TempTableStage when
		 * rangeQueryContainsExternalSource() returned true, which requires getQuery() !== null.
		 * @return AstRetrieve
		 */
		public function getInnerQuery(): AstRetrieve {
			/** @var AstRetrieve $query */
			$query = $this->range->getQuery();
			return $query;
		}
		
		/**
		 * TempTableStage has no result processor — it produces no result rows itself.
		 * @return bool Always false
		 */
		public function hasResultProcessor(): bool {
			return false;
		}
		
		/**
		 * TempTableStage has no result processor.
		 * @return callable|null Always null
		 */
		public function getResultProcessor(): ?callable {
			return null;
		}
		
		/**
		 * Returns the static parameters to forward to inner query execution
		 * @return array
		 */
		public function getStaticParams(): array {
			return $this->staticParams;
		}
	}