<?php
	
	namespace Quellabs\ObjectQuel\Execution\Executors;
	
	use Cake\Database\StatementInterface;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Execution\ExecutionStage;
	use Quellabs\ObjectQuel\Execution\ExecutionStageInterface;
	use Quellabs\ObjectQuel\Execution\QueryOptimizer;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\Execution\QueryTransformer;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL;
	
	/**
	 * Handles database-specific query execution including SQL conversion and temp tables
	 */
	class DatabaseQueryExecutor {
		protected EntityManager $entityManager;
		protected DatabaseAdapter $connection;
		protected QueryTransformer $queryTransformer;
		protected QueryOptimizer $queryOptimizer;
		protected PlatformCapabilities $platform;
		protected array $lastExecutedSql = [];
		
		/**
		 * Constructor
		 * @param EntityManager $entityManager
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityManager = $entityManager;
			$this->connection = $entityManager->getConnection();
			$this->platform = new PlatformCapabilities($this->connection);
			$this->queryTransformer = new QueryTransformer($this->entityManager, $this->platform);
			$this->queryOptimizer = new QueryOptimizer($this->entityManager, $this->platform);
		}
		
		/**
		 * Execute a database query stage
		 * @param ExecutionStage $stage
		 * @param array $initialParams
		 * @return array
		 * @throws QuelException
		 */
		public function execute(ExecutionStageInterface $stage, array $initialParams = []): array {
			// Transform and optimize the query
			$this->queryOptimizer->optimize($stage->getQuery());
			$this->queryTransformer->transform($stage->getQuery(), $initialParams);
			
			// Convert the query to SQL
			$sql = $this->convertToSQL($stage->getQuery(), $initialParams);
			
			// Store SQL
			$this->lastExecutedSql[] = $sql;
			
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
		 * Return the last executed SQL
		 * @return array
		 */
		public function getLastExecutedSql(): array {
			return $this->lastExecutedSql;
		}

		/**
		 * Clear the lastExecutedSql list
		 * @return void
		 */
		public function resetLastExecutedSql(): void {
			$this->lastExecutedSql = [];
		}

		/**
		 * Convert AstRetrieve node to SQL
		 * @param AstRetrieve $retrieve The AST to convert
		 * @param array $parameters Query parameters (passed by reference)
		 * @return string The generated SQL query
		 */
		protected function convertToSQL(AstRetrieve $retrieve, array &$parameters): string {
			$quelToSQL = new QuelToSQL($this->entityManager->getEntityStore(), $parameters, $this->platform);
			return $quelToSQL->convertToSQL($retrieve);
		}
	
	}