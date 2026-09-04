<?php
	
	namespace Quellabs\ObjectQuel\Persistence;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\Version;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\OrmException;
	use Quellabs\ObjectQuel\ReflectionManagement\PropertyHandler;
	use Quellabs\ObjectQuel\UnitOfWork;
	use Quellabs\Support\Tools;

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
		 * Used to generate engine-appropriate SQL fragments (e.g. the correct
		 * "current datetime" expression) instead of hardcoding MySQL syntax.
		 * @var PlatformCapabilitiesInterface
		 */
		private PlatformCapabilitiesInterface $platformCapabilities;

		/**
		 * Quotes identifiers for buildVersionSetClause(). Deliberately
		 * SqlIdentifierQuoter rather than DatabaseAdapter::escapeIdentifier() —
		 * this method is also called from the QUEL `replace` compile path
		 * (QuelToSQLReplace), which has no live connection to escape through.
		 * For the plain, real column names version columns always are, the
		 * two quoting mechanisms produce identical output on every engine.
		 * @var SqlIdentifierQuoter
		 */
		private SqlIdentifierQuoter $identifierQuoter;

		/**
		 * Constructor
		 * @param DatabaseAdapter $connection
		 * @param EntityStore $entityStore
		 * @param UnitOfWork $unitOfWork
		 * @param PropertyHandler $propertyHandler
		 * @param PlatformCapabilitiesInterface $platformCapabilities
		 */
		public function __construct(
			DatabaseAdapter $connection,
			EntityStore $entityStore,
			UnitOfWork $unitOfWork,
			PropertyHandler $propertyHandler,
			PlatformCapabilitiesInterface $platformCapabilities
		) {
			$this->connection = $connection;
			$this->entityStore = $entityStore;
			$this->unitOfWork = $unitOfWork;
			$this->propertyHandler = $propertyHandler;
			$this->platformCapabilities = $platformCapabilities;
			$this->identifierQuoter = new SqlIdentifierQuoter($platformCapabilities);
		}

		/**
		 * Builds the SET clause fragments that bump a set of `@Orm\Version`
		 * columns — each version column type (integer, datetime, uuid) has
		 * its own bump logic. Shared by UpdatePersister (object-persistence
		 * UPDATE) and QuelToSQLReplace (QUEL-level `replace`), so version
		 * columns bump identically on both paths instead of the QUEL path
		 * silently skipping them.
		 * @param array<string, array{name: string, column: Column, version: Version}> $versionColumns
		 * @param array<string, mixed> $params Reference to parameters array to add version parameters to
		 * @return array<int, string> Array of SQL SET clause parts
		 * @throws OrmException
		 * @throws \Exception
		 */
		public function buildVersionSetClause(array $versionColumns, array &$params): array {
			$setClauseParts = [];

			// Process each version column according to its type
			foreach ($versionColumns as $property => $versionColumn) {
				$columnName = $this->identifierQuoter->quoteIdentifier($versionColumn['name']);

				switch ($versionColumn['column']->getType()) {
					case 'integer':
					case 'bigint':
						// Integer/bigint versions increment by 1
						$setClauseParts[] = "{$columnName}={$columnName} + 1";
						break;

					case 'datetime':
						// Use the engine-appropriate "current datetime" expression rather
						// than hardcoding MySQL's NOW() — SQLite and SQL Server use
						// different syntax for this.
						$setClauseParts[] = "{$columnName}=" . $this->platformCapabilities->getCurrentDatetimeFunction();
						break;

					case 'uuid':
						// UUID versions get a new generated GUID
						$paramName = "version_{$versionColumn['name']}";
						$setClauseParts[] = "{$columnName}=:{$paramName}";
						$params[$paramName] = Tools::createUUIDv7();
						break;

					default:
						throw new OrmException("Invalid column type '{$versionColumn['column']->getType()}' for Version annotation on property '{$property}'");
				}
			}

			return $setClauseParts;
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
			$selectColumns = array_map(fn($vc) => $this->connection->escapeIdentifier($vc['name']), $versionColumns);
			
			// Build the WHERE clause and parameter list from the primary keys
			// Parameters are prefixed with "pk_" to avoid collisions with version column names
			$whereClauseParts = [];
			$selectParams = [];
			
			foreach ($primaryKeyColumnNames as $columnName) {
				$paramName = "pk_{$columnName}";
				$whereClauseParts[] = $this->connection->escapeIdentifier($columnName) . "=:{$paramName}";
				$selectParams[$paramName] = $primaryKeyValues[$columnName];
			}
			
			// Assemble query
			$selectSql = "SELECT " . implode(", ", $selectColumns) . " FROM " . $this->connection->escapeIdentifier($tableName) . " WHERE " . implode(" AND ", $whereClauseParts);
			
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
		 * @throws EntityResolutionException
		 */
		public function updateEntityVersionValues(object $entity, array $fetchedValues): void {
			// Nothing to do if the insert/update produced no version values
			if (empty($fetchedValues)) {
				return;
			}
			
			// Fetch Column annotations so the serializer can normalize each raw database value
			// to the correct PHP type (e.g. datetime string → DateTimeImmutable)
			$metadata = $this->entityStore->getMetadata($entity);
			$annotations = $metadata->getAnnotationsOfType(Column::class);
			
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
	}