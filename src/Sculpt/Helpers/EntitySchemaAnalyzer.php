<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityStore;
	use RuntimeException;
	use InvalidArgumentException;
	
	/**
	 * EntitySchemaAnalyzer - Analyzes differences between entity definitions and database schema
	 * This class compares entity class definitions (properties, indexes) against the actual
	 * database schema to detect structural differences that need migration.
	 */
	class EntitySchemaAnalyzer {
		
		/** @var DatabaseAdapter Database connection adapter for querying schema information */
		private DatabaseAdapter $connection;
		
		/** @var EntityStore Service for extracting entity metadata and column definitions */
		private EntityStore $entityStore;
		
		/** @var IndexComparator Handles comparison of database indexes between entity and schema */
		private IndexComparator $indexComparator;
		
		/** @var SchemaComparator Handles comparison of column definitions and structures */
		private SchemaComparator $schemaComparator;
		
		/** @var array<string, array> Cache to avoid redundant schema analysis operations */
		private array $analysisCache = [];
		
		/**
		 * Constructor - Initializes the analyzer with required dependencies
		 * @param DatabaseAdapter $connection Database adapter for schema queries
		 * @param EntityStore $entityStore Entity metadata extraction service
		 */
		public function __construct(DatabaseAdapter $connection, EntityStore $entityStore) {
			$this->connection = $connection;
			$this->entityStore = $entityStore;
			$this->indexComparator = new IndexComparator($connection, $entityStore);
			$this->schemaComparator = new SchemaComparator($connection);
		}
		
		/**
		 * Analyzes schema differences for multiple entity classes
		 * @param array<string, string> $entityClasses Map of entity class names to table names
		 * @param bool $useCache Whether to use cached analysis results
		 * @return array<string, array> Map of table names to their detected changes
		 * @throws RuntimeException If schema analysis fails
		 */
		public function analyzeEntityChanges(array $entityClasses, bool $useCache = true): array {
			// Generate cache key from entity classes to enable result reuse
			$cacheKey = md5(serialize($entityClasses));
			
			// Return cached data if available
			if ($useCache && isset($this->analysisCache[$cacheKey])) {
				return $this->analysisCache[$cacheKey];
			}
			
			try {
				// Retrieve all existing tables from the database
				$existingTables = $this->connection->getTables();
				
				// Analyze each entity class individually
				$allChanges = [];
				
				foreach ($entityClasses as $className => $tableName) {
					// Ensure entity class and table name are valid
					$this->validate($className, $tableName);
					
					// Compare entity definition against database schema
					$changes = $this->compareSchemas($tableName, $className, $existingTables);
					
					// Only include entities that have detected changes
					if ($this->hasChanges($changes)) {
						$allChanges[$tableName] = $changes;
					}
				}
				
				// Cache the results if caching is enabled
				if ($useCache) {
					$this->analysisCache[$cacheKey] = $allChanges;
				}
				
				return $allChanges;
				
			} catch (\Exception $e) {
				throw new RuntimeException('Schema analysis failed: ' . $e->getMessage(), 0, $e);
			}
		}
		
		/**
		 * Compares a single entity's schema against its database table
		 * @param string $tableName Name of the database table
		 * @param string $className Fully qualified entity class name
		 * @param array<int, string> $existingTables List of all existing database tables
		 * @return array Schema change information including added, modified, deleted columns and indexes
		 * @throws RuntimeException If entity has no properties defined
		 * @throws \Exception
		 */
		private function compareSchemas(string $tableName, string $className, array $existingTables): array {
			// Extract column definitions from entity class properties
			$entityColumns = $this->entityStore->extractEntityColumnDefinitions($className);
			
			if (empty($entityColumns)) {
				throw new RuntimeException("No properties found for entity {$className}");
			}
			
			// If table doesn't exist, all entity columns are "added"
			if (!in_array($tableName, $existingTables)) {
				return [
					'table_not_exists' => true,
					'added'            => $entityColumns,
					'modified'         => [],
					'deleted'          => [],
					'indexes'          => $this->indexComparator->getEntityIndexes($className),
					'constraints'      => []
				];
			}
			
			// Table exists - compare actual schema against entity definition
			$tableColumns = $this->connection->getColumns($tableName);
			$changes = $this->schemaComparator->analyzeSchemaChanges($entityColumns, $tableColumns);
			
			// Add index comparisons to the change set
			$changes['indexes'] = $this->indexComparator->compareIndexes($className);
			
			// Placeholder for future constraint comparison logic
			$changes['constraints'] = [];
			
			// Return results
			return $changes;
		}
		
		/**
		 * Determines if a change set contains any actual modifications
		 * @param array $changes Change set from compareSchemas()
		 * @return bool True if any changes are detected, false otherwise
		 */
		private function hasChanges(array $changes): bool {
			// Check if table needs to be created
			if (!empty($changes['table_not_exists'])) {
				return true;
			}
			
			// Check for column-level changes
			if (
				!empty($changes['added']) ||
				!empty($changes['modified']) ||
				!empty($changes['deleted'])
			) {
				return true;
			}
			
			// Check for index-level changes
			if (
				!empty($changes['indexes']['added']) ||
				!empty($changes['indexes']['modified']) ||
				!empty($changes['indexes']['deleted'])
			) {
				return true;
			}
			
			return false;
		}
		
		/**
		 * Validates entity class and table name parameters
		 * @param string $className Fully qualified entity class name
		 * @param string $tableName Database table name
		 * @throws InvalidArgumentException If validation fails
		 */
		private function validate(string $className, string $tableName): void {
			if (empty($className) || empty($tableName)) {
				throw new InvalidArgumentException('Class name and table name cannot be empty');
			}
			
			if (!class_exists($className)) {
				throw new InvalidArgumentException("Entity class {$className} does not exist");
			}
		}
	}