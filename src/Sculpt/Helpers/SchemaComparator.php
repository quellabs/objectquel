<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	
	/**
	 * Class SchemaComparator
	 * Compares entity schema (object properties) with database schema (table columns)
	 * to identify changes such as added, modified, or deleted columns.
	 */
	class SchemaComparator {
		
		/**
		 * @var TypeMapper Class responsible for mapping phinx types to php and vice versa
		 */
		private TypeMapper $typeMapper;
		
		/**
		 * SchemaComparator constructor
		 */
		public function __construct() {
			$this->typeMapper = new TypeMapper();
		}
		
		/**
		 * Main public method to compare entity properties with table columns
		 * Identifies added, modified, and deleted columns
		 * @param array $entityColumns Map of property names to definitions from entity model
		 * @param array $tableColumns Map of column names to definitions from database
		 * @return array Structured array of all detected changes
		 */
		public function analyzeSchemaChanges(array $entityColumns, array $tableColumns): array {
			return [
				'added'    => array_diff_key($entityColumns, $tableColumns),
				'modified' => $this->detectColumnChanges($entityColumns, $tableColumns),
				'deleted'  => array_diff_key($tableColumns, $entityColumns)
			];
		}
		
		/**
		 * Identifies columns that exist in both entity and table but have differences
		 * @param array $entityColumns Definition of properties from the entity model
		 * @param array $tableColumns Definition of columns from the database table
		 * @return array Columns that need to be modified in the table
		 */
		private function detectColumnChanges(array $entityColumns, array $tableColumns): array {
			$result = [];
			
			foreach (array_intersect_key($entityColumns, $tableColumns) as $name => $entityColumn) {
				// Fetch column information from database
				$tableColumn = $tableColumns[$name];
				
				// Use the correct column type for comparison - entity's type takes precedence
				$columnType = $entityColumn['type'] ?? 'string';
				
				// Get only the relevant properties for this specific column type
				$relevantProperties = $this->typeMapper->getRelevantProperties($columnType);
				
				// Filter the entity and table columns to include ONLY the relevant properties
				// This automatically ignores any properties not relevant to this column type
				$entityCompare = array_intersect_key($entityColumn, array_flip($relevantProperties));
				$tableCompare = array_intersect_key($tableColumn, array_flip($relevantProperties));

				// Normalize entity column definition by adding default limit if missing
				// This handles cases where the entity relies on database defaults
				if (!isset($entityCompare['limit'])) {
					$entityCompare['limit'] = $this->typeMapper->getDefaultLimit($entityCompare['type']);
				}

				// Normalize database column metadata by adding default limit if missing
				// This handles cases where database metadata doesn't explicitly report standard limits
				if (!isset($tableCompare['limit'])) {
					$tableCompare['limit'] = $this->typeMapper->getDefaultLimit($tableCompare['type']);
				}
				
				// Normalize the values for proper comparison
				$entityCompare = $this->normalizeColumnValues($entityCompare);
				$tableCompare = $this->normalizeColumnValues($tableCompare);
				
				// Only flag as changed if the normalized values differ
				if ($entityCompare != $tableCompare) {
					$result[$name] = [
						'from' => $tableColumn,
						'to'   => $entityColumn,
					];
				}
			}
			
			return $result;
		}
		
		/**
		 * Normalize column values for consistent comparison
		 * @param array $columnDefinition The column definition to normalize
		 * @return array Normalized column definition
		 */
		private function normalizeColumnValues(array $columnDefinition): array {
			$result = $columnDefinition;
			
			// Convert numeric values to their appropriate types
			foreach ($result as $property => $value) {
				// Convert limit, precision, scale to integers
				if (in_array($property, ['limit', 'precision', 'scale']) && is_numeric($value)) {
					$result[$property] = (int)$value;
				}
				
				// Normalize boolean values
				if (in_array($property, ['null', 'unsigned', 'signed', 'identity'])) {
					$result[$property] = (bool)$value;
				}
			}
			
			return $result;
		}
	}