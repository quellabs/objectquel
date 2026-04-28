<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\GetMainEntityInAst;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\GetMainEntityInAstException;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\Capabilities\NullPlatformCapabilities;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\QuelToSQLConvertToString;
	
	class QuelToSQL {
		
		private EntityStore $entityStore;
		private array $parameters;
		private PlatformCapabilitiesInterface $platform;
		
		/**
		 * QuelToSQL constructor
		 * @param EntityStore $entityStore
		 * @param array $parameters
		 * @param PlatformCapabilitiesInterface $platform Database engine capability descriptor
		 */
		public function __construct(
			EntityStore $entityStore,
			array &$parameters,
			PlatformCapabilitiesInterface $platform = new NullPlatformCapabilities()
		) {
			$this->entityStore = $entityStore;
			$this->parameters = &$parameters;
			$this->platform = $platform;
		}
		
		/**
		 * Convert a retrieve statement to SQL
		 * @param AstRetrieve $retrieve
		 * @return string
		 */
		public function convertToSQL(AstRetrieve $retrieve): string {
			// Build each clause independently, then join non-empty parts with a single
			// space so the output never contains runs of whitespace when optional clauses
			// are absent. Clause order follows the SQL standard:
			//   SELECT … FROM … JOIN … WHERE … GROUP BY … ORDER BY …
			$parts = array_filter([
				"SELECT",
				$this->getUnique($retrieve) . $this->getFieldNames($retrieve),
				$this->getFrom($retrieve),
				$this->getJoins($retrieve),
				$this->getWhere($retrieve),
				$this->getGroupBy($retrieve),
				$this->getSort($retrieve),
			], fn(string $p) => $p !== "");
			
			return implode(" ", $parts);
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
				$quelToSQLConvertToString = new QuelToSQLConvertToString($this->entityStore, $this->parameters, "VALUES", $this->platform);
				
				// Accept the value for conversion
				$value->accept($quelToSQLConvertToString);
				
				// Get the converted SQL result
				$sqlResult = $quelToSQLConvertToString->getResult();
				
				// Check if the alias is not a complete entity
				if (!empty($sqlResult)) {
					if (!$this->identifierIsEntity($value->getExpression())) {
						// Add the alias to the SQL result
						$sqlResult .= " as `{$value->getName()}`";
					}
					
					// Add the SQL result to the result array
					if (!$this->isDuplicateField($result, $sqlResult)) {
						$result[] = $sqlResult;
					}
				}
			}
			
			// array_unique is intentional: buildEntityColumns() can expand a whole entity
			// into a comma-joined column list that may overlap with individually-referenced
			// columns elsewhere in the query. isDuplicateField only guards against adding
			// the same string twice within this loop; array_unique catches cross-expansion
			// duplicates after the fact.
			return implode(",", array_unique($result));
		}
		
		/**
		 * Resolve the physical table name for a database range.
		 *
		 * Both getFrom() and getJoins() need the same logic: if the range has an
		 * explicit table name (e.g. a derived/subquery range), use it directly;
		 * otherwise look it up from the entity store. Centralising this prevents
		 * the two call sites from drifting out of sync and eliminates the null-table
		 * bug that existed in getJoins() when getQuery() was non-null but
		 * getTableName() returned null.
		 *
		 * @param AstRangeDatabase $range
		 * @return string
		 */
		protected function resolveOwningTable(AstRangeDatabase $range): string {
			return $range->getTableName() ?? $this->entityStore->getOwningTable($range->getEntityName());
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
			foreach ($ranges as $range) {
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
				
				// Subquery ranges are emitted as derived tables inline in the FROM clause.
				// Regular ranges reference a physical table looked up from the entity store.
				if ($range->getQuery() !== null) {
					$subSQL = $this->convertToSQL($range->getQuery());
					$tableNames[] = "({$subSQL}) as `{$rangeName}`";
				} else {
					// Get the corresponding table name for the entity.
					$owningTable = $this->resolveOwningTable($range);
					
					// Add the table name and alias to the list for the FROM clause.
					$tableNames[] = "`{$owningTable}` as `{$rangeName}`";
				}
			}
			
			// Return nothing if no tables are referenced
			if (empty($tableNames)) {
				return "";
			}
			
			// Combine the table names with commas to generate the FROM part of the SQL query.
			return "FROM " . implode(",", $tableNames);
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
			$retrieveEntitiesVisitor = new QuelToSQLConvertToString($this->entityStore, $this->parameters, "WHERE", $this->platform);
			
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
				$retrieveEntitiesVisitor = new QuelToSQLConvertToString($this->entityStore, $this->parameters, "SORT", $this->platform);
				$astObject->getIdentifier()->accept($retrieveEntitiesVisitor);
				
				// Process the results into an SQL ORDER BY clause
				$mappedParameters = array_map(function ($e) {
					if (method_exists($e, "getValue")) {
						return $e->getValue();
					} else {
						return "";
					}
				}, $astObject->getParameters());
				
				// Remove empty values, make unique and implode
				$parametersSql = implode(",", array_unique(array_filter($mappedParameters)));
				
				// Return results
				return "ORDER BY FIELD(" . $retrieveEntitiesVisitor->getResult() . ", " . $parametersSql . ")";
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
				$retrieveEntitiesVisitor = new QuelToSQLConvertToString($this->entityStore, $this->parameters, "SORT", $this->platform);
				
				// Guide the QUEL through to get a SQL query back
				$s['ast']->accept($retrieveEntitiesVisitor);
				
				// Save the query result
				$sqlSort[] = $retrieveEntitiesVisitor->getResult() . " " . $s["order"];
			}
			
			// Combine sort expressions into the ORDER BY clause.
			return "ORDER BY " . implode(",", $sqlSort);
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
		 * @param AstRetrieve $retrieve
		 * @return string
		 */
		protected function getGroupBy(AstRetrieve $retrieve): string {
			$groupBy = $retrieve->getGroupBy();
			
			if (empty($groupBy)) {
				return "";
			}
			
			$groupSQL = [];

			foreach($groupBy as $group) {
				$visitor = new QuelToSQLConvertToString($this->entityStore, $this->parameters, "CONDITION", $this->platform);
				$group->accept($visitor);
				$groupSQL[] = $visitor->getResult();
			}
			
			return "GROUP BY " . implode(",", $groupSQL);
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
			foreach ($ranges as $range) {
				// Skip the range if it is a json data-source
				if (!$range instanceof AstRangeDatabase) {
					continue;
				}
				
				// If the entity has no join property, skip it.
				if ($range->getJoinProperty() === null) {
					continue;
				}
				
				// Skip the range if the includeAsJoin flag is clear
				if (!$range->includeAsJoin()) {
					continue;
				}
				
				// Get the name and join property of the entity.
				$rangeName = $range->getName();
				$joinProperty = $range->getJoinProperty();
				$joinType = $range->isRequired() ? "INNER" : "LEFT";
				
				// Convert the join condition to a SQL string.
				// This involves translating the join condition to a format that SQL understands.
				$visitor = new QuelToSQLConvertToString($this->entityStore, $this->parameters, "CONDITION", $this->platform);
				$joinProperty->accept($visitor);
				$joinColumn = $visitor->getResult();
				
				// Subquery ranges are emitted as derived tables inline in the JOIN clause.
				// Regular ranges reference a physical table looked up from the entity store.
				if ($range->getQuery() !== null) {
					$subSQL = $this->convertToSQL($range->getQuery());
					$result[] = "{$joinType} JOIN ({$subSQL}) as `{$rangeName}` ON {$joinColumn}";
				} else {
					$owningTable = $this->resolveOwningTable($range);
					$result[] = "{$joinType} JOIN `{$owningTable}` as `{$rangeName}` ON {$joinColumn}";
				}
			}
			
			// Convert the list of JOIN instructions to a single string.
			// Each JOIN instruction is placed on a new line for better readability.
			return implode("\n", $result);
		}
		
		/**
		 * Checks if a SQL field name is already present in the list of fields.
		 *
		 * @param array $existingFields Array of existing field names or field groups.
		 *   Some entries may be comma-separated strings produced by buildEntityColumns()
		 *   when a whole entity is expanded (e.g. "`u`.`id`,`u`.`name`,`u`.`email`").
		 * @param string $fieldToCheck Field name to check for duplicates
		 * @return bool True if the field already exists, false otherwise
		 *
		 * @note Case 2 splits entries on literal commas to detect membership inside an
		 *   entity-column group. This works correctly for bare column references but will
		 *   produce false negatives (missed duplicates) or false positives if any column
		 *   expression itself contains a comma — e.g. FIELD(col,1,2), CONCAT(a,b), or
		 *   CASE expressions. The correct long-term fix is to store individual field
		 *   strings in $result rather than joining them in buildEntityColumns(), making
		 *   a plain in_array() check sufficient and eliminating the split entirely.
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