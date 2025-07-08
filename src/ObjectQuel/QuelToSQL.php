<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\GetMainEntityInAst;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\GetMainEntityInAstException;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\QuelToSQLConvertToString;
	
	class QuelToSQL {
		
		private EntityStore $entityStore;
		private array $parameters;
		
		/**
		 * QuelToSQL constructor
		 * @param EntityStore $entityStore
		 * @param array $parameters
		 */
		public function __construct(EntityStore $entityStore, array &$parameters) {
			$this->entityStore = $entityStore;
			$this->parameters = &$parameters;
		}
		
		/**
		 * Convert a retrieve statement to SQL
		 * @param AstRetrieve $retrieve
		 * @return string
		 */
		public function convertToSQL(AstRetrieve $retrieve): string {
			return sprintf("SELECT %s%s%s %s %s%s",
				$this->getUnique($retrieve),
				$this->getFieldNames($retrieve),
				$this->getFrom($retrieve),
				$this->getJoins($retrieve),
				$this->getWhere($retrieve),
				$this->getSort($retrieve)
			);
		}

		/**
		 * Searches for a range with a specific name in an array of ranges.
		 * @param array $ranges The list of ranges to search through.
		 * @param string $rangeName The name of the range being searched for.
		 * @return AstRangeDatabase|null The found range or null if it is not found.
		 */
		private function findRangeByName(array $ranges, string $rangeName): ?AstRangeDatabase {
			foreach ($ranges as $range) {
				if ($range->getName() === $rangeName) {
					return $range;
				}
			}
			
			return null;
		}

		/**
		 * Returns the keyword DISTINCT if the query is unique
		 * @param AstRetrieve $retrieve
		 * @return string
		 */
		protected function getUnique(AstRetrieve $retrieve): string {
			return $retrieve->isUnique() ? "DISTINCT " : "";
		}
		
		/**
		 * Returns true if the identifier is an entity, false if not
		 * @param AstInterface $ast
		 * @return bool
		 */
		protected function identifierIsEntity(AstInterface $ast): bool {
			return (
				$ast instanceof AstIdentifier &&
				$ast->getRange() instanceof AstRangeDatabase &&
				!$ast->hasNext()
			);
		}
		
		/**
		 * Retrieves the field names from an AstRetrieve object and converts them to a SQL-compatible string.
		 * @param AstRetrieve $retrieve The AstRetrieve object to process.
		 * @return string The formatted field names as a single string.
		 */
		protected function getFieldNames(AstRetrieve $retrieve): string {
			// Initialize an empty array to store the result
			$result = [];
			
			// Loop through each value in the AstRetrieve object
			foreach ($retrieve->getValues() as $value) {
				// Create a new QuelToSQLConvertToString converter
				$quelToSQLConvertToString = new QuelToSQLConvertToString($this->entityStore, $this->parameters, "VALUES");
				
				// Accept the value for conversion
				$value->accept($quelToSQLConvertToString);
				
				// Get the converted SQL result
				$sqlResult = $quelToSQLConvertToString->getResult();
				
				// Check if the alias is not a complete entity
				if (!empty($sqlResult)) {
					if (($value instanceof AstAlias) && !$this->identifierIsEntity($value->getExpression())) {
						// Add the alias to the SQL result
						$sqlResult .= " as `{$value->getName()}`";
					}
					
					// Add the SQL result to the result array
					if (!$this->isDuplicateField($result, $sqlResult)) {
						$result[] = $sqlResult;
					}
				}
			}
			
			// Convert the array to a string and remove duplicate values
			return implode(",", array_unique($result));
		}
		
		/**
		 * Generate the FROM part of the SQL query based on ranges without JOINS.
		 * @param AstRetrieve $retrieve The retrieve object from which entities are extracted.
		 * @return string The FROM part of the SQL query.
		 */
		protected function getFrom(AstRetrieve $retrieve): string {
			// Obtain all entities used in the retrieve query.
			// This includes identifying the tables and their aliases for use in the query.
			$ranges = $retrieve->getRanges();
			
			// Get all entity names that should be in the FROM clause,
			// but without the entities that are connected via JOINs.
			$tableNames = [];
			
			// Loop through all ranges (entities) in the retrieve query.
			foreach($ranges as $range) {
				// Skip JSON ranges
				if (!$range instanceof AstRangeDatabase) {
					continue;
				}
				
				// Skip ranges with JOIN properties. These go in the JOIN.
				if ($range->getJoinProperty() !== null) {
					continue;
				}
				
				// Get the name of the range
				$rangeName = $range->getName();
				
				// Get the corresponding table name for the entity.
				$owningTable = $this->entityStore->getOwningTable($range->getEntityName());
				
				// Add the table name and alias to the list for the FROM clause.
				$tableNames[] = "`{$owningTable}` as `{$rangeName}`";
			}
			
			// Return nothing if no tables are referenced
			if (empty($tableNames)) {
				return "";
			}
			
			// Combine the table names with commas to generate the FROM part of the SQL query.
			return " FROM " . implode(",", $tableNames);
		}
		
		/**
		 * Generate the WHERE part of the SQL query for the given retrieve operation.
		 * This function processes the conditions of the retrieve and converts them into a SQL-compliant WHERE clause.
		 * @param AstRetrieve $retrieve The retrieve object from which conditions are extracted.
		 * @return string The WHERE part of the SQL query. Returns an empty string if there are no conditions.
		 */
		protected function getWhere(AstRetrieve $retrieve): string {
			// Get the conditions of the retrieve operation.
			$conditions = $retrieve->getConditions();
			
			// Check if there are conditions. If not, return an empty string.
			if ($conditions === null) {
				return "";
			}
			
			// Create a new instance of QuelToSQLConvertToString to convert the conditions to a SQL string.
			// This object will process the Quel conditions and convert them into a format that SQL understands.
			$retrieveEntitiesVisitor = new QuelToSQLConvertToString($this->entityStore, $this->parameters, "WHERE");
			
			// Use the accept method of the conditions to let the QuelToSQLConvertToString object perform the processing.
			// This activates the logic for converting Quel to SQL.
			$conditions->accept($retrieveEntitiesVisitor);
			
			// Get the result, which is now a SQL-compliant string, and add 'WHERE' for the SQL query.
			// This is the result of converting Quel conditions to SQL.
			return "WHERE " . $retrieveEntitiesVisitor->getResult();
		}
		
		/**
		 * Directly manipulate the values in IN() without extra queries
		 * @param AstRetrieve $retrieve
		 * @return string
		 */
		private function getSortUsingIn(AstRetrieve $retrieve): string {
			// Check and retrieve the primary key information
			$primaryKeyInfo = $this->entityStore->fetchPrimaryKeyOfMainRange($retrieve);
			
			if (!is_array($primaryKeyInfo)) {
				return $this->getSortDefault($retrieve);
			}
			
			// Create an AstIdentifier for searching for an IN() in the query
			$astIdentifier = new AstIdentifier($primaryKeyInfo['entityName']);
			
			try {
				$visitor = new GetMainEntityInAst($astIdentifier);
				$retrieve->getConditions()->accept($visitor);
				return $this->getSortDefault($retrieve);
			} catch (GetMainEntityInAstException $exception) {
				$astObject = $exception->getAstObject();
				
				// Convert Quel conditions to a SQL string
				$retrieveEntitiesVisitor = new QuelToSQLConvertToString($this->entityStore, $this->parameters, "SORT");
				$astObject->getIdentifier()->accept($retrieveEntitiesVisitor);
				
				// Process the results into a SQL ORDER BY clause
				$parametersSql = implode(",", array_unique(array_map(function ($e) { return $e->getValue(); }, $astObject->getParameters())));
				return " ORDER BY FIELD(" . $retrieveEntitiesVisitor->getResult() . ", " . $parametersSql . ")";
			}
		}
		
		/**
		 * Regular sort handler
		 * @param AstRetrieve $retrieve
		 * @return string
		 */
		protected function getSortDefault(AstRetrieve $retrieve): string {
			// Get the conditions of the retrieve operation.
			$sort = $retrieve->getSort();
			
			// Check if there are conditions. If not, return an empty string.
			if (empty($sort)) {
				return "";
			}
			
			// Convert the sort elements to SQL
			$sqlSort = [];
			
			foreach($sort as $s) {
				// Create a new instance of QuelToSQLConvertToString to convert the conditions to a SQL string.
				// This object will process the Quel conditions and convert them into a format that SQL understands.
				$retrieveEntitiesVisitor = new QuelToSQLConvertToString($this->entityStore, $this->parameters, "SORT");
				
				// Guide the QUEL through to get a SQL query back
				$s['ast']->accept($retrieveEntitiesVisitor);
				
				// Save the query result
				$sqlSort[] = $retrieveEntitiesVisitor->getResult() . " " . $s["order"];
			}
			
			// Get the result, which is now a SQL-compliant string, and add 'WHERE' for the SQL query.
			// This is the result of converting Quel conditions to SQL.
			return " ORDER BY " . implode(",", $sqlSort);
		}
		
		/**
		 * Generate the ORDER BY part of the SQL query for the given retrieve operation.
		 * This function processes the conditions of the retrieve and converts them into a SQL-compliant ORDER BY clause.
		 * @param AstRetrieve $retrieve
		 * @return string
		 */
		protected function getSort(AstRetrieve $retrieve): string {
			// If the compiler directive @InValuesAreFinal is provided, then we need to sort based on
			// the order within the IN() list
			$compilerDirectives = $retrieve->getDirectives();
			
			if (isset($compilerDirectives['InValuesAreFinal']) && ($compilerDirectives['InValuesAreFinal'] === true)) {
				return $this->getSortUsingIn($retrieve);
			} elseif (!$retrieve->getSortInApplicationLogic()) {
				return $this->getSortDefault($retrieve);
			} else {
				return "";
			}
		}
		
		/**
		 * Generate the JOIN part of the SQL query for the given retrieve operation.
		 * This function analyzes all entities with join properties and converts them
		 * to SQL JOIN instructions.
		 * @param AstRetrieve $retrieve The retrieve object from which entities and their join properties are extracted.
		 * @return string The JOIN part of the SQL query, formatted as a string.
		 */
		protected function getJoins(AstRetrieve $retrieve): string {
			$result = [];
			
			// Get the list of entities involved in the retrieve operation.
			$ranges = $retrieve->getRanges();
			
			// Loop through all entities (ranges) and process those with join properties.
			foreach($ranges as $range) {
				// Skip the range if it is a json data-source
				if (!$range instanceof AstRangeDatabase) {
					continue;
				}
				
				// If the entity has no join property, skip it.
				if ($range->getJoinProperty() === null) {
					continue;
				}
				
				// Get the name and join property of the entity.
				$rangeName = $range->getName();
				$joinProperty = $range->getJoinProperty();
				$entityName = $range->getEntityName();
				
				// Find the table associated with the entity.
				$owningTable = $this->entityStore->getOwningTable($entityName);
				
				// Convert the join condition to a SQL string.
				// This involves translating the join condition to a format that SQL understands.
				$visitor = new QuelToSQLConvertToString($this->entityStore, $this->parameters, "CONDITION");
				$joinProperty->accept($visitor);
				$joinColumn = $visitor->getResult();
				$joinType = $range->isRequired() ? "INNER" : "LEFT";
				
				// Add the SQL JOIN instruction to the result.
				// This results in a LEFT JOIN instruction for the relevant entity.
				$result[] = "{$joinType} JOIN `{$owningTable}` as `{$rangeName}` ON {$joinColumn}";
			}
			
			// Convert the list of JOIN instructions to a single string.
			// Each JOIN instruction is placed on a new line for better readability.
			return implode("\n", $result);
		}
		
		/**
		 * Checks if a SQL field name is already present in the list of fields.
		 * @param array $existingFields Array of existing field names or field groups
		 * @param string $fieldToCheck Field name to check for duplicates
		 * @return bool True if the field already exists, false otherwise
		 */
		protected function isDuplicateField(array $existingFields, string $fieldToCheck): bool {
			// Normalize the field to check (trim whitespace)
			$fieldToCheck = trim($fieldToCheck);
			
			foreach ($existingFields as $existingField) {
				// Case 1: Direct match with an existing field
				if ($existingField === $fieldToCheck) {
					return true;
				}
				
				// Case 2: Field exists in a comma-separated list
				// Split by comma and check each field
				$individualFields = array_map('trim', explode(',', $existingField));
				
				if (in_array($fieldToCheck, $individualFields, true)) {
					return true;
				}
			}
			
			return false;
		}
	}