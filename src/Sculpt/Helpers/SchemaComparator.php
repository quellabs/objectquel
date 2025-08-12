<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	
	/**
	 * Class SchemaComparator
	 * Compares entity schema (object properties) with database schema (table columns)
	 * to identify changes such as added, modified, or deleted columns.
	 */
	class SchemaComparator {
		
		private const array NUMERIC_PROPERTIES = ['limit', 'precision', 'scale'];
		private const array BOOLEAN_PROPERTIES = ['null', 'unsigned', 'signed', 'identity'];
		
		/**
		 * Main public method to compare entity properties with table columns
		 * Identifies added, modified, and deleted columns
		 * @param array $entityColumns Map of property names to definitions from entity model
		 * @param array $tableColumns Map of column names to definitions from database
		 * @return array Structured array of all detected changes
		 * @throws \InvalidArgumentException If input arrays are malformed
		 */
		public function analyzeSchemaChanges(array $entityColumns, array $tableColumns): array {
			$this->validateInput($entityColumns, 'entityColumns');
			$this->validateInput($tableColumns, 'tableColumns');
			
			return [
				'added'    => $this->getAddedColumns($entityColumns, $tableColumns),
				'modified' => $this->getModifiedColumns($entityColumns, $tableColumns),
				'deleted'  => $this->getDeletedColumns($entityColumns, $tableColumns)
			];
		}
		
		/**
		 * Get columns that exist in entity but not in table
		 * @param array $entityColumns Map of property names to definitions from entity model
		 * @param array $tableColumns Map of column names to definitions from database
		 * @return array Columns that need to be added to the table
		 */
		private function getAddedColumns(array $entityColumns, array $tableColumns): array {
			return array_diff_key($entityColumns, $tableColumns);
		}
		
		/**
		 * Get columns that exist in table but not in entity
		 * @param array $entityColumns Map of property names to definitions from entity model
		 * @param array $tableColumns Map of column names to definitions from database
		 * @return array Columns that need to be deleted from the table
		 */
		private function getDeletedColumns(array $entityColumns, array $tableColumns): array {
			return array_diff_key($tableColumns, $entityColumns);
		}
		
		/**
		 * Get columns that exist in both but have differences
		 * @param array $entityColumns Definition of properties from the entity model
		 * @param array $tableColumns Definition of columns from the database table
		 * @return array Columns that need to be modified in the table
		 */
		private function getModifiedColumns(array $entityColumns, array $tableColumns): array {
			$result = [];
			$commonColumns = array_intersect_key($entityColumns, $tableColumns);
			
			foreach ($commonColumns as $columnName => $entityColumn) {
				$tableColumn = $tableColumns[$columnName];
				
				if ($this->hasColumnChanged($entityColumn, $tableColumn)) {
					$result[$columnName] = $this->buildChangeDetails($entityColumn, $tableColumn);
				}
			}
			
			return $result;
		}
		
		/**
		 * Check if a specific column has changed between entity and table definitions
		 * @param array $entityColumn Column definition from entity model
		 * @param array $tableColumn Column definition from database table
		 * @return bool True if the column has changed, false otherwise
		 */
		private function hasColumnChanged(array $entityColumn, array $tableColumn): bool {
			$normalizedEntity = $this->normalizeColumnDefinition($entityColumn);
			$normalizedTable = $this->normalizeColumnDefinition($tableColumn);
			return $normalizedEntity !== $normalizedTable;
		}
		
		/**
		 * Build detailed change information for a modified column
		 * @param array $entityColumn Column definition from entity model
		 * @param array $tableColumn Column definition from database table
		 * @return array Detailed change information including from/to values and specific changes
		 */
		private function buildChangeDetails(array $entityColumn, array $tableColumn): array {
			$normalizedEntity = $this->normalizeColumnDefinition($entityColumn);
			$normalizedTable = $this->normalizeColumnDefinition($tableColumn);
			
			return [
				'from'    => $tableColumn,
				'to'      => $entityColumn,
				'changes' => $this->identifySpecificChanges($normalizedTable, $normalizedEntity)
			];
		}
		
		/**
		 * Identify specific properties that changed between two column definitions
		 * @param array $from Original column definition (normalized)
		 * @param array $to New column definition (normalized)
		 * @return array Map of property names to their before/after values
		 */
		private function identifySpecificChanges(array $from, array $to): array {
			$changes = [];
			$allKeys = array_unique(array_merge(array_keys($from), array_keys($to)));
			
			foreach ($allKeys as $key) {
				$fromValue = $from[$key] ?? null;
				$toValue = $to[$key] ?? null;
				
				if ($fromValue !== $toValue) {
					$changes[$key] = [
						'from' => $fromValue,
						'to'   => $toValue
					];
				}
			}
			
			return $changes;
		}
		
		/**
		 * Normalize column definition for consistent comparison
		 * @param array $columnDefinition The column definition to normalize
		 * @return array Normalized column definition
		 */
		private function normalizeColumnDefinition(array $columnDefinition): array {
			// Step 1: Add any missing default values to ensure all required properties are present
			$normalized = $this->addDefaultValues($columnDefinition);
			
			// Step 2: Remove irrelevant or comparison-specific properties that shouldn't affect equality
			$normalized = $this->filterRelevantProperties($normalized);
			
			// Step 3: Standardize property values to a consistent format - pass full context
			$normalized = $this->normalizePropertyValues($normalized);
			
			// Step 4: Sort the array keys alphabetically for consistent ordering
			ksort($normalized);
			
			return $normalized;
		}
		
		/**
		 * Add default values where missing
		 * @param array $columnDefinition Raw column definition
		 * @return array Column definition with default values added
		 */
		private function addDefaultValues(array $columnDefinition): array {
			$result = $columnDefinition;
			$columnType = $result['type'] ?? 'string';
			
			// Add default limit if missing
			if (!isset($result['limit'])) {
				$result['limit'] = TypeMapper::getDefaultLimit($columnType);
			}
			
			return $result;
		}
		
		/**
		 * Filter to only include properties relevant to the column type
		 * @param array $columnDefinition Column definition with all properties
		 * @return array Column definition with only type-relevant properties
		 */
		private function filterRelevantProperties(array $columnDefinition): array {
			$columnType = $columnDefinition['type'] ?? 'string';
			$relevantProperties = TypeMapper::getRelevantProperties($columnType);
			
			return array_intersect_key($columnDefinition, array_flip($relevantProperties));
		}
		
		/**
		 * Normalize property values for consistent comparison
		 * @param array $columnDefinition Column definition to normalize
		 * @return array Column definition with normalized property values
		 */
		private function normalizePropertyValues(array $columnDefinition): array {
			$result = $columnDefinition;
			$columnType = $result['type'] ?? 'string';
			
			foreach ($result as $property => $value) {
				$result[$property] = $this->normalizePropertyValue($property, $value, $columnType);
			}
			
			return $result;
		}
		
		/**
		 * Normalize a specific property value based on its type and column context
		 * @param string $property Property name
		 * @param mixed $value Property value to normalize
		 * @param string $columnType The column type for context
		 * @return mixed Normalized property value
		 */
		private function normalizePropertyValue(string $property, mixed $value, string $columnType): mixed {
			// Convert numeric properties to integers if the value is numeric
			// This ensures consistent data types for properties like length, precision, scale, etc.
			if (in_array($property, self::NUMERIC_PROPERTIES) && is_numeric($value)) {
				return (int)$value;
			}
			
			// Convert boolean properties to actual boolean values
			// This handles properties like nullable, unsigned, auto_increment, etc.
			if (in_array($property, self::BOOLEAN_PROPERTIES)) {
				return (bool)$value;
			}
			
			// Special case: handle default values for boolean columns
			// Boolean column defaults need special normalization (e.g., "0"/"1" strings to booleans)
			if ($property === 'default' && $columnType === 'boolean') {
				return $this->normalizeBooleanDefault($value);
			}
			
			// Clean up string values by removing leading/trailing whitespace
			// This ensures consistent formatting for string properties
			if (is_string($value)) {
				return trim($value);
			}
			
			// Return the value unchanged if no specific normalization rules apply
			// This preserves the original value for unsupported types or edge cases
			return $value;
		}
		
		/**
		 * Normalize boolean default values to handle database tinyint(1) vs PHP boolean differences
		 * @param mixed $value The default value to normalize (can be bool, int, string, or other types)
		 * @return int Normalized boolean value as integer for consistency (0 or 1)
		 */
		private function normalizeBooleanDefault(mixed $value): int {
			// Handle all truthy boolean representations
			// Covers: PHP true, integer 1, string '1', string 'true' (case-sensitive)
			if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
				return 1;
			}
			
			// Handle all falsy boolean representations
			// Covers: PHP false, integer 0, string '0', string 'false' (case-sensitive)
			if ($value === false || $value === 0 || $value === '0' || $value === 'false') {
				return 0;
			}
			
			// Fallback for unexpected values
			return is_numeric($value) ? (int)$value : 0;
		}
		
		/**
		 * Validate input arrays to ensure they have the expected structure
		 * @param array $columns Array of column definitions to validate
		 * @param string $parameterName Name of the parameter being validated (for error messages)
		 * @return void
		 * @throws \InvalidArgumentException If validation fails
		 */
		private function validateInput(array $columns, string $parameterName): void {
			foreach ($columns as $columnName => $columnDefinition) {
				if (!is_string($columnName)) {
					throw new \InvalidArgumentException(
						"Invalid column name in {$parameterName}: expected string, got " . gettype($columnName)
					);
				}
				
				if (!is_array($columnDefinition)) {
					throw new \InvalidArgumentException(
						"Invalid column definition for '{$columnName}' in {$parameterName}: expected array, got " . gettype($columnDefinition)
					);
				}
			}
		}
	}