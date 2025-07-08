<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	
	/**
	 * PhinxMigrationBuilder - Generates Phinx migration files from schema changes
	 *
	 * This class takes a list of schema changes and generates the corresponding Phinx
	 * migration code for creating, modifying, or removing database tables, columns, and indexes.
	 */
	class PhinxMigrationBuilder {
		private TypeMapper $phinxTypeMapper;
		private string $migrationsPath;
		
		/**
		 * PhinxMigrationBuilder constructor
		 * @param string $migrationsPath Path where migration files will be stored
		 */
		public function __construct(string $migrationsPath) {
			$this->migrationsPath = $migrationsPath;
			$this->phinxTypeMapper = new TypeMapper();
		}
		
		/**
		 * Generate Phinx migration file from schema changes
		 * @param array $allChanges Changes for all tables
		 * @return array Migration generation result with success status and file path
		 */
		public function generateMigrationFile(array $allChanges): array {
			// If no changes were detected, inform the user and exit early
			if (empty($allChanges)) {
				return [
					'success' => false,
					'message' => "No changes detected. Migration file not created."
				];
			}
			
			// Create timestamp and name components for the migration file
			$timestamp = time();
			$migrationName = 'EntitySchemaMigration' . date('YmdHis', $timestamp);
			$className = 'EntitySchemaMigration' . date('YmdHis', $timestamp);
			
			// Construct the full filepath for the migration
			$filename = $this->migrationsPath . '/' . date('YmdHis', $timestamp) . '_' . $migrationName . '.php';
			
			// Generate the PHP code content for the migration file
			$migrationContent = $this->buildMigrationContent($className, $allChanges);
			
			// Create migrations directory if it doesn't exist
			if (!is_dir($this->migrationsPath)) {
				mkdir($this->migrationsPath, 0755, true);
			}
			
			// Write the migration file
			if (file_put_contents($filename, $migrationContent)) {
				return [
					'success' => true,
					'message' => "Migration file created",
					'path'    => $filename
				];
			}
			
			// If file writing failed, inform the user
			return [
				'success' => false,
				'message' => "Failed to create migration file."
			];
		}
		
		/**
		 * Build the content of the migration file
		 * @param string $className Migration class name
		 * @param array $allChanges Changes for all tables
		 * @return string Migration file content
		 */
		private function buildMigrationContent(string $className, array $allChanges): string {
			$upMethod = [];
			$downMethod = [];
			
			foreach ($allChanges as $tableName => $changes) {
				// Add table if it doesn't exist
				if (!empty($changes['table_not_exists'])) {
					$upMethod[] = $this->buildCreateTableCode($tableName, $changes['added'], $changes['indexes']);
					$downMethod[] = "        \$this->table('$tableName')->drop()->save();";
					continue;
				}
				
				// Add columns
				if (!empty($changes['added'])) {
					$upMethod[] = $this->buildAddColumnsCode($tableName, $changes['added']);
					$downMethod[] = $this->buildRemoveColumnsCode($tableName, $changes['added']);
				}
				
				// Modify columns
				if (!empty($changes['modified'])) {
					$upMethod[] = $this->buildModifyColumnsCode($tableName, $changes['modified']);
					$downMethod[] = $this->buildReverseModifyColumnsCode($tableName, $changes['modified']);
				}
				
				// Remove columns
				if (!empty($changes['deleted'])) {
					$upMethod[] = $this->buildRemoveColumnsCode($tableName, $changes['deleted']);
					$downMethod[] = $this->buildAddColumnsCode($tableName, $changes['deleted']);
				}
				
				// Handle index changes for existing tables
				if (!empty($changes['indexes'])) {
					// Add new indexes
					if (!empty($changes['indexes']['added'])) {
						$upMethod[] = $this->buildAddIndexesCode($tableName, $changes['indexes']['added']);
						$downMethod[] = $this->buildRemoveIndexesCode($tableName, $changes['indexes']['added']);
					}
					
					// Modify existing indexes
					if (!empty($changes['indexes']['modified'])) {
						$upMethod[] = $this->buildModifyIndexesCode($tableName, $changes['indexes']['modified']);
						
						$downIndexChanges = [];
						
						foreach ($changes['indexes']['modified'] as $name => $configs) {
							$downIndexChanges[$name] = [
								'database' => $configs['entity'],
								'entity'   => $configs['database']
							];
						}
						
						$downMethod[] = $this->buildModifyIndexesCode($tableName, $downIndexChanges);
					}
					
					// Remove deleted indexes
					if (!empty($changes['indexes']['deleted'])) {
						$upMethod[] = $this->buildRemoveIndexesCode($tableName, $changes['indexes']['deleted']);
						$downMethod[] = $this->buildAddIndexesCode($tableName, $changes['indexes']['deleted']);
					}
				}
			}
			
			$upMethodContent = implode("\n\n", $upMethod);
			$downMethodContent = implode("\n\n", $downMethod);
			
			return <<<PHP
<?php

use Phinx\Migration\AbstractMigration;

class $className extends AbstractMigration {

    /**
     * This migration was automatically generated by ObjectQuel
     *
     * More information on migrations is available on the Phinx website:
     * https://book.cakephp.org/phinx/0/en/migrations.html
     */
     
    public function up(): void {
$upMethodContent
    }

    public function down(): void {
$downMethodContent
    }
}
PHP;
		}
		
		/**
		 * Build code for creating a new database table
		 * @param string $tableName Table name
		 * @param array $entityColumns Column definitions
		 * @param array $indexes Index definitions
		 * @return string Phinx code for creating the table
		 */
		private function buildCreateTableCode(string $tableName, array $entityColumns, array $indexes = []): string {
			// Step 1: Analyze columns to identify primary keys and auto-increment
			$columnInfo = $this->analyzeColumns($entityColumns);
			$primaryKeys = $columnInfo['primaryKeys'];
			$hasAutoIncrement = $columnInfo['hasAutoIncrement'];
			$autoIncrementColumn = $columnInfo['autoIncrementColumn'];
			
			// Step 2: Determine if we need an additional unique index for auto-increment column
			$needsUniqueIndex = $hasAutoIncrement && !in_array($autoIncrementColumn, $primaryKeys);
			
			// Step 3: Generate column definitions
			$columnDefs = $this->generateColumnDefinitions($entityColumns);
			
			// Step 4: Build the table creation code
			return $this->assembleTableCode(
				$tableName,
				$columnDefs,
				$primaryKeys,
				$needsUniqueIndex ? $autoIncrementColumn : null,
				$indexes
			);
		}
		
		/**
		 * Analyze column definitions to extract primary keys and auto-increment information
		 *
		 * This method examines the entity columns to identify specific characteristics needed for
		 * proper database table generation:
		 * 1. Which columns form the primary key(s)
		 * 2. Whether any column is an auto-increment column
		 * 3. The name of the auto-increment column (if any)
		 *
		 * These details are crucial for creating appropriate table structures and indexes in the
		 * migration code, as database systems like MySQL have specific requirements for primary keys
		 * and auto-increment columns.
		 *
		 * @param array $entityColumns Column definitions from the entity schema
		 * @return array Information about primary keys and auto-increment with the following keys:
		 *               - primaryKeys: array of column names that are part of the primary key
		 *               - hasAutoIncrement: boolean indicating if any column has auto-increment
		 *               - autoIncrementColumn: string name of the auto-increment column (or null)
		 */
		private function analyzeColumns(array $entityColumns): array {
			// Track columns that are marked as primary keys
			$primaryKeys = [];
			
			// Track whether we have an auto-increment column
			// MySQL only allows one auto-increment column per table
			$hasAutoIncrement = false;
			
			// Store the name of the auto-increment column (if any)
			$autoIncrementColumn = null;
			
			// Examine each column definition to identify special characteristics
			foreach ($entityColumns as $columnName => $definition) {
				// Check if this column is part of the primary key
				// Primary keys are essential for uniquely identifying rows in the table
				if (!empty($definition['primary_key'])) {
					$primaryKeys[] = $columnName;
				}
				
				// Check if this column is an auto-increment column
				// Auto-increment columns automatically generate sequential values for new rows
				// Most databases only support one auto-increment column per table
				if (!empty($definition['identity'])) {
					$hasAutoIncrement = true;
					$autoIncrementColumn = $columnName;
				}
			}
			
			// Return all collected information in a structured array
			// This will be used by other methods to generate the appropriate
			// table structure and indexes in the migration code
			return [
				'primaryKeys'         => $primaryKeys,         // Columns that form the primary key
				'hasAutoIncrement'    => $hasAutoIncrement,    // Whether we have an auto-increment column
				'autoIncrementColumn' => $autoIncrementColumn  // Name of the auto-increment column (if any)
			];
		}
		
		/**
		 * Generate Phinx column definition code for each column
		 * @param array $entityColumns Column definitions from the entity schema
		 * @return array Array of formatted Phinx column definition strings ready for inclusion in migration code
		 */
		private function generateColumnDefinitions(array $entityColumns): array {
			// Initialize array to hold generated column definition strings
			$columnDefs = [];
			
			// Process each column in the entity schema
			foreach ($entityColumns as $columnName => $definition) {
				// Extract the column data type which maps to Phinx types
				$type = $definition['type'];
				
				// Build column options using helper method (handles nullability, defaults, limits, etc.)
				$options = $this->buildColumnOptions($definition);
				
				// Add identity (auto-increment) option if this column is defined as an identity column
				// MySQL requires explicit identity flag for auto-increment columns
				if (!empty($definition['identity'])) {
					$options[] = "'identity' => true";
				}
				
				// Format the options array into a string for the Phinx method call
				// If no options are defined, omit the options parameter entirely
				$optionsStr = empty($options) ? "" : ", [" . implode(", ", $options) . "]";
				
				// Generate the complete addColumn() method call with proper indentation
				// This follows Phinx's fluent interface pattern for table operations
				$columnDefs[] = "            ->addColumn('$columnName', '$type'$optionsStr)";
			}
			
			// Return all column definitions as an array of strings
			return $columnDefs;
		}
		
		/**
		 * Assemble the complete table creation code
		 * @param string $tableName Table name
		 * @param array $columnDefs Column definition strings
		 * @param array $primaryKeys List of primary key columns
		 * @param string|null $autoIncrementColumn Auto-increment column that needs a unique index
		 * @param array $indexes Index definitions
		 * @return string Complete table creation code
		 */
		private function assembleTableCode(
			string  $tableName,
			array   $columnDefs,
			array   $primaryKeys,
			?string $autoIncrementColumn,
			array   $indexes
		): string {
			// Create primary key option string if needed
			$primaryKeyOption = "";
			
			if (!empty($primaryKeys)) {
				$primaryKeysList = "'" . implode("', '", $primaryKeys) . "'";
				$primaryKeyOption = ", 'primary_key' => [$primaryKeysList]";
			}
			
			// Start with table definition
			$tableStart = "        \$this->table('$tableName', ['id' => false$primaryKeyOption])";
			
			// Add columns
			$tableCode = $tableStart . "\n" . implode("\n", $columnDefs);
			
			// Add unique index for auto-increment column if needed
			if ($autoIncrementColumn) {
				$tableCode .= "\n            ->addIndex(['$autoIncrementColumn'], ['unique' => true, 'name' => 'uidx_{$tableName}_{$autoIncrementColumn}'])";
			}
			
			// Add user-defined indexes
			$tableCode .= $this->generateIndexDefinitions($indexes);
			
			// Close the table creation
			$tableCode .= "\n            ->create();";
			
			return $tableCode;
		}
		
		/**
		 * Generate index definitions for all indexes
		 * @param array $indexes Index definitions
		 * @return string Combined index definition code
		 */
		private function generateIndexDefinitions(array $indexes): string {
			// Early return if no indexes are defined
			if (empty($indexes)) {
				return "";
			}
			
			// Process each index defined for this table
			$indexCode = "";
			
			foreach ($indexes as $indexName => $indexConfig) {
				// Initialize an options array with the index name
				$indexOptions = [];
				$indexOptions[] = "'name' => '$indexName'";
				
				// Add unique constraint if specified
				if ($indexConfig['unique']) {
					$indexOptions[] = "'unique' => true";
				}
				
				// Convert options array to a string for Phinx method
				$indexOptionsStr = implode(", ", $indexOptions);
				
				// Format column list for Phinx (e.g. 'column1', 'column2')
				$columnsList = "'" . implode("', '", $indexConfig['columns']) . "'";
				
				// Build the addIndex() method call with proper indentation
				// Uses Phinx fluent interface for table creation
				$indexCode .= "\n            ->addIndex([$columnsList], [$indexOptionsStr])";
			}
			
			return $indexCode;
		}
		
		/**
		 * Builds code for adding columns to an existing database table
		 * @param string $tableName Name of the table to modify
		 * @param array $entityColumns Column definitions with their properties
		 * @return string Generated PHP code for Phinx migration to add columns
		 */
		private function buildAddColumnsCode(string $tableName, array $entityColumns): string {
			// Step 1: Analyze columns to extract metadata (reuse existing method)
			$columnInfo = $this->analyzeColumns($entityColumns);
			$primaryKeys = $columnInfo['primaryKeys'];
			$hasAutoIncrement = $columnInfo['hasAutoIncrement'];
			$autoIncrementColumn = $columnInfo['autoIncrementColumn'];
			
			// Step 2: Determine if auto-increment column needs unique index
			$needsUniqueIndex = $hasAutoIncrement && !in_array($autoIncrementColumn, $primaryKeys);
			
			// Step 3: Generate column definitions (reuse existing method)
			$columnDefinitions = $this->generateColumnDefinitions($entityColumns);
			
			// Step 4: Start with table declaration
			$tableCode = "        \$this->table('$tableName')";
			
			// Step 5: Add all column definitions
			$tableCode .= "\n" . implode("\n", $columnDefinitions);
			
			// Step 6: Add indexes for primary keys
			if (!empty($primaryKeys)) {
				// Transform primary keys to string
				$primaryKeysStr = implode("', '", $primaryKeys);
				
				// Remove any existing indexes on these columns first
				$tableCode .= "\n            ->removeIndex(['$primaryKeysStr'])\n";
				$tableCode .= "            ->addIndex(['$primaryKeysStr'], ['unique' => true, 'name' => 'PRIMARY'])";
			}
			
			// Step 7: Add separate unique index for auto-increment column if needed
			if ($needsUniqueIndex) {
				$tableCode .= "\n            ->addIndex(['$autoIncrementColumn'], ['unique' => true, 'name' => 'uidx_{$tableName}_{$autoIncrementColumn}'])";
			}
			
			// Step 8: Finalize the table update
			$tableCode .= "\n            ->update();";
		
			// Return the result
			return $tableCode;
		}
		
		/**
		 * Build code for removing columns from a table
		 * @param string $tableName Table name
		 * @param array $columns Column definitions
		 * @return string Code for removing columns
		 */
		private function buildRemoveColumnsCode(string $tableName, array $columns): string {
			$columnDefs = [];
			
			foreach ($columns as $columnName => $columnDef) {
				$columnDefs[] = "            ->removeColumn('$columnName')";
			}
			
			return "        \$this->table('$tableName')\n" . implode("\n", $columnDefs) . "\n            ->update();";
		}
		
		/**
		 * Build code for modifying columns in a table
		 * @param string $tableName Table name
		 * @param array $modifiedColumns Modified column definitions
		 * @return string Code for modifying columns
		 */
		private function buildModifyColumnsCode(string $tableName, array $modifiedColumns): string {
			$columnDefs = [];
			
			foreach ($modifiedColumns as $columnName => $changes) {
				$type = $changes['to']['type'];
				
				$options = $this->buildColumnOptions($changes['to']);
				
				$optionsStr = empty($options) ? "" : ", [" . implode(", ", $options) . "]";
				$columnDefs[] = "            ->changeColumn('$columnName', '$type'$optionsStr)";
			}
			
			return "        \$this->table('$tableName')\n" . implode("\n", $columnDefs) . "\n            ->update();";
		}
		
		/**
		 * Build code for reversing column modifications
		 * @param string $tableName Table name
		 * @param array $modifiedColumns Modified column definitions
		 * @return string Code for reversing column modifications
		 */
		private function buildReverseModifyColumnsCode(string $tableName, array $modifiedColumns): string {
			$columnDefs = [];
			
			foreach ($modifiedColumns as $columnName => $changes) {
				$type = $changes['from']['type'];
				
				$options = $this->buildColumnOptions($changes['from']);
				
				$optionsStr = empty($options) ? "" : ", [" . implode(", ", $options) . "]";
				$columnDefs[] = "            ->changeColumn('$columnName', '$type'$optionsStr)";
			}
			
			return "        \$this->table('$tableName')\n" . implode("\n", $columnDefs) . "\n            ->update();";
		}
		
		/**
		 * Build code for adding indexes to a table
		 * @param string $tableName Table name
		 * @param array $indexes Indexes to add
		 * @return string Code for adding indexes
		 */
		private function buildAddIndexesCode(string $tableName, array $indexes): string {
			$indexDefs = [];
			
			foreach ($indexes as $name => $indexConfig) {
				$columns = "'" . implode("', '", $indexConfig['columns']) . "'";
				$options = ["'name' => '$name'"];
				
				if ($indexConfig['unique']) {
					$options[] = "'unique' => true";
				}
				
				$optionsStr = ", [" . implode(", ", $options) . "]";
				$indexDefs[] = "            ->addIndex([$columns]$optionsStr)";
			}
			
			return "        \$this->table('$tableName')\n" . implode("\n", $indexDefs) . "\n            ->update();";
		}
		
		/**
		 * Build code for modifying indexes in a table
		 * @param string $tableName Table name
		 * @param array $indexes Index modifications
		 * @return string Code for modifying indexes
		 */
		private function buildModifyIndexesCode(string $tableName, array $indexes): string {
			$indexDefs = [];
			
			foreach ($indexes as $name => $configs) {
				// We need to drop and recreate the index, as Phinx doesn't have a direct "modify index" function
				$indexDefs[] = "            ->removeIndex([], ['name' => '$name'])";
				
				$columns = "'" . implode("', '", $configs['entity']['columns']) . "'";
				$options = ["'name' => '$name'"];
				
				if ($configs['entity']['unique']) {
					$options[] = "'unique' => true";
				}
				
				$optionsStr = ", [" . implode(", ", $options) . "]";
				$indexDefs[] = "            ->addIndex([$columns]$optionsStr)";
			}
			
			return "        \$this->table('$tableName')\n" . implode("\n", $indexDefs) . "\n            ->update();";
		}
		
		/**
		 * Build code for removing indexes from a table
		 * @param string $tableName Table name
		 * @param array $indexes Indexes to remove
		 * @return string Code for removing indexes
		 */
		private function buildRemoveIndexesCode(string $tableName, array $indexes): string {
			$indexDefs = [];
			
			foreach ($indexes as $name => $indexConfig) {
				$indexDefs[] = "            ->removeIndex([], ['name' => '$name'])";
			}
			
			return "        \$this->table('$tableName')\n" . implode("\n", $indexDefs) . "\n            ->update();";
		}
		
		/**
		 * Build Phinx column options based on property definition
		 * @param array $propertyDef Entity property definition
		 * @return array List of formatted Phinx column options
		 */
		private function buildColumnOptions(array $propertyDef): array {
			$options = [];
			
			// Handle size/length constraints
			$this->addLimitOption($options, $propertyDef);
			
			// Handle default value
			$this->addDefaultOption($options, $propertyDef);
			
			// Handle nullability
			$this->addNullabilityOption($options, $propertyDef);
			
			// Handle numeric precision and scale
			$this->addNumericPrecisionOptions($options, $propertyDef);
			
			// Handle unsigned flag for numeric types
			$this->addSignednessOption($options, $propertyDef);
			
			// Return result
			return $options;
		}
		
		/**
		 * Add column size/length constraint if specified
		 * @param array &$options Options array to modify
		 * @param array $propertyDef Property definition
		 */
		private function addLimitOption(array &$options, array $propertyDef): void {
			if (!empty($propertyDef['limit'])) {
				$options[] = "'limit' => " . $this->phinxTypeMapper->formatValue($propertyDef['limit']);
			}
		}
		
		/**
		 * Add default value if specified and not null
		 * @param array &$options Options array to modify
		 * @param array $propertyDef Property definition
		 */
		private function addDefaultOption(array &$options, array $propertyDef): void {
			if (!empty($propertyDef['default']) && $propertyDef['default'] !== null) {
				$options[] = "'default' => " . $this->phinxTypeMapper->formatValue($propertyDef['default']);
			}
		}
		
		/**
		 * Add nullability constraint (defaults to NOT NULL)
		 * @param array &$options Options array to modify
		 * @param array $propertyDef Property definition
		 */
		private function addNullabilityOption(array &$options, array $propertyDef): void {
			if (isset($propertyDef['nullable'])) {
				$options[] = "'null' => " . ($propertyDef['nullable'] ? 'true' : 'false');
			} else {
				// Default to NOT NULL if not explicitly set
				$options[] = "'null' => false";
			}
		}
		
		/**
		 * Add precision and scale for numeric types
		 * @param array &$options Options array to modify
		 * @param array $propertyDef Property definition
		 */
		private function addNumericPrecisionOptions(array &$options, array $propertyDef): void {
			// Set precision (total digits)
			if (!empty($propertyDef['precision'])) {
				$options[] = "'precision' => " . $propertyDef['precision'];
			}
			
			// Set scale (digits after decimal point)
			if (!empty($propertyDef['scale'])) {
				$options[] = "'scale' => " . $propertyDef['scale'];
			}
		}
		
		/**
		 * Add signed/unsigned flag for numeric types
		 * @param array &$options Options array to modify
		 * @param array $propertyDef Property definition
		 */
		private function addSignednessOption(array &$options, array $propertyDef): void {
			if (isset($propertyDef['unsigned'])) {
				// Note: Phinx uses 'signed' (opposite of 'unsigned')
				$options[] = "'signed' => " . ($propertyDef['unsigned'] ? 'false' : 'true');
			}
		}
	}