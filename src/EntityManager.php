<?php
	
	/**
	 * ObjectQuel - A Sophisticated Object-Relational Mapping (ORM) System
	 *
	 * ObjectQuel is an ORM that brings a fresh approach to database interaction,
	 * featuring a unique query language, a streamlined architecture, and powerful
	 * entity relationship management. It implements the Data Mapper pattern for
	 * clear separation between domain models and underlying database structures.
	 *
	 * @author      Floris van den Berg
	 * @copyright   Copyright (c) 2025 ObjectQuel
	 * @license     MIT
	 * @version     1.0.0
	 * @package     Quellabs\ObjectQuel
	 */
	
    namespace Quellabs\ObjectQuel;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\QuelResult;
	use Quellabs\ObjectQuel\ProxyGenerator\ProxyGenerator;
	use Quellabs\ObjectQuel\ProxyGenerator\ProxyInterface;
	use Quellabs\ObjectQuel\QueryManagement\QueryBuilder;
	use Quellabs\ObjectQuel\QueryManagement\QueryExecutor;
	use Quellabs\ObjectQuel\ReflectionManagement\PropertyHandler;
	use Quellabs\ObjectQuel\Validation\EntityToValidation;
	
	/**
	 * Represents an Entity Manager.
	 */
	class EntityManager {
		protected Configuration $configuration;
        protected DatabaseAdapter $connection;
        protected UnitOfWork $unit_of_work;
		protected EntityStore $entity_store;
		protected QueryBuilder $query_builder;
		protected PropertyHandler $property_handler;
		protected QueryExecutor $query_executor;
		
		/**
		 * EntityManager constructor - accepts optional configuration or discovers it
		 * @param Configuration|null $configuration
		 */
        public function __construct(?Configuration $configuration = null) {
	        $this->configuration = $configuration;
            $this->connection = new DatabaseAdapter($configuration);
	        $this->entity_store = new EntityStore($configuration);
            $this->unit_of_work = new UnitOfWork($this);
			$this->query_builder = new QueryBuilder($this->entity_store);
			$this->query_executor = new QueryExecutor($this);
			$this->property_handler = new PropertyHandler();
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
			return $this->unit_of_work;
		}
		
		/**
		 * Returns the entity store
		 * @return EntityStore
		 */
		public function getEntityStore(): EntityStore {
			return $this->entity_store;
		}
		
		/**
		 * Returns the property handler
		 * @return PropertyHandler
		 */
		public function getPropertyHandler(): PropertyHandler {
			return $this->property_handler;
		}
		
        /**
         * Adds an entity to the entity manager list
         * @param $entity
         * @return bool
         */
        public function persist(&$entity): bool {
            if (!is_object($entity)) {
				return false;
			}
			
			return $this->unit_of_work->persistNew($entity);
        }
    
		/**
		 * Flush all changed entities to the database
		 * If an error occurs, an OrmException is thrown.
		 * @param mixed|null $entity
		 * @return void
		 * @throws OrmException
		 */
        public function flush(mixed $entity = null): void {
            $this->unit_of_work->commit($entity);
        }
		
		/**
		 * Detach an entity from the EntityManager.
		 * This will remove the entity from the identity map and stop tracking its changes.
		 * @param object $entity The entity to detach.
		 */
		public function detach(object $entity): void {
			$this->unit_of_work->detach($entity);
		}
		
		/**
		 * Execute a decomposed query plan
		 * @param string $query The query to execute
		 * @param array $parameters Initial parameters for the plan
		 * @return QuelResult|null The results of the execution plan
		 * @throws QuelException
		 */
		public function executeQuery(string $query, array $parameters=[]): ?QuelResult {
			return $this->query_executor->executeQuery($query, $parameters);
		}
		
		/**
		 * Retrieves all results from an executed ObjectQuel query.
		 * @param string $query
		 * @param array $parameters
		 * @return array
		 * @throws QuelException
		 */
		public function getAll(string $query, array $parameters=[]): array {
			return $this->query_executor->getAll($query, $parameters);
		}
		
		/**
		 * Executes an ObjectQuel query and returns an array of objects from the
		 * first column of each result, with duplicates removed.
		 * @param string $query The ObjectQuel query to execute.
		 * @param array $parameters Optional parameters for the query.
		 * @return array An array of unique objects from the first column of query results.
		 */
		public function getCol(string $query, array $parameters=[]): array {
			return $this->query_executor->getCol($query, $parameters);
		}
		
		/**
		 * Searches for entities based on the given entity type and primary key.
		 * @template T
		 * @param class-string<T> $entityType The fully qualified class name of the container
		 * @param mixed $primaryKey The primary key of the entity
		 * @return T[] The found entities
		 * @throws QuelException
		 */
		public function findBy(string $entityType, mixed $primaryKey): array {
			// Check if the desired entity type is actually an entity
			if (!$this->entity_store->exists($entityType)) {
				throw new QuelException("The entity or range {$entityType} referenced in the query does not exist.");
			}
			
			// Normalize the primary key
			$primaryKeys = $this->entity_store->formatPrimaryKeyAsArray($primaryKey, $entityType);
			
			// Prepare a query in case the entity is not found
			$query = $this->query_builder->prepareQuery($entityType, $primaryKeys);
			
			// Execute query and retrieve result
			$result = $this->query_executor->getAll($query, $primaryKeys);
			
			// Extract the main column from the result
			$filteredResult = array_column($result, "main");
			
			// Return deduplicated results
			return $this->query_executor->deDuplicateObjects($filteredResult);
		}
		
		/**
		 * Searches for a single entity based on the given entity type and primary key.
		 * @template T of object
		 * @param class-string<T> $entityType The fully qualified class name of the container
		 * @param mixed $primaryKey The primary key of the entity
		 * @return T|null The found entity or null if not found
		 * @throws QuelException
		 * @psalm-return T|null
		 */
		public function find(string $entityType, mixed $primaryKey): ?object {
			// Check if the desired entity type is actually an entity
			if (!$this->entity_store->exists($entityType)) {
				throw new QuelException("The entity or range {$entityType} referenced in the query does not exist.");
			}
			
			// Normalize the primary key
			$primaryKeys = $this->entity_store->formatPrimaryKeyAsArray($primaryKey, $entityType);
			
			// Try to find the entity in the current unit of work
			$existingEntity = $this->unit_of_work->findEntity($entityType, $primaryKeys);
			
			// If the entity exists and is initialized, return it
			if (!empty($existingEntity) && !($existingEntity instanceof ProxyInterface && !$existingEntity->isInitialized())) {
				return $existingEntity;
			}
			
			// Retrieve results
			$result = $this->findBy($entityType, $primaryKey);
			
			// If the query returns no results, return null
			if (empty($result)) {
				return null;
			}
			
			// Get the results from the query and return the main entity
			return $result[0] ?? null; // Use null-coalescing operator for safe access
		}
		
		/**
		 * Schedules an entity for removal
		 * @param object $entity
		 * @return void
		 */
		public function remove(object $entity): void {
			$this->unit_of_work->scheduleForDelete($entity);
		}
		
		/**
		 * Returns the validation rules of a given entity
		 * @param object $entity
		 * @return array
		 */
		public function getValidationRules(object $entity): array {
			$validate = new EntityToValidation();
			return $validate->convert($entity);
		}
		
		/**
		 * Returns the default window size (for pagination)
		 * @return int|null
		 */
		public function getDefaultWindowSize(): ?int {
			return $this->configuration->getDefaultWindowSize();
		}
	}