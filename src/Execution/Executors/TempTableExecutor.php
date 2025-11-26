<?php
	
	namespace Quellabs\ObjectQuel\Execution\Executors;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Execution\ExecutionPlan;
	use Quellabs\ObjectQuel\Execution\ExecutionStage;
	use Quellabs\ObjectQuel\Execution\ExecutionStageTempTable;
	use Quellabs\ObjectQuel\Execution\PlanExecutor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	
	/**
	 * Handles execution of temporary table stages in ObjectQuel query plans.
	 *
	 * This executor creates temporary tables from inner query plans, executes those plans,
	 * and populates the temporary tables with the results. It handles both simple property
	 * retrieval and full entity expansion.
	 */
	class TempTableExecutor {
		
		private PlanExecutor $planExecutor;
		private DatabaseAdapter $connection;
		private EntityStore $entityStore;
		
		/**
		 * Initialize the temp table executor.
		 * @param PlanExecutor $planExecutor The parent plan executor for recursive plan execution
		 * @param DatabaseAdapter $connection Database connection for creating tables and inserting data
		 */
		public function __construct(PlanExecutor $planExecutor, DatabaseAdapter $connection) {
			$this->planExecutor = $planExecutor;
			$this->entityStore = $planExecutor->getEntityStore();
			$this->connection = $connection;
		}
		
		// ==================== Public Interface ====================
		
		/**
		 * Execute a temp table stage by recursively executing its inner plan.
		 *
		 * This method:
		 * 1. Extracts the inner execution plan from the stage
		 * 2. Recursively executes that plan to get result data
		 * 3. Creates a temporary table matching the result structure
		 * 4. Inserts the result data into the temporary table
		 *
		 * @param ExecutionStageTempTable $stage The temp table stage to execute
		 * @param array $initialParams Initial parameters for the inner plan execution
		 * @return array The data that was inserted into the temp table
		 * @throws QuelException If the inner plan is invalid or table creation fails
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
		
		// ==================== Table Creation ====================
		
		/**
		 * Create a temporary table based on the retrieve clause structure from an execution plan.
		 *
		 * This method analyzes the retrieve values from the plan's main stage and creates
		 * appropriate table columns. It handles two cases:
		 *
		 * 1. Entity retrieval (e.g., retrieve(x)): Expands to all entity properties with
		 *    prefixed column names like "x.id", "x.name", etc.
		 *
		 * 2. Property/expression retrieval (e.g., retrieve(id = x.id)): Creates a single
		 *    column with the alias name.
		 *
		 * @param string $tableName The name for the temporary table
		 * @param ExecutionPlan $plan The execution plan containing the retrieve structure
		 * @return void
		 * @throws QuelException If the plan is empty, has no main stage, or has no retrieve values
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
				if (!$value instanceof AstAlias) {
					continue;
				}
				
				$aliasName = $value->getName();
				$expression = $value->getExpression();
				
				// Check if this is an entity retrieval (e.g., retrieve(x))
				if (
					$expression instanceof AstIdentifier &&
					$expression->isFromEntity() &&
					!$expression->hasNext()
				) {
					// This is a whole entity - expand to all its columns
					$entityName = $expression->getEntityName();
					$entityColumns = $this->entityStore->getColumnMap($entityName);
					$rangeName = $expression->getRange()->getName();
					
					// Create a column for each entity property with prefixed name
					// Example: x.id, x.title, x.name
					foreach ($entityColumns as $propertyName => $dbColumnName) {
						$columnName = "{$rangeName}.{$propertyName}";
						$type = $this->inferTypeFromEntityProperty($entityName, $propertyName);
						$columns[] = "`{$columnName}` {$type}";
					}
				} else {
					// Not an entity retrieval - use the alias name directly
					// This handles:
					// - Stripped property access: "id" (from x.id)
					// - Explicit aliases: "myId" (from myId = x.id)
					// - Expressions: "total" (from total = x.price * x.quantity)
					$columnName = $aliasName;
					$type = $this->inferTypeFromAst($expression);
					$columns[] = "`{$columnName}` {$type}";
				}
			}
			
			$columnDefs = implode(', ', $columns);
			$sql = "CREATE TEMPORARY TABLE `{$tableName}` ({$columnDefs})";
			
			$this->connection->execute($sql, []);
		}
		
		/**
		 * Uses the column names from the first data row to build an INSERT statement,
		 * then executes individual inserts for each row using parameterized queries.
		 * @param string $tableName The name of the temporary table
		 * @param array $data Array of associative arrays, where each inner array represents a row
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
		
		// ==================== Type Inference ====================
		
		/**
		 * Infer SQL column type from entity property metadata.
		 *
		 * Looks up the entity's property annotations to find Column annotations,
		 * then uses the TypeMapper to convert Phinx types to SQL types. Falls back
		 * to VARCHAR(255) if type information cannot be determined.
		 *
		 * @param string $entityName The entity class name
		 * @param string $propertyName The property name on the entity
		 * @return string SQL column type definition (e.g., "INTEGER", "VARCHAR(255)")
		 */
		private function inferTypeFromEntityProperty(string $entityName, string $propertyName): string {
			try {
				// Get property annotations to determine type
				$annotations = $this->entityStore->getAnnotations($entityName);
				
				if (isset($annotations[$propertyName])) {
					$propertyAnnotations = $annotations[$propertyName]->toArray();
					
					foreach ($propertyAnnotations as $annotation) {
						if ($annotation instanceof Column) {
							$phinxType = $annotation->getType();
							
							// Use TypeMapper to convert Phinx type to SQL type
							return TypeMapper::phinxTypeToSqlType($phinxType, $annotation);
						}
					}
				}
			} catch (\Exception $e) {
				// Fall back to default if we can't determine type
			}
			
			return 'VARCHAR(255)';
		}
		
		/**
		 * Infer SQL column type from an AST expression.
		 *
		 * Analyzes the expression type to determine an appropriate SQL column type:
		 * - Aggregate functions (SUM, AVG, MIN, MAX): DOUBLE
		 * - Count functions: INTEGER
		 * - Everything else: VARCHAR(255)
		 *
		 * @param AstInterface $ast The AST node to analyze
		 * @return string SQL column type definition
		 */
		private function inferTypeFromAst(AstInterface $ast): string {
			// Check for aggregate functions that return numeric values
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
	}