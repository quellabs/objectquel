<?php
	
	namespace Quellabs\ObjectQuel\Persistence;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\Version;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ReflectionManagement\PropertyHandler;
	use Quellabs\ObjectQuel\UnitOfWork;
	
	class VersionValueHandler {
		
		/**
		 * The EntityStore that maintains metadata about entities and their mappings
		 * Used to retrieve information about entity tables, columns and identifiers
		 */
		private EntityStore $entityStore;
		
		/**
		 * Reference to the UnitOfWork that manages persistence operations
		 */
		private UnitOfWork $unitOfWork;
		
		/**
		 * Utility for handling entity property access and manipulation
		 * Provides methods to get and set entity properties regardless of their visibility
		 */
		private PropertyHandler $propertyHandler;
		
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
		 * @param PropertyHandler $propertyHandler
		 */
		public function __construct(DatabaseAdapter $connection, EntityStore $entityStore, UnitOfWork $unitOfWork, PropertyHandler $propertyHandler) {
			$this->connection = $connection;
			$this->entityStore = $entityStore;
			$this->unitOfWork = $unitOfWork;
			$this->propertyHandler = $propertyHandler;
		}
		
		/**
		 * Fetches version values back from the database after update
		 * Required to ensure in-memory entity matches database state exactly
		 * @param string $tableName Raw (unescaped) table name
		 * @param array<string, array{name: string, column: Column, version: Version}> $versionColumns All version column metadata
		 * @param array<int, string> $primaryKeyColumnNames Primary key column names
		 * @param array<string, mixed> $primaryKeyValues Primary key values
		 * @return array<string, mixed> Fetched version values as property_name => value pairs
		 */
		public function fetchUpdatedVersionValues(string $tableName, array $versionColumns, array $primaryKeyColumnNames, array $primaryKeyValues): array {
			// Nothing to fetch if this entity has no version columns
			if (empty($versionColumns)) {
				return [];
			}
			
			// Build the SELECT column list from the version column names
			$selectColumns = array_map(fn($vc) => $this->escapeIdentifier($vc['name']), $versionColumns);
			
			// Build the WHERE clause and parameter list from the primary keys
			// Parameters are prefixed with "pk_" to avoid collisions with version column names
			$whereClauseParts = [];
			$selectParams = [];
			
			foreach ($primaryKeyColumnNames as $columnName) {
				$paramName = "pk_{$columnName}";
				$whereClauseParts[] = $this->escapeIdentifier($columnName) . "=:{$paramName}";
				$selectParams[$paramName] = $primaryKeyValues[$columnName];
			}
			
			// Assemble query
			$selectSql = "SELECT " . implode(", ", $selectColumns) . " FROM " . $this->escapeIdentifier($tableName) . " WHERE " . implode(" AND ", $whereClauseParts);
			
			// Execute query
			$result = $this->connection->Execute($selectSql, $selectParams);
			
			// Return empty if the query failed or the row has already been removed
			if (!$result || !($row = $result->fetchAssoc())) {
				return [];
			}
			
			// Map each version column back to its fetched value, keyed by property name
			$resultValues = [];
			
			/** @noinspection PhpLoopCanBeConvertedToArrayMapInspection */
			foreach ($versionColumns as $property => $vc) {
				$resultValues[$property] = $row[$vc['name']];
			}
			
			return $resultValues;
		}
		
		/**
		 * Updates the entity with new version values from the database
		 * @param object $entity The entity to update
		 * @param array<string, mixed> $fetchedValues Fetched version values as property_name => value pairs
		 * @return void
		 */
		public function updateEntityVersionValues(object $entity, array $fetchedValues): void {
			// Nothing to do if the insert/update produced no version values
			if (empty($fetchedValues)) {
				return;
			}
			
			// Fetch Column annotations so the serializer can normalize each raw database value
			// to the correct PHP type (e.g. datetime string → DateTimeImmutable)
			$annotations = $this->entityStore->getAnnotationsOfType($entity, Column::class);
			
			foreach ($fetchedValues as $property => $newValue) {
				// Fetch first column annotation
				$columnAnnotation = $annotations[$property][0] ?? null;
				
				// If none found, continue to the next
				if ($columnAnnotation === null) {
					continue;
				}
				
				// Normalize the raw database value to its PHP representation
				$normalizedValue = $this->unitOfWork->getSerializer()->normalizeValue($columnAnnotation, $newValue);
				
				// Write it back
				$this->propertyHandler->set($entity, $property, $normalizedValue);
			}
		}
		
		/**
		 * Escapes a database identifier (table or column name)
		 * @param string $identifier The identifier to escape
		 * @return string The escaped identifier wrapped in backticks
		 */
		public function escapeIdentifier(string $identifier): string {
			// Wrap in backticks and double any internal backticks to produce a valid MySQL identifier
			return '`' . str_replace('`', '``', $identifier) . '`';
		}
	}