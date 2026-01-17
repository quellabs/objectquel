<?php
	
	namespace Quellabs\ObjectQuel\Persistence;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
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
		private UnitOfWork $unitOfWork;
		
		/**
		 * The EntityStore that maintains metadata about entities and their mappings
		 * Used to retrieve information about entity tables, columns and identifiers
		 */
		private EntityStore $entityStore;
		
		/**
		 * Database connection adapter used for executing SQL queries
		 * Abstracts the underlying database system and provides a unified interface
		 */
		private DatabaseAdapter $connection;
		
		/**
		 * Handles values with @Orm\Version annotations
		 * @var VersionValueHandler
		 */
		private VersionValueHandler $valueHandler;
		
		/**
		 * UpdatePersister constructor
		 * @param UnitOfWork $unitOfWork The UnitOfWork that will coordinate update operations
		 */
		public function __construct(UnitOfWork $unitOfWork) {
			$this->unitOfWork = $unitOfWork;
			$this->entityStore = $unitOfWork->getEntityStore();
			$this->connection = $unitOfWork->getConnection();
			$this->valueHandler = new VersionValueHandler($unitOfWork->getConnection(), $unitOfWork->getEntityStore(), $unitOfWork, $unitOfWork->getPropertyHandler());
		}
		
		/**
		 * Persists changes to an entity into the database
		 * @param object $entity The entity to be updated in the database
		 * @return void
		 * @throws OrmException If the database query fails or version mismatch is detected
		 */
		public function persist(object $entity): void {
			// Retrieve basic information needed for the update
			// Get the table name where the entity is stored
			$tableName = $this->valueHandler->escapeIdentifier($this->entityStore->getOwningTable($entity));
			
			// Serialize the entity's current state into an array of column name => value pairs
			$serializedEntity = $this->unitOfWork->getSerializer()->serialize($entity);
			
			// Get the entity's original data (snapshot) from when it was loaded or last persisted
			$originalData = $this->unitOfWork->getOriginalEntityData($entity);
			
			// Get the column names that make up the primary key
			$primaryKeyColumnNames = $this->entityStore->getIdentifierColumnNames($entity);
			
			// Get the version column names. These will auto update
			$versionColumns = $this->entityStore->getVersionColumnNames($entity);
			$versionColumnNames = array_column($versionColumns, 'name');
			
			// Extract the primary key values from the original data
			// These will be used in the WHERE clause to identify the record to update
			$primaryKeyValues = array_intersect_key($originalData, array_flip($primaryKeyColumnNames));
			
			// Extract only fields that have actually changed (excluding version columns)
			$changedFields = $this->extractChangedFields($serializedEntity, $originalData, $primaryKeyColumnNames, $versionColumnNames);
			
			// Build the complete UPDATE statement components
			$params = [];
			
			// Build SET clause for version columns and regular changed fields
			$setClauseParts = array_merge(
				$this->buildVersionSetClause($versionColumns, $params),
				$this->buildFieldsSetClause($changedFields, $params)
			);
			
			// Build WHERE clause with primary keys and version checks for optimistic locking
			$whereClause = $this->buildWhereClause($primaryKeyColumnNames, $primaryKeyValues, $versionColumns, $originalData, $params);
			
			// Build query
			$query = "UPDATE {$tableName} SET " . implode(", ", $setClauseParts) . " WHERE {$whereClause}";
			
			// Execute the UPDATE query with the merged parameters
			$rs = $this->connection->Execute($query, $params);
			
			// If the query fails, throw an exception with error details
			if (!$rs) {
				throw new OrmException($this->connection->getLastErrorMessage(), $this->connection->getLastError());
			}
			
			// Check if the update actually affected a row
			// If 0 rows were affected, it means either:
			// 1. The record was deleted by another process, or
			// 2. The version number changed (concurrent modification - race condition)
			if ($rs->rowCount() === 0) {
				throw new OrmException(
					"Version mismatch detected: The entity was modified by another process. " .
					"Expected version: " . json_encode(array_intersect_key($originalData, array_flip($versionColumnNames)))
				);
			}
			
			// Fetch version values from the database (if any)
			$fetchedDatetimeValues = $this->valueHandler->fetchUpdatedVersionValues(
				$tableName,
				$versionColumns,
				$primaryKeyColumnNames,
				$primaryKeyValues,
			);
			
			// Update the entity with the new version values so the in-memory object
			// matches the database state and can be used for subsequent operations
			$this->valueHandler->updateEntityVersionValues($entity, $fetchedDatetimeValues);
		}
		
		/**
		 * Extracts only the fields that have changed compared to the original entity data
		 * Version columns are excluded as they are handled separately
		 * @param array $serializedEntity Current entity state as column => value pairs
		 * @param array $originalData Original entity snapshot
		 * @param array $primaryKeyColumnNames Primary key column names
		 * @param array $versionColumnNames Version column names to exclude
		 * @return array Changed fields as column => value pairs
		 */
		protected function extractChangedFields(array $serializedEntity, array $originalData, array $primaryKeyColumnNames, array $versionColumnNames): array {
			// Create a list of changed fields by comparing current values with original values
			// We exclude version columns as they are handled separately with special auto-increment logic
			return array_filter($serializedEntity, function ($value, $key) use ($originalData, $primaryKeyColumnNames, $versionColumnNames) {
				// Skip version tagged columns. These will be handled separately
				if (in_array($key, $versionColumnNames)) {
					return false;
				}
				
				// Use primary keys and changed data. Skip the rest.
				return in_array($key, $primaryKeyColumnNames) || ($value != $originalData[$key]);
			}, ARRAY_FILTER_USE_BOTH);
		}
		
		/**
		 * Builds the SET clause for version columns
		 * Each version column type (integer, datetime, uuid) has different update logic
		 * @param array $versionColumns Version column metadata indexed by property name
		 * @param array &$params Reference to parameters array to add version parameters to
		 * @return array Array of SQL SET clause parts
		 * @throws OrmException
		 */
		protected function buildVersionSetClause(array $versionColumns, array &$params): array {
			$setClauseParts = [];
			
			// Process each version column according to its type
			foreach ($versionColumns as $property => $versionColumn) {
				$columnName = $this->valueHandler->escapeIdentifier($versionColumn['name']);
				
				switch($versionColumn['column']->getType()) {
					case 'integer':
						// Integer versions increment by 1
						$setClauseParts[] = "{$columnName}={$columnName} + 1";
						break;
					
					case 'datetime':
						// Datetime versions use the database's current timestamp
						$setClauseParts[] = "{$columnName}=NOW()";
						break;
					
					case 'uuid':
						// UUID versions get a new generated GUID
						$paramName = "version_{$versionColumn['name']}";
						$setClauseParts[] = "{$columnName}=:{$paramName}";
						$params[$paramName] = \Quellabs\Support\Tools::createUUIDv7();
						break;
					
					default:
						throw new OrmException("Invalid column type '{$versionColumn['column']->getType()}' for Version annotation on property '{$property}'");
				}
			}
			
			return $setClauseParts;
		}
		
		/**
		 * Builds the SET clause for regular changed fields
		 * Each field gets a prefixed parameter name to avoid collisions with other parameters
		 * @param array $changedFields Changed fields as column => value pairs
		 * @param array &$params Reference to parameters array to add field parameters to
		 * @return array Array of SQL SET clause parts
		 */
		protected function buildFieldsSetClause(array $changedFields, array &$params): array {
			// Add the regular changed fields to the SET clause
			// Each field gets a prefixed parameter name to avoid collisions
			$setClauseParts = [];
			foreach ($changedFields as $columnName => $value) {
				$paramName = "field_{$columnName}";
				$setClauseParts[] = $this->valueHandler->escapeIdentifier($columnName) . "=:{$paramName}";
				$params[$paramName] = $value;
			}
			
			return $setClauseParts;
		}
		
		/**
		 * Builds the WHERE clause for the UPDATE statement
		 * Includes primary key conditions and version column conditions for optimistic locking
		 * @param array $primaryKeyColumnNames Primary key column names
		 * @param array $primaryKeyValues Primary key values
		 * @param array $versionColumns Version column metadata
		 * @param array $originalData Original entity data for version values
		 * @param array &$params Reference to parameters array to add WHERE parameters to
		 * @return string Complete WHERE clause SQL
		 */
		protected function buildWhereClause(array $primaryKeyColumnNames, array $primaryKeyValues, array $versionColumns, array $originalData, array &$params): string {
			$whereClauseParts = [];
			
			// Build the WHERE clause to target the specific record
			// This includes primary key columns to identify the record
			foreach ($primaryKeyColumnNames as $columnName) {
				$paramName = "pk_{$columnName}";
				$whereClauseParts[] = $this->valueHandler->escapeIdentifier($columnName) . "=:{$paramName}";
				$params[$paramName] = $primaryKeyValues[$columnName];
			}
			
			// Add version columns to WHERE clause for optimistic locking
			// If the version in the database doesn't match our original snapshot,
			// the UPDATE will affect 0 rows, indicating a concurrent modification
			foreach ($versionColumns as $property => $versionColumn) {
				$columnName = $versionColumn['name'];
				$paramName = "where_version_{$columnName}";
				$whereClauseParts[] = $this->valueHandler->escapeIdentifier($columnName) . "=:{$paramName}";
				
				// Use the original version value from our snapshot
				$params[$paramName] = $originalData[$columnName];
			}
			
			// Combine all WHERE clause parts
			return implode(" AND ", $whereClauseParts);
		}
	}