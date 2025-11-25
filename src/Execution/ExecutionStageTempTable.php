<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	
	/**
	 * Execution stage that materializes a subquery into a temporary table.
	 *
	 * This stage executes an inner plan and stores its results in a temporary database table,
	 * allowing the results to be referenced multiple times without re-execution. The temporary
	 * table is then associated with a range that can be used in subsequent query operations.
	 */
	class ExecutionStageTempTable implements ExecutionStageInterface {

		/** @var string The name of the temporary table to create */
		private string $name;
		
		/** @var ExecutionPlan The plan to execute whose results will populate the temp table */
		private ExecutionPlan $innerPlan;
		
		/** @var AstRangeDatabase The range object that will be updated to reference the temp table */
		private AstRangeDatabase $rangeToUpdate;
		
		/**
		 * Constructor
		 * @param string $name The name for the temporary table
		 * @param ExecutionPlan $innerPlan The execution plan to materialize
		 * @param AstRangeDatabase $rangeToUpdate The range to update with the temp table reference
		 */
		public function __construct(string $name, ExecutionPlan $innerPlan, AstRangeDatabase $rangeToUpdate) {
			$this->name = $name;
			$this->innerPlan = $innerPlan;
			$this->rangeToUpdate = $rangeToUpdate;
		}
		
		/**
		 * Get the name of the temporary table.
		 * @return string The temp table name
		 */
		public function getName(): string {
			return $this->name;
		}
		
		/**
		 * Get the execution plan that will be materialized into the temp table.
		 * @return ExecutionPlan The inner plan to execute
		 */
		public function getInnerPlan(): ExecutionPlan {
			return $this->innerPlan;
		}
		
		/**
		 * Get the range that will be updated to reference the temporary table.
		 * After the temp table is created and populated, this range's database reference
		 * will be updated to point to the temporary table instead of its original source.
		 * @return AstRangeDatabase The range to update
		 */
		public function getRangeToUpdate(): AstRangeDatabase {
			return $this->rangeToUpdate;
		}
	}