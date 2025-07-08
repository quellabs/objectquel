<?php
	
	namespace Quellabs\ObjectQuel\Persistence;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\databaseAdapter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\OrmException;
	use Quellabs\ObjectQuel\ReflectionManagement\PropertyHandler;
	use Quellabs\ObjectQuel\UnitOfWork;
	
	/**
	 * Specialized persister class responsible for updating existing entities in the database
	 * Extends the PersisterBase to inherit common persistence functionality
	 * This class handles the process of detecting and persisting changes to existing entities
	 */
	class UpdatePersister {
		
		/**
		 * Reference to the UnitOfWork that manages persistence operations
		 * This is a duplicate of the parent's unitOfWork property with a different naming convention
		 */
		protected UnitOfWork $unit_of_work;
		
		/**
		 * The EntityStore that maintains metadata about entities and their mappings
		 * Used to retrieve information about entity tables, columns and identifiers
		 */
		protected EntityStore $entity_store;
		
		/**
		 * Utility for handling entity property access and manipulation
		 * Provides methods to get and set entity properties regardless of their visibility
		 */
		protected PropertyHandler $property_handler;
		
		/**
		 * Database connection adapter used for executing SQL queries
		 * Abstracts the underlying database system and provides a unified interface
		 */
		protected databaseAdapter $connection;
		
		/**
		 * UpdatePersister constructor
		 * @param UnitOfWork $unitOfWork The UnitOfWork that will coordinate update operations
		 */
		public function __construct(UnitOfWork $unitOfWork) {
			$this->unit_of_work = $unitOfWork;
			$this->entity_store = $unitOfWork->getEntityStore();
			$this->property_handler = $unitOfWork->getPropertyHandler();
			$this->connection = $unitOfWork->getConnection();
		}
		
		/**
		 * Takes an array, adds a prefix to all keys, and returns the new, modified array
		 * This is used to prevent parameter name collisions in SQL prepared statements
		 * @param array $array The original array with keys to be prefixed
		 * @param string $prefix The prefix to add to each key
		 * @return array The new array with prefixed keys and original values
		 */
		protected function prefixKeys(array $array, string $prefix): array {
			$newArray = [];
			
			foreach ($array as $key => $value) {
				$newKey = $prefix . $key;
				$newArray[$newKey] = $value;
			}
			
			return $newArray;
		}
		
		/**
		 * Persists changes to an entity into the database
		 * This method handles the complete update process including:
		 * - Detecting which fields have changed
		 * - Building and executing the UPDATE SQL statement
		 *
		 * @param object $entity The entity to be updated in the database
		 * @return void
		 * @throws OrmException If the database query fails
		 */
		public function persist(object $entity): void {
			// Retrieve basic information needed for the update
			// Get the table name where the entity is stored
			$tableName = $this->entity_store->getOwningTable($entity);
			
			// Serialize the entity's current state into an array of column name => value pairs
			$serializedEntity = $this->unit_of_work->getSerializer()->serialize($entity);
			
			// Get the entity's original data (snapshot) from when it was loaded or last persisted
			$originalData = $this->unit_of_work->getOriginalEntityData($entity);
			
			// Get the column names that make up the primary key
			$primaryKeyColumnNames = $this->entity_store->getIdentifierColumnNames($entity);
			
			// Extract the primary key values from the original data
			// These will be used in the WHERE clause to identify the record to update
			$primaryKeyValues = array_intersect_key($originalData, array_flip($primaryKeyColumnNames));
			
			// Create a list of changed fields by comparing current values with original values
			// We include primary keys (for the SET clause) and any values that have changed
			$extractedEntityChanges = array_filter($serializedEntity, function ($value, $key) use ($originalData, $primaryKeyColumnNames) {
				return (in_array($key, $primaryKeyColumnNames) || ($value != $originalData[$key]));
			}, ARRAY_FILTER_USE_BOTH);
			
			// Build and execute the SQL query
			// Create the SET clause with column=:param pairs for each changed field
			$sql = implode(",", array_map(fn($key) => "`{$key}`=:{$key}", array_keys($extractedEntityChanges)));
			
			// Create the WHERE clause to target the specific record using its primary keys
			$sqlWhere = implode(" AND ", array_map(fn($key) => "`{$key}`=:primary_key_{$key}", $primaryKeyColumnNames));
			
			// Merge the changed values and prefixed primary key values into a single parameter array
			// The primary key values are prefixed to avoid name collisions with SET parameters
			$mergedParams = array_merge($extractedEntityChanges, $this->prefixKeys($primaryKeyValues, "primary_key_"));
			
			// Execute the UPDATE query with the merged parameters
			$rs = $this->connection->Execute("
                UPDATE `{$tableName}` SET
                    {$sql}
                WHERE {$sqlWhere}
            ", $mergedParams);
			
			// If the query fails, throw an exception with error details
			if (!$rs) {
				throw new OrmException($this->connection->getLastErrorMessage(), $this->connection->getLastError());
			}
		}
	}