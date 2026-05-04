<?php
	
	namespace Quellabs\ObjectQuel\Execution;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\ObjectQuel;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\QuelResult;
	use Quellabs\ObjectQuel\Execution\Executors\DatabaseQueryExecutor;
	use Quellabs\ObjectQuel\Execution\Executors\JsonQueryExecutor;
	
	/**
	 * Orchestrates query execution by delegating to specialized executors.
	 *
	 * TempTableStages are NOT routed through executeStage() — they are handled
	 * exclusively by PlanExecutor before result-producing stages run. executeStage()
	 * only ever receives ExecutionStage instances (database or JSON ranges).
	 */
	class QueryExecutor {
		
		private EntityManager $entityManager;
		private DatabaseAdapter $connection;
		private PlanExecutor $planExecutor;
		private ObjectQuel $objectQuel;
		private DatabaseQueryExecutor $databaseExecutor;
		private JsonQueryExecutor $jsonExecutor;
		
		/**
		 * Constructor
		 * @param EntityManager $entityManager
		 * @param DatabaseQueryExecutor|null $databaseExecutor
		 */
		public function __construct(EntityManager $entityManager, ?DatabaseQueryExecutor $databaseExecutor = null) {
			$this->entityManager = $entityManager;
			$this->connection = $entityManager->getConnection();
			$this->objectQuel = new ObjectQuel($entityManager);
			
			// Create specialized executors
			$conditionEvaluator = new ConditionEvaluator();
			$this->planExecutor = new PlanExecutor($this, $conditionEvaluator);
			$this->databaseExecutor = $databaseExecutor ?? new DatabaseQueryExecutor($entityManager);
			$this->jsonExecutor = new JsonQueryExecutor($conditionEvaluator);
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
		 * @param array<int|string, mixed> $initialParams (Optional) An array of parameters to bind to the query
		 * @return list<array<string, mixed>>
		 * @throws QuelException
		 */
		public function executeStage(ExecutionStageInterface $stage, array $initialParams = []): array {
			$queryType = $stage->getRange() instanceof AstRangeJsonSource ? 'json' : 'database';
			
			return match ($queryType) {
				'json' => $this->jsonExecutor->execute($stage, $this->normalizeParams($initialParams)),
				'database' => $this->databaseExecutor->execute($stage, $this->normalizeParams($initialParams)),
			};
		}
		
		/**
		 * Execute an already-built execution plan and return raw result rows.
		 *
		 * This lower-level entry point is used by PlanExecutor's inner query runner
		 * when materialising a TempTableStage. It accepts a pre-built ExecutionPlan so
		 * that the inner AstRetrieve is not re-parsed from a string.
		 *
		 * @param ExecutionPlan $plan The plan to execute
		 * @return list<array<string, mixed>>
		 * @throws QuelException
		 */
		public function executePlan(ExecutionPlan $plan): array {
			return $this->planExecutor->execute($plan);
		}
		
		/**
		 * Execute a decomposed query plan
		 * @param string $query The query to execute
		 * @param array<int|string, mixed> $parameters
		 * @return QuelResult
		 * @throws QuelException
		 */
		public function executeQuery(string $query, array $parameters = []): QuelResult {
			// Clear SQL list
			$this->databaseExecutor->resetLastExecutedSql();
			
			// Parse the input query string into an Abstract Syntax Tree (AST)
			$ast = $this->getObjectQuel()->parse(trim($query));
			
			// Decompose the query
			$decomposer = new QueryDecomposer();
			$executionPlan = $decomposer->buildExecutionPlan($ast, $this->normalizeParams($parameters));
			
			// Execute the returned execution plan and return the QuelResult
			$result = $this->planExecutor->execute($executionPlan);
			
			// QuelResult gebruikt de AST om de ontvangen data te transformeren naar entities
			return new QuelResult($this->entityManager, $ast, $result);
		}
		
		/**
		 * Return the executed SQL
		 * @return list<string>
		 */
		public function getLastExecutedSql(): array {
			return $this->databaseExecutor->getLastExecutedSql();
		}
		
		/**
		 * Normalizes an array of parameters by casting all keys to strings.
		 * @param array<int|string, mixed> $params The parameters to normalize.
		 * @return array<string, mixed> The normalized parameters with string keys.
		 */
		private function normalizeParams(array $params): array {
			$normalized = [];
			
			foreach ($params as $key => $value) {
				$normalized[(string)$key] = $value;
			}
			
			return $normalized;
		}
	}