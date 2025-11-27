<?php
	
	namespace Quellabs\ObjectQuel\Execution\Executors;
	
	use Cake\Database\StatementInterface;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Execution\ExecutionStage;
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
			
			echo $sql . "<br>";
			
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
		 * Convert AstRetrieve node to SQL
		 * @param AstRetrieve $retrieve The AST to convert
		 * @param array $parameters Query parameters (passed by reference)
		 * @return string The generated SQL query
		 */
		private function convertToSQL(AstRetrieve $retrieve, array &$parameters): string {
			$quelToSQL = new QuelToSQL($this->entityManager->getEntityStore(), $parameters);
			return $quelToSQL->convertToSQL($retrieve);
		}
	}