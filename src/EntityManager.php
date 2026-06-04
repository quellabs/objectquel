<?php
	
	/*
	 * ╔═══════════════════════════════════════════════════════════════════════════════════════╗
	 * ║                                                                                       ║
	 * ║   ██████╗ ██████╗      ██╗███████╗ ██████╗████████╗ ██████╗ ██╗   ██╗███████╗██╗      ║
	 * ║  ██╔═══██╗██╔══██╗     ██║██╔════╝██╔════╝╚══██╔══╝██╔═══██╗██║   ██║██╔════╝██║      ║
	 * ║  ██║   ██║██████╔╝     ██║█████╗  ██║        ██║   ██║   ██║██║   ██║█████╗  ██║      ║
	 * ║  ██║   ██║██╔══██╗██   ██║██╔══╝  ██║        ██║   ██║▄▄ ██║██║   ██║██╔══╝  ██║      ║
	 * ║  ╚██████╔╝██████╔╝╚█████╔╝███████╗╚██████╗   ██║   ╚██████╔╝╚██████╔╝███████╗███████╗ ║
	 * ║   ╚═════╝ ╚═════╝  ╚════╝ ╚══════╝ ╚═════╝   ╚═╝    ╚══▀▀═╝  ╚═════╝ ╚══════╝╚══════╝ ║
	 * ║                                                                                       ║
	 * ║  ObjectQuel - Powerful Object-Relational Mapping built on the Data Mapper pattern     ║
	 * ║                                                                                       ║
	 * ║  Clean separation between entities and persistence logic with an intuitive,           ║
	 * ║  object-oriented query language. Powered by CakePHP's robust database foundation.     ║
	 * ║                                                                                       ║
	 * ╚═══════════════════════════════════════════════════════════════════════════════════════╝
	 */
	
	namespace Quellabs\ObjectQuel;
	
	use Cake\Database\Connection;
	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Execution\ResultProcessor;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\QuelResult;
	use Quellabs\ObjectQuel\ProxyGenerator\ProxyInterface;
	use Quellabs\ObjectQuel\Execution\QueryBuilder;
	use Quellabs\ObjectQuel\Execution\QueryExecutor;
	use Quellabs\ObjectQuel\Planner\QueryPlan\QueryPlan;
	use Quellabs\ObjectQuel\ReflectionManagement\PropertyHandler;
	use Quellabs\ObjectQuel\Validation\EntityToValidation;
	use Quellabs\ObjectQuel\Validation\ValidationInterface;
	use Quellabs\SignalHub\Signal;
	use Quellabs\SignalHub\SignalHub;
	use Quellabs\SignalHub\SignalHubLocator;
	use Quellabs\Support\Tools;
	
	/**
	 * Represents an Entity Manager.
	 */
	class EntityManager {
		
		/**
		 * Signals
		 */
		protected ?Signal $debugQuerySignal;
		
		/**
		 * Properties
		 */
		protected Configuration $configuration;
		protected SignalHub $signalHub;
		protected DatabaseAdapter $connection;
		protected UnitOfWork $unitOfWork;
		protected EntityStore $entityStore;
		protected QueryBuilder $queryBuilder;
		protected PropertyHandler $propertyHandler;
		protected QueryExecutor $queryExecutor;
		
		/**
		 * EntityManager constructor
		 * @param Configuration $configuration
		 * @param Connection $connection CakePHP database connection
		 * @throws AnnotationReaderException
		 */
		public function __construct(Configuration $configuration, Connection $connection) {
			$this->configuration = $configuration;
			$this->signalHub = SignalHubLocator::getInstance();
			$this->connection = new DatabaseAdapter($connection);
			$this->entityStore = new EntityStore($configuration);
			$this->unitOfWork = new UnitOfWork($this);
			$this->queryBuilder = new QueryBuilder($this->entityStore);
			$this->queryExecutor = new QueryExecutor($this);
			$this->propertyHandler = new PropertyHandler();
			
			// Fetch Signal or create if it doesn't exist
			$this->debugQuerySignal = $this->signalHub->getSignal('debug.database.query');
			
			if ($this->debugQuerySignal === null) {
				$this->debugQuerySignal = new Signal('debug.database.query');
				$this->signalHub->registerSignal($this->debugQuerySignal);
			}
		}
		
		/**
		 * Remove signal from hub
		 */
		public function __destruct() {
			if ($this->debugQuerySignal !== null) {
				$this->signalHub->unregisterSignal($this->debugQuerySignal);
			}
		}
		
		/**
		 * Returns the DatabaseAdapter
		 * @return DatabaseAdapter
		 */
		public function getConnection(): DatabaseAdapter {
			return $this->connection;
		}
		
		/**
		 * Returns the unit of work
		 * @return UnitOfWork
		 */
		public function getUnitOfWork(): UnitOfWork {
			return $this->unitOfWork;
		}
		
		/**
		 * Returns the entity store
		 * @return EntityStore
		 */
		public function getEntityStore(): EntityStore {
			return $this->entityStore;
		}
		
		/**
		 * Returns the property handler
		 * @return PropertyHandler
		 */
		public function getPropertyHandler(): PropertyHandler {
			return $this->propertyHandler;
		}
		
		/**
		 * Persists (inserts) an entity into the database
		 *
		 * For entities with composite primary keys where multiple keys use the identity strategy,
		 * only the first identity key will receive the database-generated value. Other identity
		 * keys must be set manually before calling flush().
		 *
		 * @param object $entity The entity to be inserted into the database
		 */
		public function persist(object $entity): bool {
			return $this->unitOfWork->persistNew($entity);
		}
		
		/**
		 * Flush all changed entities to the database
		 * If an error occurs, an OrmException is thrown.
		 * @param object|array<int, object>|null $entity
		 * @return void
		 * @throws OrmException
		 */
		public function flush(object|array|null $entity = null): void {
			$this->unitOfWork->commit($entity);
		}
		
		/**
		 * Detach an entity from the EntityManager.
		 * This will remove the entity from the identity map and stop tracking its changes.
		 * @param object $entity The entity to detach.
		 */
		public function detach(object $entity): void {
			$this->unitOfWork->detach($entity);
		}
		
		/**
		 * Execute a decomposed query plan
		 * @param string $query The query to execute
		 * @param array<string, mixed> $parameters Initial parameters for the plan
		 * @return QuelResult|null The results of the execution plan
		 * @throws QuelException
		 */
		public function executeQuery(string $query, array $parameters = []): ?QuelResult {
			// Record start time for performance monitoring
			$start = microtime(true);
			
			// Execute the query through the query executor
			$result = $this->queryExecutor->executeQuery($query, $parameters);
			
			// Record end time to calculate execution duration
			$end = microtime(true);
			
			// In development mode, emit a debug signal with the full query plan
			// (planner decisions + generated SQL). Skipped entirely in production.
			if ($this->configuration->getDevelopmentMode()) {
				// Fetch data
				$executionTime = round(($end - $start) * 1000, 0, PHP_ROUND_HALF_UP);
				$memoryUsage = memory_get_usage(true) / 1024;
				$memoryPeakUsage = memory_get_peak_usage(true) / 1024;
				
				// Explain the query
				$plan = $this->queryExecutor->explainQuery($query, $parameters);
				
				// Emit the query plan + additional query info
				$this->debugQuerySignal?->emit([
					'driver'            => 'objectquel',
					'query'             => Tools::dedent($query),
					'query_plan'        => $plan,
					'bound_parameters'  => $parameters,
					'execution_time_ms' => $executionTime,
					'timestamp'         => date('Y-m-d H:i:s'),
					'memory_usage_kb'   => $memoryUsage,
					'peak_memory_kb'    => $memoryPeakUsage,
				]);
			}
			
			return $result;
		}
		
		/**
		 * Returns planner decisions and generated SQL for a query without executing it.
		 * Combines explain() with a SQL dry-run into one coherent result.
		 * @param string $query The ObjectQuel query string
		 * @param array<string, mixed> $parameters Query parameters
		 * @return QueryPlan Planning decisions and generated SQL
		 * @throws QuelException
		 */
		public function explainQuery(string $query, array $parameters = []): QueryPlan {
			return $this->queryExecutor->explainQuery($query, $parameters);
		}
		
		/**
		 * Retrieves all results from an executed ObjectQuel query.
		 * @param string $query The ObjectQuel query string to execute
		 * @param array<string, mixed> $parameters Optional parameters to bind to the query (default: empty array)
		 * @return array<int, array<string, mixed>> Array of all rows returned by the query, or empty array if no results
		 * @throws QuelException When query execution fails or encounters an error
		 */
		public function getAll(string $query, array $parameters = []): array {
			// Execute the ObjectQuel query with the provided parameters
			// This delegates to the executeQuery method which handles parameter binding and execution
			$rs = $this->executeQuery($query, $parameters);
			
			// Check if the query returned any results
			if ($rs === null || $rs->recordCount() == 0) {
				return [];
			}
			
			// Build result array by iterating through all rows in the result set
			$result = [];
			while ($row = $rs->fetchRow()) {
				$result[] = $row;
			}
			
			// Return the complete array of all fetched rows
			return $result;
		}
		
		/**
		 * Executes an ObjectQuel query and returns an array of objects from the
		 * first column of each result, with duplicates removed.
		 * @param string $query The ObjectQuel query to execute.
		 * @param array<string, mixed> $parameters Optional parameters for the query.
		 * @return array<int, mixed> An array of unique objects from the first column of query results.
		 * @throws QuelException
		 */
		public function getCol(string $query, array $parameters = []): array {
			// Execute the ObjectQuel query with the provided parameters
			$rs = $this->executeQuery($query, $parameters);
			
			// Return an empty array if the query returned no results
			if ($rs === null || $rs->recordCount() == 0) {
				return [];
			}
			
			// Iterate through each row in the result set
			$result = [];
			$keys = null;
			
			while ($row = $rs->fetchRow()) {
				// Get column names from the first row (cached for performance)
				if ($keys === null) {
					$keys = array_keys($row);
				}
				
				// Extract the value from the first column and add to the result array
				$result[] = $row[$keys[0]];
			}
			
			// Remove duplicate objects from the result array before returning
			return ResultProcessor::deDuplicateObjects($result);
		}
		
		/**
		 * Searches for a single entity based on the given entity type and primary key.
		 * @template T of object
		 * @param class-string<T> $entityType The fully qualified class name of the entity
		 * @param int|string $primaryKey The primary key of the entity
		 * @return T|null The found entity or null if not found
		 * @throws QuelException
		 * @throws EntityResolutionException
		 * @psalm-return T|null
		 */
		public function find(string $entityType, int|string $primaryKey): ?object {
			// Normalize the primary key
			$firstKey = $this->entityStore->getMetadata($entityType)->getPrimaryKey();
			$primaryKeys = $firstKey ? [$firstKey => $primaryKey] : [];
			
			// Return early if the entity is already tracked and fully initialized
			$existingEntity = $this->unitOfWork->findEntity($entityType, $primaryKeys);
			
			// If the entity exists and is initialized, return it
			if (
				$existingEntity !== null &&
				!($existingEntity instanceof ProxyInterface && !$existingEntity->isInitialized())
			) {
				/** @var T $existingEntity */
				return $existingEntity;
			}
			
			// Fall back to a database query
			$result = $this->findBy($entityType, $primaryKeys);
			
			// If the query returns no results, return null
			if (empty($result)) {
				return null;
			}
			
			/**
			 * Get the results from the query and return the main entity
			 * @var T $entity
			 */
			$entity = $result[0];
			return $entity;
		}
		
		/**
		 * Searches for entities based on the given entity type and primary key.
		 * @template T
		 * @param class-string<T> $entityType The fully qualified class name of the container
		 * @param array<string, mixed> $searchData Associative array of field names and values to filter by
		 * @param array<string, string>|null $sortBy Associative array of field names and sort directions
		 * @return T[] The found entities
		 * @throws QuelException
		 * @throws EntityResolutionException
		 */
		public function findBy(string $entityType, array $searchData, ?array $sortBy = null): array {
			// Prepare a query in case the entity is not found
			$query = $this->queryBuilder->prepareQuery($entityType, $searchData, $sortBy);
			
			// Execute query and retrieve result
			$result = $this->getAll($query, $searchData);
			
			// Extract the main column from the result
			$filteredResult = array_column($result, "main");
			
			// Return deduplicated results
			return ResultProcessor::deDuplicateObjects($filteredResult);
		}
		
		/**
		 * Searches for entities based on the given entity type and primary key.
		 * @template T of object
		 * @param class-string<T> $entityType The fully qualified class name of the container
		 * @param array<string, mixed> $searchData Associative array of field names and values to filter by
		 * @param array<string, string>|null $sortBy Associative array of field names and sort directions
		 * @return T|null The found entity or null if not found
		 * @throws QuelException
		 * @throws EntityResolutionException
		 */
		public function findOneBy(string $entityType, array $searchData, ?array $sortBy = null): ?object {
			$results = $this->findBy($entityType, $searchData, $sortBy);
			return $results[0] ?? null;
		}
		
		/**
		 * Schedules an entity for removal
		 * @param object $entity
		 * @return void
		 * @throws EntityResolutionException
		 */
		public function remove(object $entity): void {
			$this->unitOfWork->scheduleForDelete($entity);
		}
		
		/**
		 * Returns the validation rules of a given entity
		 * @param object $entity
		 * @return array<int|string, array<int, ValidationInterface>>
		 */
		public function getValidationRules(object $entity): array {
			$validate = new EntityToValidation();
			return $validate->convert($entity);
		}
	}