<?php
	
	namespace Quellabs\ObjectQuel\Persistence;
	
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\OrmException;
	use Quellabs\ObjectQuel\PrimaryKeys\PrimaryKeyFactory;
	use Quellabs\ObjectQuel\ReflectionManagement\PropertyHandler;
	use Quellabs\ObjectQuel\UnitOfWork;
	
	/**
	 * Specialized persister class responsible for inserting new entities into the database
	 * Extends the PersisterBase to inherit common persistence functionality
	 * This class handles the creation process of inserting entities into database tables
	 */
	class InsertPersister {
		
		/**
		 * Reference to the EntityManager
		 * @var EntityManager
		 */
		private EntityManager $entity_manager;
		
		/**
		 * The EntityStore that maintains metadata about entities and their mappings
		 * Used to retrieve information about entity tables, columns and identifiers
		 */
		protected EntityStore $entity_store;
		
		/**
		 * Reference to the UnitOfWork that manages persistence operations
		 * This is a duplicate of the parent's unitOfWork property with a different naming convention
		 */
		protected UnitOfWork $unit_of_work;
		
		/**
		 * Utility for handling entity property access and manipulation
		 * Provides methods to get and set entity properties regardless of their visibility
		 */
		protected PropertyHandler $property_handler;
		
		/**
		 * Database connection adapter used for executing SQL queries
		 * Abstracts the underlying database system and provides a unified interface
		 */
		protected DatabaseAdapter $connection;
		
		/**
		 * Factory for creating primary key values
		 * @var PrimaryKeyFactory
		 */
		private PrimaryKeyFactory $primary_key_factory;
		
		/**
		 * @var array Cache for primary key strategy fetcher
		 */
		private array $strategy_column_cache;
		
		/**
		 * InsertPersister constructor
		 * @param UnitOfWork $unitOfWork The UnitOfWork that will coordinate insertion operations
		 * @param PrimaryKeyFactory|null $factory Factory for creating primary keys
		 */
		public function __construct(UnitOfWork $unitOfWork, ?PrimaryKeyFactory $factory=null) {
			$this->unit_of_work = $unitOfWork;
			$this->entity_manager = $unitOfWork->getEntityManager();
			$this->entity_store = $unitOfWork->getEntityStore();
			$this->property_handler = $unitOfWork->getPropertyHandler();
			$this->connection = $unitOfWork->getConnection();
			$this->primary_key_factory = $factory ?? new PrimaryKeyFactory();
			$this->strategy_column_cache = [];
		}
		
		/**
		 * Persists (inserts) an entity into the database
		 * @param object $entity The entity to be inserted into the database
		 * @throws OrmException If the database query fails
		 */
		public function persist(object $entity): void {
			// Gather the necessary information for the insert operation
			// Get the table name where the entity should be stored
			$tableName = $this->entity_store->getOwningTable($entity);
			
			// Get the primary key property names and their corresponding column names
			$primaryKeys = $this->entity_store->getIdentifierKeys($entity);

			// Iterate through each identified primary key for the entity
			foreach($primaryKeys as $primaryKey) {
				// First check if the primary key already has a value
				// This prevents overwriting manually set primary keys
				$currentValue = $this->property_handler->get($entity, $primaryKey);
				
				// Only generate a new primary key if the current value is null or an empty string
				// This respects existing values while ensuring all primary keys have values
				if ($currentValue === null || $currentValue === '') {
					// Determine the primary key generation strategy for this specific primary key field
					// (e.g., 'uuid', 'identity', 'sequence') - only done when needed
					$strategy = $this->getPrimaryKeyStrategy($entity, $primaryKey);
					
					// Generate a new primary key value using the appropriate generator
					// Passes context (entity manager and entity) for generators that need it
					$value = $this->primary_key_factory->generate($this->entity_manager, $entity, $strategy);
					
					// Update the entity with the newly generated primary key value
					// Uses the property handler to respect access rules for private/protected properties
					if ($value !== null) {
						$this->property_handler->set($entity, $primaryKey, $value);
					}
				}
			}
			
			// Serialize the entity into an array of column name => value pairs
			$serializedEntity = $this->unit_of_work->getSerializer()->serialize($entity);
			
			// Create the SQL query for insertion
			// Generates a comma-separated list of "column=:value" pairs for the SET clause
			$sql = implode(",", array_map(fn($key) => "`{$key}`=:{$key}", array_keys($serializedEntity)));
			
			// Execute the insert query with the serialized entity data as parameters
			$rs = $this->connection->Execute("INSERT INTO `{$tableName}` SET {$sql}", $serializedEntity);
			
			// If the query fails, throw an exception with the error details
			if (!$rs) {
				throw new OrmException($this->connection->getLastErrorMessage(), $this->connection->getLastError());
			}

			// After successful query execution, check if the entity has a primary key with identity/auto-increment strategy
			// This identifies columns marked either with @PrimaryKeyStrategy(strategy="identity") or primary keys with no strategy
			$autoincrementColumn = $this->entity_store->findAutoIncrementPrimaryKey($entity);
			
			if ($autoincrementColumn !== null) {
				// Entity has an identity primary key column that should receive the auto-generated ID from the database
				// Get the last inserted ID value from the database connection
				$autoIncrementId = $this->connection->getInsertId();
				
				if ($autoIncrementId !== 0) {
					// Non-zero ID was returned, indicating the database successfully generated a new primary key value
					// Update the entity's property with the database-generated ID
					// This ensures the entity's state is synchronized with its database representation
					$this->property_handler->set($entity, $autoincrementColumn, $autoIncrementId);
				}
				
				// If the auto-increment ID is 0, it may indicate no new ID was generated
				// (possibly due to a transaction rollback or other database condition)
			}
		}
		
		/**
		 * Retrieves the primary key generation strategy for a given entity and primary key.
		 * @param object $entity      The entity object to examine
		 * @param string $primaryKey  The name of the primary key field
		 * @return string             The primary key strategy value
		 */
		protected function getPrimaryKeyStrategy(object $entity, string $primaryKey): string {
			$table = $this->entity_store->getOwningTable($entity);
			
			// Fetch key from cache if present
			if (array_key_exists("$table.$primaryKey", $this->strategy_column_cache)) {
				return $this->strategy_column_cache["$table.$primaryKey"];
			}
			
			// Get all annotations for the entity from the entity store
			$annotations = $this->entity_store->getAnnotations($entity);
			
			// If no annotations exist for the specified primary key, return "identity"
			// This should never happen as we already established that there's a primary key
			if (empty($annotations[$primaryKey])) {
				return "identity";
			}
			
			// Iterate through all annotations for the primary key
			foreach($annotations[$primaryKey] as $annotation) {
				// Check if the current annotation is a PrimaryKeyStrategy instance
				if ($annotation instanceof PrimaryKeyStrategy) {
					// Return the value of the PrimaryKeyStrategy annotation
					$this->strategy_column_cache["$table.$primaryKey"] = $annotation->getValue();
					return $annotation->getValue();
				}
			}
			
			// No PrimaryKeyStrategy annotation found for this primary key
			return $this->strategy_column_cache["$table.$primaryKey"] = "identity";
		}
	}