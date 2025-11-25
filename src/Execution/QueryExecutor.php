<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Execution\Executors\TempTableExecutor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\ObjectQuel;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\QuelResult;
	use Quellabs\ObjectQuel\Execution\Executors\DatabaseQueryExecutor;
	use Quellabs\ObjectQuel\Execution\Executors\JsonQueryExecutor;
	
	/**
	 * Orchestrates query execution by delegating to specialized executors
	 */
	class QueryExecutor {
		
		private EntityManager $entityManager;
		private DatabaseAdapter $connection;
		private PlanExecutor $planExecutor;
		private ObjectQuel $objectQuel;
		private DatabaseQueryExecutor $databaseExecutor;
		private JsonQueryExecutor $jsonExecutor;
		private TempTableExecutor $tempTableExecutor;
		
		public function __construct(EntityManager $entityManager) {
			$this->entityManager = $entityManager;
			$this->connection = $entityManager->getConnection();
			$this->objectQuel = new ObjectQuel($entityManager);
			
			// Create specialized executors
			$conditionEvaluator = new ConditionEvaluator();
			$this->planExecutor = new PlanExecutor($this, $conditionEvaluator);
			$this->databaseExecutor = new DatabaseQueryExecutor($entityManager);
			$this->jsonExecutor = new JsonQueryExecutor($conditionEvaluator);
			$this->tempTableExecutor = new TempTableExecutor($this->planExecutor, $this->connection);
		}
		
		/**
		 * Returns the entity manager object
		 * @return EntityManager
		 */
		public function getEntityManager(): EntityManager {
			return $this->entityManager;
		}
		
		/**
		 * Returns the ObjectQuel parser
		 * @return ObjectQuel
		 */
		public function getObjectQuel(): ObjectQuel {
			return $this->objectQuel;
		}
		
		/**
		 * Returns the DatabaseAdapter
		 * @return DatabaseAdapter
		 */
		public function getConnection(): DatabaseAdapter {
			return $this->connection;
		}
		
		/**
		 * Execute a database query and return the results
		 * @param ExecutionStageInterface $stage
		 * @param array $initialParams (Optional) An array of parameters to bind to the query
		 * @return array
		 * @throws QuelException
		 */
		public function executeStage(ExecutionStageInterface $stage, array $initialParams = []): array {
			// Handle temp table stages
			if ($stage instanceof ExecutionStageTempTable) {
				return $this->tempTableExecutor->execute($stage, $initialParams);
			}
			
			// Handle regular stages
			if ($stage instanceof ExecutionStage) {
				$queryType = $stage->getRange() instanceof AstRangeJsonSource ? 'json' : 'database';
				
				return match ($queryType) {
					'json' => $this->jsonExecutor->execute($stage, $initialParams),
					'database' => $this->databaseExecutor->execute($stage, $initialParams),
				};
			}
			
			throw new QuelException('Unknown stage type: ' . get_class($stage));
		}
		
		/**
		 * Execute a decomposed query plan
		 * @param string $query The query to execute
		 * @param array $parameters Initial parameters for the plan
		 * @return QuelResult The results of the execution plan
		 * @throws QuelException
		 */
		public function executeQuery(string $query, array $parameters = []): QuelResult {
			// Parse the input query string into an Abstract Syntax Tree (AST)
			$ast = $this->getObjectQuel()->parse(trim($query));
			
			// Decompose the query
			$decomposer = new QueryDecomposer();
			$executionPlan = $decomposer->buildExecutionPlan($ast, $parameters);
			
			// Execute the returned execution plan and return the QuelResult
			$result = $this->planExecutor->execute($executionPlan);
			
			// QuelResult gebruikt de AST om de ontvangen data te transformeren naar entities
			return new QuelResult($this->entityManager, $ast, $result);
		}
	}