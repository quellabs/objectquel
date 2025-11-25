<?php
	
	namespace Quellabs\ObjectQuel\Execution\Executors;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Execution\ExecutionPlan;
	use Quellabs\ObjectQuel\Execution\ExecutionStage;
	use Quellabs\ObjectQuel\Execution\ExecutionStageTempTable;
	use Quellabs\ObjectQuel\Execution\PlanExecutor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
	/**
	 * Handles execution of temporary table stages
	 */
	class TempTableExecutor {
		private PlanExecutor $planExecutor;
		private DatabaseAdapter $connection;
		
		public function __construct(PlanExecutor $planExecutor, DatabaseAdapter $connection) {
			$this->planExecutor = $planExecutor;
			$this->connection = $connection;
		}
		
		/**
		 * Execute a temp table stage by recursively executing its inner plan
		 * @param ExecutionStageTempTable $stage
		 * @param array $initialParams
		 * @return array The data that was inserted into the temp table
		 * @throws QuelException
		 */
		public function execute(ExecutionStageTempTable $stage, array $initialParams): array {
			// Get the inner plan
			$innerPlan = $stage->getInnerPlan();
			
			// Recursively execute the inner plan
			$innerResults = $this->planExecutor->execute($innerPlan);
			
			// Get the main stage results from inner plan
			$mainStageName = $innerPlan->getMainStageName();
			$data = $innerResults[$mainStageName] ?? [];
			
			// Create temp table based on retrieve() structure
			$tempTableName = $stage->getRangeToUpdate()->getTableName();
			$this->createTempTableFromAst($tempTableName, $innerPlan);
			
			// Insert data into temp table
			if (!empty($data)) {
				$this->insertIntoTempTable($tempTableName, $data);
			}
			
			return $data;
		}
		
		/**
		 * Create a temporary table based on the retrieve clause structure
		 * @param string $tableName
		 * @param ExecutionPlan $plan
		 * @return void
		 * @throws QuelException
		 */
		private function createTempTableFromAst(string $tableName, ExecutionPlan $plan): void {
			// Get the main query from the plan
			$stages = $plan->getStagesInOrder();
			if (empty($stages)) {
				throw new QuelException("Cannot create temp table from empty plan");
			}
			
			// Find the main database stage to get the retrieve structure
			$mainStage = null;
			foreach ($stages as $stage) {
				if ($stage instanceof ExecutionStage && $stage->getRange() === null) {
					$mainStage = $stage;
					break;
				}
			}
			
			if ($mainStage === null) {
				throw new QuelException("Cannot find main stage in plan");
			}
			
			$query = $mainStage->getQuery();
			$retrieveValues = $query->getValues();
			
			if (empty($retrieveValues)) {
				throw new QuelException("Cannot create temp table without retrieve values");
			}
			
			// Build column definitions from retrieve values
			$columns = [];
			foreach ($retrieveValues as $value) {
				if ($value instanceof AstAlias) {
					$columnName = $value->getName() ?? 'col_' . count($columns);
					$type = $this->inferTypeFromAst($value->getExpression());
					$columns[] = "`{$columnName}` {$type}";
				}
			}
			
			$columnDefs = implode(', ', $columns);
			$sql = "CREATE TEMPORARY TABLE `{$tableName}` ({$columnDefs})";
			
			$this->connection->execute($sql, []);
		}
		
		/**
		 * Infer SQL column type from AST expression
		 * @param AstInterface $ast
		 * @return string
		 */
		private function inferTypeFromAst(AstInterface $ast): string {
			// Check for aggregate functions
			if ($ast instanceof AstSum || $ast instanceof AstSumU ||
				$ast instanceof AstAvg || $ast instanceof AstAvgU) {
				return 'DOUBLE';
			}
			
			if ($ast instanceof AstCount || $ast instanceof AstCountU) {
				return 'INTEGER';
			}
			
			if ($ast instanceof AstMax || $ast instanceof AstMin) {
				return 'DOUBLE';
			}
			
			// Default to VARCHAR for regular fields
			return 'VARCHAR(255)';
		}
		
		/**
		 * Insert data into a temporary table
		 * @param string $tableName
		 * @param array $data
		 * @return void
		 */
		private function insertIntoTempTable(string $tableName, array $data): void {
			if (empty($data)) {
				return;
			}
			
			// Get column names from first row
			$firstRow = reset($data);
			$columns = array_keys($firstRow);
			$columnList = '`' . implode('`, `', $columns) . '`';
			
			// Build placeholders
			$placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
			
			// Insert all rows
			foreach ($data as $row) {
				$sql = "INSERT INTO `{$tableName}` ({$columnList}) VALUES {$placeholders}";
				$values = array_values($row);
				$this->connection->execute($sql, $values);
			}
		}
	}