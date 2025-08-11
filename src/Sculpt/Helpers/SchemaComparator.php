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
				'deleted'  => $this->getDeletedColumns($entityColumns, $tableColumns),
				'summary'  => $this->generateChangeSummary($entityColumns, $tableColumns)
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
		 * Generate a summary of changes for easy reporting
		 * @param array $entityColumns Map of property names to definitions from entity model
		 * @param array $tableColumns Map of column names to definitions from database
		 * @return array Summary statistics including counts and flags
		 */
		private function generateChangeSummary(array $entityColumns, array $tableColumns): array {
			$added = count($this->getAddedColumns($entityColumns, $tableColumns));
			$deleted = count($this->getDeletedColumns($entityColumns, $tableColumns));
			$modified = count($this->getModifiedColumns($entityColumns, $tableColumns));
			
			return [
				'total_changes'  => $added + $deleted + $modified,
				'added_count'    => $added,
				'deleted_count'  => $deleted,
				'modified_count' => $modified,
				'has_changes'    => ($added + $deleted + $modified) > 0
			];
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
			// This prevents comparison issues when some definitions are missing optional properties
			$normalized = $this->addDefaultValues($columnDefinition);
			
			// Step 2: Remove irrelevant or comparison-specific properties that shouldn't affect equality
			// This filters out properties like timestamps, comments, or other metadata that
			// don't impact the actual column structure
			$normalized = $this->filterRelevantProperties($normalized);
			
			// Step 3: Standardize property values to a consistent format
			// This handles cases like converting string booleans to actual booleans,
			// normalizing case sensitivity, or standardizing numeric representations
			$normalized = $this->normalizePropertyValues($normalized);
			
			// Step 4: Sort the array keys alphabetically for consistent ordering
			// This ensures that two functionally identical column definitions will have
			// the same array structure regardless of the original key order
			ksort($normalized);
			
			// Return result
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
			
			foreach ($result as $property => $value) {
				$result[$property] = $this->normalizePropertyValue($property, $value);
			}
			
			return $result;
		}
		
		/**
		 * Normalize a specific property value based on its type
		 * @param string $property Property name
		 * @param mixed $value Property value to normalize
		 * @return mixed Normalized property value
		 */
		private function normalizePropertyValue(string $property, mixed $value): mixed {
			if (in_array($property, self::NUMERIC_PROPERTIES) && is_numeric($value)) {
				return (int)$value;
			}
			
			if (in_array($property, self::BOOLEAN_PROPERTIES)) {
				return (bool)$value;
			}
			
			// Normalize string values
			if (is_string($value)) {
				return trim($value);
			}
			
			return $value;
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