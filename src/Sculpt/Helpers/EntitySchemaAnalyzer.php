<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityStore;
	use RuntimeException;
	use InvalidArgumentException;
	
	/**
	 * EntitySchemaAnalyzer - Analyzes differences between entity definitions and database schema
	 *
	 * This class provides comprehensive analysis of schema differences between entity definitions
	 * and the actual database schema, supporting migration planning and validation.
	 */
	class EntitySchemaAnalyzer {
		private DatabaseAdapter $connection;
		private EntityStore $entityStore;
		private IndexComparator $indexComparator;
		private SchemaComparator $schemaComparator;
		private array $analysisCache = [];
		
		/**
		 * EntitySchemaAnalyzer constructor
		 * @param DatabaseAdapter $connection Database connection adapter
		 * @param EntityStore $entityStore Entity store for entity definitions
		 */
		public function __construct(
			DatabaseAdapter $connection,
			EntityStore     $entityStore
		) {
			$this->connection = $connection;
			$this->entityStore = $entityStore;
			$this->indexComparator = new IndexComparator($connection, $entityStore);
			$this->schemaComparator = new SchemaComparator();
		}
		
		/**
		 * Analyze changes between entity definitions and database schema
		 * @param array $entityClasses Mapping of entity class names to their corresponding table names
		 * @param bool $useCache Whether to use cached results for performance
		 * @return array Analysis results with detailed change information
		 * @throws RuntimeException When analysis fails
		 */
		public function analyzeEntityChanges(array $entityClasses, bool $useCache = true): array {
			// Generate a unique cache key based on the input entity classes
			$cacheKey = md5(serialize($entityClasses));
			
			// Return cached results if available and caching is enabled
			if ($useCache && isset($this->analysisCache[$cacheKey])) {
				return $this->analysisCache[$cacheKey];
			}
			
			try {
				// Get list of all existing tables from the database
				$existingTables = $this->getExistingTables();
				
				// Initialize array to store all detected changes
				$allChanges = [];
				
				// Loop through each entity class to analyze its changes
				foreach ($entityClasses as $className => $tableName) {
					// Validate that the entity class and table name are valid
					$this->validateEntityClass($className, $tableName);
					
					try {
						// Extract properties/fields from the entity class definition
						$entityProperties = $this->extractEntityProperties($className);
						
						// Compare entity properties with actual database table structure
						$changes = $this->analyzeTableChanges($tableName, $className, $entityProperties, $existingTables);
						
						// Only include tables that have meaningful changes worth reporting
						if ($this->hasSignificantChanges($changes)) {
							$allChanges[$tableName] = $changes;
						}
					} catch (\Exception $e) {
						// Wrap any entity-specific errors with context about which entity failed
						throw new RuntimeException(
							"Analysis failed for entity {$className}: " . $e->getMessage(),
							0,
							$e
						);
					}
				}
				
				// Store results in cache for future requests if caching is enabled
				if ($useCache) {
					$this->analysisCache[$cacheKey] = $allChanges;
				}
				
				// Return the complete analysis results
				return $allChanges;
				
			} catch (RuntimeException $e) {
				// Re-throw RuntimeException without wrapping to preserve error context
				throw $e;
			} catch (\Exception $e) {
				// Wrap any unexpected exceptions with a general schema analysis error
				throw new RuntimeException('Schema analysis failed: ' . $e->getMessage(), 0, $e);
			}
		}
		
		/**
		 * Check if a specific table needs migration
		 * @param string $tableName Table to check
		 * @param string $entityClass Corresponding entity class
		 * @return bool True if migration is needed
		 */
		public function needsMigration(string $tableName, string $entityClass): bool {
			$changes = $this->analyzeEntityChanges([$entityClass => $tableName]);
			return isset($changes[$tableName]);
		}
		
		/**
		 * Clear analysis cache
		 * @return void
		 */
		public function clearCache(): void {
			$this->analysisCache = [];
		}
		
		/**
		 * Get existing tables from database with error handling
		 * @return array List of existing table names
		 * @throws RuntimeException When unable to retrieve tables
		 */
		private function getExistingTables(): array {
			try {
				return $this->connection->getTables();
			} catch (\Exception $e) {
				throw new RuntimeException(
					'Unable to retrieve existing tables from database: ' . $e->getMessage(),
					0,
					$e
				);
			}
		}
		
		/**
		 * Extract entity properties with validation
		 * @param string $className Entity class name
		 * @return array Entity column definitions
		 * @throws RuntimeException When entity properties cannot be extracted
		 */
		private function extractEntityProperties(string $className): array {
			try {
				$properties = $this->entityStore->extractEntityColumnDefinitions($className);
				
				if (empty($properties)) {
					throw new RuntimeException("No properties found for entity {$className}");
				}
				
				return $properties;
			} catch (\Exception $e) {
				throw new RuntimeException(
					"Failed to extract properties for entity {$className}: " . $e->getMessage(),
					0,
					$e
				);
			}
		}
		
		/**
		 * Analyze changes for a specific table
		 * @param string $tableName Database table name
		 * @param string $className Entity class name
		 * @param array $entityProperties Properties from entity definition
		 * @param array $existingTables List of existing tables
		 * @return array Changes for this table
		 * @throws \Exception
		 */
		private function analyzeTableChanges(
			string $tableName,
			string $className,
			array  $entityProperties,
			array  $existingTables
		): array {
			if (!in_array($tableName, $existingTables)) {
				return $this->createNewTableChanges($className, $entityProperties);
			} else {
				return $this->compareExistingTable($tableName, $className, $entityProperties);
			}
		}
		
		/**
		 * Create changes structure for a new table
		 * @param string $className Entity class name
		 * @param array $entityProperties Entity properties
		 * @return array Changes structure for new table
		 * @throws \Exception
		 */
		private function createNewTableChanges(string $className, array $entityProperties): array {
			return [
				'table_not_exists' => true,
				'added'            => $entityProperties,
				'modified'         => [],
				'deleted'          => [],
				'indexes'          => $this->indexComparator->getEntityIndexes($className),
				'constraints'      => [], // Future enhancement
			];
		}
		
		/**
		 * Compare an existing table with entity definition
		 * @param string $tableName Name of the database table
		 * @param string $className Entity class name
		 * @param array $entityProperties Properties extracted from entity
		 * @return array Changes for this table
		 */
		private function compareExistingTable(
			string $tableName,
			string $className,
			array  $entityProperties
		): array {
			try {
				// Fetch database columns
				$tableColumns = $this->connection->getColumns($tableName);
				
				// Compare database columns with entity data
				$changes = $this->schemaComparator->analyzeSchemaChanges($entityProperties, $tableColumns);
				
				// Also compare the indexes
				$changes['indexes'] = $this->indexComparator->compareIndexes($className);
				
				// Future enhancement: Compare constraints
				$changes['constraints'] = [];
				
				return $changes;
			} catch (\Exception $e) {
				throw new RuntimeException(
					"Failed to compare table {$tableName}: " . $e->getMessage(),
					0,
					$e
				);
			}
		}
		
		/**
		 * Check if there are any significant changes for a table
		 * @param array $changes The changes array for a table
		 * @return bool True if there are significant changes
		 */
		private function hasSignificantChanges(array $changes): bool {
			return !empty($changes['table_not_exists']) ||
				!empty($changes['added']) ||
				!empty($changes['modified']) ||
				!empty($changes['deleted']) ||
				!empty($changes['indexes']['added'] ?? []) ||
				!empty($changes['indexes']['modified'] ?? []) ||
				!empty($changes['indexes']['deleted'] ?? []) ||
				!empty($changes['constraints']['added'] ?? []) ||
				!empty($changes['constraints']['modified'] ?? []) ||
				!empty($changes['constraints']['deleted'] ?? []);
		}
		
		/**
		 * Validate entity class and table name
		 * @param string $className Entity class name
		 * @param string $tableName Table name
		 * @throws InvalidArgumentException When validation fails
		 */
		private function validateEntityClass(string $className, string $tableName): void {
			if (empty($className)) {
				throw new InvalidArgumentException('Entity class name cannot be empty');
			}
			
			if (empty($tableName)) {
				throw new InvalidArgumentException("Table name cannot be empty for entity {$className}");
			}
			
			if (!class_exists($className)) {
				throw new InvalidArgumentException("Entity class {$className} does not exist");
			}
		}
	}