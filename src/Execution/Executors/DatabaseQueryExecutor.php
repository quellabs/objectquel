<?php
	
	namespace Quellabs\ObjectQuel\Execution\Executors;
	
	use Cake\Database\StatementInterface;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Planner\ExecutionStageInterface;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\Execution\QueryTransformer;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL;
	
	/**
	 * Handles database-specific query execution including SQL conversion and temp tables
	 */
	class DatabaseQueryExecutor {
		protected EntityManager $entityManager;
		protected DatabaseAdapter $connection;
		protected QueryTransformer $queryTransformer;
		protected PlatformCapabilities $capabilities;
		
		/** @var list<string> */
		protected array $lastExecutedSql = [];
		
		/**
		 * Constructor
		 * @param EntityManager $entityManager
		 */
		public function __construct(EntityManager $entityManager, PlatformCapabilities $capabilities) {
			$this->entityManager = $entityManager;
			$this->connection = $entityManager->getConnection();
			$this->capabilities = $capabilities;
			$this->queryTransformer = new QueryTransformer($this->entityManager, $this->capabilities);
		}
		
		/**
		 * Execute a database query stage
		 * @param ExecutionStageInterface $stage
		 * @param array<string, mixed> $initialParams
		 * @return list<array<string, mixed>>
		 * @throws QuelException
		 */
		public function execute(ExecutionStageInterface $stage, array $initialParams = []): array {
			// Transform the query
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
		 * @return list<string>
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
		 * @param array<string, mixed> $parameters Query parameters (passed by reference)
		 * @return string The generated SQL query
		 */
		protected function convertToSQL(AstRetrieve $retrieve, array &$parameters): string {
			$quelToSQL = new QuelToSQL($this->entityManager->getEntityStore(), $parameters, $this->capabilities);
			return $quelToSQL->convertToSQL($retrieve);
		}
	}