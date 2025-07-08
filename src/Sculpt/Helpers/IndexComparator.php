<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\ObjectQuel\Annotations\Orm\UniqueIndex;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityStore;
	
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
		
		/**
		 * IndexComparator constructor
		 * @param DatabaseAdapter $connection
		 * @param EntityStore $entityStore
		 */
		public function __construct(DatabaseAdapter $connection, EntityStore $entityStore) {
			$this->connection = $connection;
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Compares database indexes with entity-defined indexes to find missing or inconsistent indexes
		 *
		 * This method identifies:
		 * - Indexes that exist in the database but not in the entity
		 * - Indexes that exist in the entity but not in the database
		 * - Indexes that exist in both but have different configurations
		 *
		 * @param mixed $entity The entity class to analyze
		 * @return array An array containing differences between DB and entity indexes
		 */
		public function compareIndexes(mixed $entity): array {
			// Fetch the owning table of this entity
			$tableName = $this->entityStore->getOwningTable($entity);
			
			// Get database indexes
			$tableIndexes = $this->getTableIndexes($tableName);
			
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
			
			// Any remaining DB indexes must be deleted
			$result['deleted'] = $tableIndexes;
			return $result;
		}
		
		
		/**
		 * Retrieves all database indexes defined for a specific table
		 * @param string $tableName The name of the database table to get indexes for
		 * @return array Formatted array of database indexes with their configurations
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
		 * @param mixed $entity The entity object or class to get indexes for
		 * @return array Formatted array of database indexes with their configurations
		 */
		public function getEntityIndexes(mixed $entity): array {
			// Fetch the column map
			$columnMap = $this->entityStore->getColumnMap($entity);
			
			// Iterate through all indexes defined for this entity
			$result = [];

			foreach ($this->entityStore->getIndexes($entity) as $annotation) {
				// Check if this is a unique index or a regular index
				$isUnique = $annotation instanceof UniqueIndex;
				
				// Get the entity property names that make up this index
				$columns = $annotation->getColumns();
				
				// Convert entity property names to their corresponding database column names
				$databaseColumns = [];
				foreach ($columns as $column) {
					$databaseColumns[] = $columnMap[$column];
				}
				
				// Build the index configuration array
				$result[$annotation->getName()] = [
					'columns' => $databaseColumns,    // Database column names for this index
					'type'    => $isUnique ? 'UNIQUE' : 'INDEX',  // Index type identifier
					'unique'  => $isUnique            // Boolean flag for uniqueness constraint
				];
			}
			
			return $result;
		}
		
		/**
		 * Compares two index configurations to check if they differ
		 * @param array $dbConfig Database index configuration
		 * @param array $entityConfig Entity index configuration
		 * @return bool True if configurations differ, false otherwise
		 */
		private function indexConfigDiffers(array $dbConfig, array $entityConfig): bool {
			// Check column count first (quick fail)
			if (count($dbConfig['columns']) !== count($entityConfig['columns'])) {
				return true;
			}
			
			// Check uniqueness (single boolean comparison)
			if ($dbConfig['unique'] !== $entityConfig['unique']) {
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