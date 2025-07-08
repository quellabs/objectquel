<?php
	
	namespace Quellabs\ObjectQuel\QueryManagement;
	
	use Flow\JSONPath\JSONPathException;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Execution\ExecutionStage;
	use Quellabs\ObjectQuel\Execution\PlanExecutor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\ObjectQuel;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\QuelResult;
	
	/**
	 * Represents an Entity Manager.
	 */
	class QueryExecutor {
		protected DatabaseAdapter $connection;
		private EntityManager $entityManager;
		private PlanExecutor $planExecutor;
		private ObjectQuel $objectQuel;
		private ConditionEvaluator $conditionEvaluator;
		
		/**
		 * @param EntityManager $entityManager
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityManager = $entityManager;
			$this->connection = $entityManager->getConnection();
			$this->conditionEvaluator = new ConditionEvaluator();
			$this->planExecutor = new PlanExecutor($this, $this->conditionEvaluator);
			$this->objectQuel = new ObjectQuel($entityManager);
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
		 * Removes duplicate objects from an array based on their object hash.
		 * Non-objects in the array are left unchanged.
		 * @param array $array The input array with possibly duplicate objects.
		 * @return array An array with unique objects and all original non-object elements.
		 */
		public function deDuplicateObjects(array $array): array {
			// Storage for the hashes of objects that have already been seen.
			$objectKeys = [];
			
			// Use array_filter to go through the array and remove duplicate objects.
			return array_filter($array, function ($item) use (&$objectKeys) {
				// If the item is not an object, keep it in the array.
				if (!is_object($item)) {
					return true;
				}
				
				// Calculate the unique hash of the object.
				$hash = spl_object_hash($item);
				
				// Check if the hash is already in the list of seen objects.
				if (in_array($hash, $objectKeys)) {
					// If yes, filter this object out of the array.
					return false;
				}
				
				// Add the hash to the list of seen objects and keep the item in the array.
				$objectKeys[] = $hash;
				return true;
			});
		}
		
		/**
		 * Execute a database query and return the results
		 * @param ExecutionStage $stage
		 * @param array $initialParams (Optional) An array of parameters to bind to the query
		 * @return array
		 * @throws QuelException
		 */
		public function executeStage(ExecutionStage $stage, array $initialParams = []): array {
			$queryType = $stage->getRange() instanceof AstRangeJsonSource ? 'json' : 'database';
			
			return match ($queryType) {
				'json' => $this->executeSimpleQueryJson($stage, $initialParams),
				'database' => $this->executeSimpleQueryDatabase($stage, $initialParams),
			};
		}
		
		/**
		 * Retrieves all results of an executed ObjectQuel query.
		 * @param string $query
		 * @param array $parameters
		 * @return array
		 * @throws QuelException
		 */
		public function getAll(string $query, array $parameters = []): array {
			// Executes the query with the specified parameters.
			$rs = $this->executeQuery($query, $parameters);
			
			// Checks if the query has successful results.
			if ($rs->recordCount() == 0) {
				return [];
			}
			
			// Iterates through all rows of the result.
			$result = [];
			while ($row = $rs->fetchRow()) {
				$result[] = $row;
			}
			
			return $result;
		}
		
		/**
		 * Executes an ObjectQuel query and returns an array of objects from the
		 * first column of each result, with duplicates removed.
		 * @param string $query The ObjectQuel query to execute.
		 * @param array $parameters Optional parameters for the query.
		 * @return array An array of unique objects from the first column of the query results.
		 * @throws QuelException
		 */
		public function getCol(string $query, array $parameters = []): array {
			// Executes the query with the specified parameters.
			$rs = $this->executeQuery($query, $parameters);
			
			// Checks if the query was successful and has results.
			if ($rs->recordCount() == 0) {
				return [];
			}
			
			// Get the result
			$result = [];
			$keys = null;
			
			while ($row = $rs->fetchRow()) {
				// Determines the keys (column names) of the first row, if not already determined.
				if ($keys === null) {
					$keys = array_keys($row);
				}
				
				// Adds the value of the first column to the result.
				$result[] = $row[$keys[0]];
			}
			
			// Returns deduplicated results.
			return $this->deDuplicateObjects($result);
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
			$ast = $this->getObjectQuel()->parse($query);
			
			// Decompose the query
			$decomposer = new QueryDecomposer();
			$executionPlan = $decomposer->buildExecutionPlan($ast, $parameters);
			
			// Execute the returned execution plan and return the QuelResult
			$result = $this->planExecutor->execute($executionPlan);
			
			// QuelResult gebruikt de AST om de ontvangen data te transformeren naar entities
			return new QuelResult($this->entityManager, $ast, $result);
		}
		
		/**
		 * Transforms a Quel query to SQL, executes the SQL and returns the result
		 * @param ExecutionStage $stage The parsed query (AST)
		 * @param array $initialParams Parameters for this query
		 * @return array The QuelResult object
		 * @throws QuelException
		 */
		private function executeSimpleQueryDatabase(ExecutionStage $stage, array $initialParams = []): array {
			// Convert the query to SQL
			$sql = $this->objectQuel->convertToSQL($stage->getQuery(), $initialParams);
			
			// Execute the SQL query
			$rs = $this->connection->execute($sql, $initialParams);
			
			// If the query is incorrect, throw an exception
			if (!$rs) {
				throw new QuelException($this->connection->getLastErrorMessage());
			}
			
			// Retrieve all data and pass it to QuelResult
			$result = [];
			while ($row = $rs->fetch('assoc')) {
				$result[] = $row;
			}
			
			return $result;
		}
		
		/**
		 * Load and filter a JSON file from a JSON source
		 * @param AstRangeJsonSource $source
		 * @return array
		 * @throws QuelException
		 */
		private function loadAndFilterJsonFile(AstRangeJsonSource $source): array {
			// Load the JSON file
			$contents = file_get_contents($source->getPath());
			
			if ($contents === false) {
				throw new QuelException("JSON file {$source->getName()} not found");
			}
			
			// Decode the JSON file
			$decoded = json_decode($contents, true);
			
			if (json_last_error() !== JSON_ERROR_NONE) {
				throw new QuelException("Error decoding JSON file {$source->getName()}: " . json_last_error_msg());
			}
			
			// If a JSONPath was given, use it to filter the output
			if (!empty($source->getExpression())) {
				try {
					$decoded = (new \Flow\JSONPath\JSONPath($decoded))->find($source->getExpression())->getData();
				} catch (JSONPathException $e) {
					throw new QuelException($e->getMessage(), $e->getCode(), $e);
				}
			}
			
			// Prefix all items with the range alias
			$result = [];
			$alias = $source->getName();
			
			foreach ($decoded as $row) {
				$line = [];
				
				foreach ($row as $key => $value) {
					$line["{$alias}.{$key}"] = $value;
				}
				
				$result[] = $line;
			}
			
			return $result;
		}
		
		/**
		 * Execute a JSON query and returns the result
		 * @param ExecutionStage $stage
		 * @param array $initialParams
		 * @return array
		 * @throws QuelException
		 */
		private function executeSimpleQueryJson(ExecutionStage $stage, array $initialParams = []): array {
			// Load the JSON file and perform initial filtering
			$contents = $this->loadAndFilterJsonFile($stage->getRange());
			
			// Use the conditions to further filter the file
			$result = [];
			
			foreach ($contents as $row) {
				if ($stage->getQuery()->getConditions() === null ||
					$this->conditionEvaluator->evaluate($stage->getQuery()->getConditions(), $row, $initialParams)) {
					$result[] = $row;
				}
			}
			
			return $result;
		}
	}