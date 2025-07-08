<?php
	
	namespace Quellabs\ObjectQuel\Persistence;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\OrmException;
	use Quellabs\ObjectQuel\ReflectionManagement\PropertyHandler;
	use Quellabs\ObjectQuel\UnitOfWork;
	
	/**
	 * Specialized persister class responsible for handling entity deletion operations
	 * Extends the PersisterBase to inherit common persistence functionality
	 * This class specifically manages the process of removing entities from the database
	 */
	class DeletePersister {
		
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
		protected DatabaseAdapter $connection;
		
		/**
		 * DeletePersister constructor
		 * Initializes all necessary components for entity deletion operations
		 * @param UnitOfWork $unitOfWork The UnitOfWork that will coordinate deletion operations
		 */
		public function __construct(UnitOfWork $unitOfWork) {
			$this->unit_of_work = $unitOfWork;
			$this->entity_store = $unitOfWork->getEntityStore();
			$this->property_handler = $unitOfWork->getPropertyHandler();
			$this->connection = $unitOfWork->getConnection();
		}
		
		/**
		 * Extracts primary key values from an entity into a column-to-value mapping
		 * This mapping is used to build the WHERE clause for the DELETE statement
		 * @param object $entity The entity from which to extract primary key values
		 * @param array $primaryKeys The property names that represent primary keys in the entity
		 * @param array $primaryKeyColumns The corresponding database column names for the primary keys
		 * @return array Associative array with column names as keys and their values from the entity
		 */
		private function extractPrimaryKeyValueMap(object $entity, array $primaryKeys, array $primaryKeyColumns): array {
			$result = [];
			
			foreach($primaryKeys as $index => $key) {
				$result[$primaryKeyColumns[$index]] = $this->property_handler->get($entity, $key);
			}
			
			return $result;
		}
		
		/**
		 * Deletes an entity from the database based on its primary keys
		 * This function first retrieves the necessary table and key information and then
		 * constructs a DELETE SQL query to remove the specific entity
		 * @param object $entity The entity to be removed from the database
		 * @throws OrmException If the DELETE operation fails, an exception is thrown
		 */
		public function persist(object $entity): void {
			// Get the name of the table where the entity is stored
			$tableName = $this->entity_store->getOwningTable($entity);
			
			// Obtain the primary keys and corresponding column names of the entity
			$primaryKeys = $this->entity_store->getIdentifierKeys($entity);
			$primaryKeyColumns = $this->entity_store->getIdentifierColumnNames($entity);
			
			// Create a mapping of primary key column names to their values for this specific entity
			$primaryKeyValues = $this->extractPrimaryKeyValueMap($entity, $primaryKeys, $primaryKeyColumns);
			
			// Construct the SQL query for deleting the entity, using each primary key value
			// in the WHERE clause to target this specific entity
			// Uses `AND` to ensure all conditions must match
			$sql = implode(" AND ", array_map(fn($key) => "`{$key}`=:{$key}", array_keys($primaryKeyValues)));
			
			// Execute the DELETE query with the constructed conditions
			// Use the primary key values as parameters for the prepared statement to prevent SQL injection
			if (!$this->connection->execute("DELETE FROM `{$tableName}` WHERE {$sql}", $primaryKeyValues)) {
				// If execution fails, throw an exception with the last error message and error code
				// from the database connection to help identify and resolve the issue
				throw new OrmException("Error deleting entity: " . $this->connection->getLastErrorMessage(), $this->connection->getLastError());
			}
		}
	}