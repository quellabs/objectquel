<?php
	
	namespace Quellabs\ObjectQuel\Persistence;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ReflectionManagement\PropertyHandler;
	use Quellabs\ObjectQuel\UnitOfWork;
	
	class VersionValueHandler {
		
		/**
		 * The EntityStore that maintains metadata about entities and their mappings
		 * Used to retrieve information about entity tables, columns and identifiers
		 */
		private EntityStore $entity_store;
		
		/**
		 * Reference to the UnitOfWork that manages persistence operations
		 */
		private UnitOfWork $unit_of_work;
		
		/**
		 * Utility for handling entity property access and manipulation
		 * Provides methods to get and set entity properties regardless of their visibility
		 */
		private PropertyHandler $property_handler;
		
		/**
		 * Database connection adapter used for executing SQL queries
		 * Abstracts the underlying database system and provides a unified interface
		 */
		private DatabaseAdapter $connection;
		
		/**
		 * Constructor
		 * @param DatabaseAdapter $connection
		 * @param EntityStore $entityStore
		 * @param UnitOfWork $unitOfWork
		 * @param PropertyHandler $property_handler
		 */
		public function __construct(DatabaseAdapter $connection, EntityStore $entityStore, UnitOfWork $unitOfWork, PropertyHandler $property_handler) {
			$this->connection = $connection;
			$this->entity_store = $entityStore;
			$this->unit_of_work = $unitOfWork;
			$this->property_handler = $property_handler;
		}
		
		/**
		 * Fetches version values back from the database after update
		 * Required to ensure in-memory entity matches database state exactly
		 * @param string $tableName Escaped table name
		 * @param array $versionColumns All version column metadata
		 * @param array $primaryKeyColumnNames Primary key column names
		 * @param array $primaryKeyValues Primary key values
		 * @return array Fetched version values as property_name => value pairs
		 */
		public function fetchUpdatedVersionValues(string $tableName, array $versionColumns, array $primaryKeyColumnNames, array $primaryKeyValues): array {
			// Do nothing when no version columns exist
			if (empty($versionColumns)) {
				return [];
			}
			
			// Build a SELECT query to retrieve all version columns
			$selectColumns = array_map(fn($vc) => $this->escapeIdentifier($vc['name']), $versionColumns);
			
			// Build WHERE clause using only primary keys to identify the row we just updated
			$whereClauseParts = [];
			$selectParams = [];
			
			foreach ($primaryKeyColumnNames as $columnName) {
				$paramName = "pk_{$columnName}";
				$whereClauseParts[] = $this->escapeIdentifier($columnName) . "=:{$paramName}";
				$selectParams[$paramName] = $primaryKeyValues[$columnName];
			}
			
			// Build select query
			$selectSql = "SELECT " . implode(", ", $selectColumns) . " FROM {$tableName} WHERE " . implode(" AND ", $whereClauseParts);
			
			// Execute select query
			$result = $this->connection->Execute($selectSql, $selectParams);
			
			// Collect fetched datetime values
			if (!$result || !($row = $result->fetchAssoc())) {
				return [];
			}
			
			return array_map(function ($vc) use ($row) {
				return $row[$vc['name']];
			}, $versionColumns);
		}
		
		/**
		 * Updates the entity with new version values from the database
		 * @param object $entity The entity to update
		 * @param array $fetchedValues Fetched version values as property_name => value pairs
		 * @return void
		 */
		public function updateEntityVersionValues(object $entity, array $fetchedValues): void {
			if (empty($fetchedValues)) {
				return;
			}
			
			$annotations = $this->entity_store->getAnnotations($entity, Column::class);
			
			foreach ($fetchedValues as $property => $newValue) {
				$normalizedValue = $this->unit_of_work->getSerializer()->normalizeValue($annotations[$property], $newValue);
				$this->property_handler->set($entity, $property, $normalizedValue);
			}
		}
		
		/**
		 * Escapes a database identifier (table or column name)
		 * @param string $identifier The identifier to escape
		 * @return string The escaped identifier wrapped in backticks
		 */
		public function escapeIdentifier(string $identifier): string {
			return '`' . str_replace('`', '``', $identifier) . '`';
		}
	}