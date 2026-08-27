<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\ObjectQuel\Annotations\Orm\FullTextIndex;
	use Quellabs\ObjectQuel\Annotations\Orm\Index;
	use Quellabs\ObjectQuel\Annotations\Orm\UniqueIndex;
	use Quellabs\ObjectQuel\Capabilities\NullPlatformCapabilities;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Sculpt\SculptTypes;

	/**
	 * @phpstan-import-type IndexDefinition from SculptTypes
	 * @phpstan-import-type IndexChangeSet from SculptTypes
	 */
	class IndexComparator {

		/**
		 * Database connection / interface with cakephp/database and Phinx
		 * @var DatabaseAdapter
		 */
		private DatabaseAdapter $connection;

		/**
		 * EntityStore manages entity metadata and relations
		 * @var EntityStore
		 */
		private EntityStore $entityStore;

		/** @var PlatformCapabilitiesInterface Describes what the connected database engine supports */
		private PlatformCapabilitiesInterface $platform;

		/**
		 * IndexComparator constructor
		 * @param DatabaseAdapter $connection
		 * @param EntityStore $entityStore
		 * @param PlatformCapabilitiesInterface $platform Database engine capability descriptor
		 */
		public function __construct(DatabaseAdapter $connection, EntityStore $entityStore, PlatformCapabilitiesInterface $platform = new NullPlatformCapabilities()) {
			$this->connection = $connection;
			$this->entityStore = $entityStore;
			$this->platform = $platform;
		}
		
		/**
		 * Compares database indexes with entity-defined indexes to find missing or inconsistent indexes
		 *
		 * This method identifies:
		 * - Indexes that exist in the database but not in the entity
		 * - Indexes that exist in the entity but not in the database
		 * - Indexes that exist in both but have different configurations
		 *
		 * @param string|object $entity The entity class to analyze
		 * @return IndexChangeSet An array containing differences between DB and entity indexes
		 * @throws EntityResolutionException
		 * @throws \Exception
		 */
		public function compareIndexes(mixed $entity): array {
			// Fetch the metadata
			$metadata = $this->entityStore->getMetadata($entity);

			// Get database indexes
			$tableIndexes = $this->getTableIndexes($metadata->tableName);

			// Get entity indexes
			$entityIndexes = $this->getEntityIndexes($entity);

			// Early return if both are empty
			if (empty($tableIndexes) && empty($entityIndexes)) {
				return ['added' => [], 'modified' => [], 'deleted' => []];
			}

			// Initialize results arrays
			$result = [
				'added'    => [],
				'modified' => []
			];

			// Find missing and modified indexes in one pass through entity indexes
			foreach ($entityIndexes as $name => $config) {
				if (!isset($tableIndexes[$name])) {
					$result['added'][$name] = $config;
				} elseif ($this->indexConfigDiffers($tableIndexes[$name], $config)) {
					$result['modified'][$name] = [
						'database' => $tableIndexes[$name],
						'entity'   => $config
					];
				}

				// Mark as processed
				unset($tableIndexes[$name]);
			}

			// Remaining DB indexes are candidates for deletion, except ones a live FK
			// relies on: MySQL auto-creates a supporting index per FK that @Orm\Index
			// never declares, and dropping it while the constraint still needs it fails
			// outright. Whether the FK itself is kept, modified, or dropped is
			// ForeignKeyComparator's concern, not this one's.
			$fkBackedColumnSets = $this->getForeignKeyBackedColumnSets($metadata->tableName);
			$result['deleted'] = [];

			foreach ($tableIndexes as $name => $config) {
				if (!in_array($config['columns'], $fkBackedColumnSets, true)) {
					$result['deleted'][$name] = $config;
				}
			}

			return $result;
		}

		/**
		 * Returns the column lists of every live foreign key constraint on a table,
		 * so compareIndexes() can recognize an index that only exists to support one.
		 * Returns an empty list when the engine isn't introspectable (see
		 * PlatformCapabilitiesInterface::supportsForeignKeyIntrospection()), since an
		 * empty getForeignKeys() there means "unknown", not "there are none".
		 * @param string $tableName
		 * @return array<string, string[]>
		 */
		private function getForeignKeyBackedColumnSets(string $tableName): array {
			if (!$this->platform->supportsForeignKeyIntrospection()) {
				return [];
			}

			return array_map(
				static fn(array $foreignKey): array => $foreignKey['columns'],
				$this->connection->getForeignKeys($tableName)
			);
		}
		
		
		/**
		 * Retrieves all database indexes defined for a specific table
		 * @param string $tableName The name of the database table to get indexes for
		 * @return array<string, IndexDefinition> Formatted array of database indexes with their configurations
		 */
		public function getTableIndexes(string $tableName): array {
			return array_map(function ($index) {
				return [
					'columns' => $index['columns'],   // Array of column names included in this index
					'type'    => $index['type'],      // Original index type from database
					'unique'  => strtoupper($index['type']) === 'UNIQUE'  // Convert type to boolean flag for uniqueness
				];
			}, $this->connection->getIndexes($tableName));
		}
		
		/**
		 * Retrieves database index configurations for an entity
		 * @param string|object $entity The entity object or class to get indexes for
		 * @return array<string, IndexDefinition> Formatted array of database indexes with their configurations
		 * @throws \Exception
		 */
		public function getEntityIndexes(mixed $entity): array {
			// Fetch the metadata
			$metadata = $this->entityStore->getMetadata($entity);
			
			// Iterate through all indexes defined for this entity
			$result = [];
			
			foreach ($metadata->indexes as $annotation) {
				/** @var FullTextIndex|UniqueIndex|Index $annotation */
				// Determine the index type from the annotation class
				if ($annotation instanceof FullTextIndex) {
					$indexType = 'FULLTEXT';
				} elseif ($annotation instanceof UniqueIndex) {
					$indexType = 'UNIQUE';
				} else {
					$indexType = 'INDEX';
				}
				
				// Get the entity property names that make up this index
				$columns = $annotation->getColumns();
				
				// Convert entity property names to their corresponding database column names
				$databaseColumns = [];
				
				foreach ($columns as $column) {
					// If the indexed column does not exist, throw an error and abort migration creation
					if (!isset($metadata->columnMap[$column])) {
						throw new \Exception(
							"Index column '{$column}' on '{$metadata->tableName}' does not match any property name. " .
							"@Index columns must use PHP property names (e.g. 'customerId'), not database column names (e.g. 'customer_id')."
						);
					}
					
					$databaseColumns[] = $metadata->columnMap[$column];
				}
				
				// Build the index configuration array
				$result[$annotation->getName()] = [
					'columns' => $databaseColumns,
					'type'    => $indexType,
					'unique'  => $annotation instanceof UniqueIndex
				];
			}
			
			return $result;
		}
		
		/**
		 * Compares two index configurations to check if they differ
		 * @param IndexDefinition $dbConfig Database index configuration
		 * @param IndexDefinition $entityConfig Entity index configuration
		 * @return bool True if configurations differ, false otherwise
		 */
		private function indexConfigDiffers(array $dbConfig, array $entityConfig): bool {
			// Check column count first (quick fail)
			if (count($dbConfig['columns']) !== count($entityConfig['columns'])) {
				return true;
			}
			
			// Compare index type explicitly — this catches FULLTEXT vs INDEX vs UNIQUE
			if (strtoupper($dbConfig['type']) !== strtoupper($entityConfig['type'])) {
				return true;
			}
			
			// Sort arrays before comparing to ensure consistent order
			$dbColumns = $dbConfig['columns'];
			$entityColumns = $entityConfig['columns'];
			sort($dbColumns);
			sort($entityColumns);
			
			// Direct array comparison is faster than array_diff for sorted arrays
			return $dbColumns !== $entityColumns;
		}
	}