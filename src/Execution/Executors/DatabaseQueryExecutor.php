<?php
	
	namespace Quellabs\ObjectQuel\Execution\Executors;
	
	use Cake\Database\StatementInterface;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Execution\ExecutionStage;
	use Quellabs\ObjectQuel\Execution\ExecutionStageTempTable;
	use Quellabs\ObjectQuel\Execution\PlanExecutor;
	use Quellabs\ObjectQuel\Execution\QueryOptimizer;
	use Quellabs\ObjectQuel\Execution\QueryTransformer;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL;
	
	/**
	 * Handles database-specific query execution including SQL conversion and temp tables
	 */
	class DatabaseQueryExecutor {
		private EntityManager $entityManager;
		private DatabaseAdapter $connection;
		private QueryTransformer $queryTransformer;
		private QueryOptimizer $queryOptimizer;
		
		public function __construct(EntityManager $entityManager) {
			$this->entityManager = $entityManager;
			$this->connection = $entityManager->getConnection();
			$this->queryTransformer = new QueryTransformer($this->entityManager);
			$this->queryOptimizer = new QueryOptimizer($this->entityManager);
		}
		
		/**
		 * Execute a database query stage
		 * @param ExecutionStage $stage
		 * @param array $initialParams
		 * @return array
		 * @throws QuelException
		 */
		public function execute(ExecutionStage $stage, array $initialParams = []): array {
			// Transform and optimize the query
			$this->queryOptimizer->optimize($stage->getQuery());
			$this->queryTransformer->transform($stage->getQuery(), $initialParams);
			
			// Convert the query to SQL
			$sql = $this->convertToSQL($stage->getQuery(), $initialParams);
			
			// Execute the SQL query
			$rs = $this->connection->execute($sql, $initialParams);
			
			// If the query is incorrect, throw an exception
			if (!$rs) {
				throw new QuelException($this->connection->getLastErrorMessage());
			}
			
			// Retrieve all data
			$result = [];
			while ($row = $rs->fetch(StatementInterface::FETCH_TYPE_ASSOC)) {
				$result[] = $row;
			}
			
			return $result;
		}
		
		/**
		 * Execute a temp table stage by recursively executing its inner plan
		 * @param ExecutionStageTempTable $stage
		 * @param PlanExecutor $planExecutor
		 * @return array The data that was inserted into the temp table
		 * @throws QuelException
		 */
		public function executeTempTableStage(ExecutionStageTempTable $stage, PlanExecutor $planExecutor): array {
			// Recursively execute the inner plan
			$innerResults = $planExecutor->execute($stage->getInnerPlan());
			
			// Get the main stage results from inner plan
			$mainStageName = $stage->getInnerPlan()->getMainStageName();
			$data = $innerResults[$mainStageName] ?? [];
			
			// Create temp table
			$tempTableName = $stage->getRangeToUpdate()->getTableName();
			$this->createTempTable($tempTableName, $data);
			
			// Insert data into temp table
			$this->insertIntoTempTable($tempTableName, $data);
			
			return $data;
		}
		
		/**
		 * Convert AstRetrieve node to SQL
		 * @param AstRetrieve $retrieve The AST to convert
		 * @param array $parameters Query parameters (passed by reference)
		 * @return string The generated SQL query
		 */
		private function convertToSQL(AstRetrieve $retrieve, array &$parameters): string {
			$quelToSQL = new QuelToSQL($this->entityManager->getEntityStore(), $parameters);
			return $quelToSQL->convertToSQL($retrieve);
		}
		
		/**
		 * Create a temporary table with the structure matching the data
		 * @param string $tableName
		 * @param array $data
		 * @return void
		 * @throws QuelException
		 */
		private function createTempTable(string $tableName, array $data): void {
			if (empty($data)) {
				throw new QuelException("Cannot create temp table from empty result set");
			}
			
			// Get column names from first row
			$firstRow = reset($data);
			$columns = [];
			
			foreach ($firstRow as $key => $value) {
				// Infer column type from value
				$type = match(true) {
					is_int($value) => 'INTEGER',
					is_float($value) => 'DOUBLE',
					is_bool($value) => 'BOOLEAN',
					default => 'VARCHAR(255)'
				};
				
				$columns[] = "`{$key}` {$type}";
			}
			
			$columnDefs = implode(', ', $columns);
			$sql = "CREATE TEMPORARY TABLE `{$tableName}` ({$columnDefs})";
			
			$this->connection->execute($sql, []);
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