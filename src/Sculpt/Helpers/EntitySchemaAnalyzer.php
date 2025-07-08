<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityStore;
	
	/**
	 * EntitySchemaAnalyzer - Analyzes differences between entity definitions and database schema
	 */
	class EntitySchemaAnalyzer {
		private DatabaseAdapter $connection;
		private EntityStore $entityStore;
		private IndexComparator $indexComparator;
		private SchemaComparator $schemaComparator;
		
		/**
		 * EntitySchemaAnalyzer constructor
		 * @param DatabaseAdapter $connection Database connection adapter
		 * @param EntityStore $entityStore Entity store for entity definitions
		 */
		public function __construct(
			DatabaseAdapter  $connection,
			EntityStore      $entityStore,
		) {
			$this->connection = $connection;
			$this->entityStore = $entityStore;
			$this->indexComparator = new IndexComparator($connection, $entityStore);
			$this->schemaComparator = new SchemaComparator();
		}
		
		/**
		 * Analyze changes between entity definitions and database schema
		 * @param array $entityClasses Mapping of entity class names to their corresponding table names
		 * @return array List of changes organized by table name
		 */
		public function analyzeEntityChanges(array $entityClasses): array {
			// Get all existing tables from the database connection
			$existingTables = $this->connection->getTables();
			
			// This will hold all detected changes, organized by table name
			$allChanges = [];
			
			// Iterate through each entity class and analyze differences with database
			foreach ($entityClasses as $className => $tableName) {
				// Extract column definitions from the entity class annotations/metadata
				// This gives us the expected schema according to the entity definition
				$entityProperties = $this->entityStore->extractEntityColumnDefinitions($className);
				
				// Special case: Table doesn't exist in database yet
				// No need for detailed comparison, just mark entire table as new
				if (!in_array($tableName, $existingTables)) {
					$allChanges[$tableName] = [
						// Flag indicating this is a completely new table
						'table_not_exists' => true,
						
						// All columns from entity will be added as new
						'added'            => $entityProperties,
						
						// Get all index definitions from the entity
						// These will all be new indexes since the table doesn't exist
						'indexes'          => $this->indexComparator->getEntityIndexes($className)
					];
					
					// Skip to next entity - no need to compare with existing schema
					continue;
				}
				
				// For existing tables, perform detailed comparison between
				// entity definition and database schema
				$allChanges[$tableName] = $this->compareExistingTable(
					$tableName,
					$className,
					$entityProperties
				);
				
				// Optimization: Remove tables that have no actual changes
				// This keeps the change list clean and focused only on tables that need migration
				if (!$this->hasChanges($allChanges[$tableName])) {
					unset($allChanges[$tableName]);
				}
			}
			
			// Return the complete list of changes that need to be applied
			return $allChanges;
		}
		
		/**
		 * Compare an existing table with entity definition
		 * @param string $tableName Name of the database table
		 * @param string $className Entity class name
		 * @param array $entityProperties Properties extracted from entity
		 * @return array Changes for this table
		 */
		private function compareExistingTable(string $tableName, string $className, array  $entityProperties): array {
			// Compare columns
			$tableColumns = $this->connection->getColumns($tableName);
			$changes = $this->schemaComparator->analyzeSchemaChanges($entityProperties, $tableColumns);
			
			// Compare indexes
			$changes['indexes'] = $this->indexComparator->compareIndexes($className);
			
			// Return the result
			return $changes;
		}
		
		/**
		 * Check if there are any changes for a table
		 * @param array $changes The changes array for a table
		 * @return bool True if there are changes
		 */
		private function hasChanges(array $changes): bool {
			return
				!empty($changes['added']) ||
				!empty($changes['modified']) ||
				!empty($changes['deleted']) ||
				!empty($changes['indexes']['added']) ||
				!empty($changes['indexes']['modified']) ||
				!empty($changes['indexes']['deleted']);
		}
	}