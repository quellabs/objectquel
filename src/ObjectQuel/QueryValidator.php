<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsNode;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityReferenceValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityPropertyValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\NoExpressionsAllowedOnEntitiesValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\RangeOnlyReferencesOtherRanges;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ValidateRelationInViaValid;
	
	/**
	 * QueryValidator class responsible for validating ObjectQuel query ASTs
	 */
	class QueryValidator {
		
		/**
		 * Entity store containing schema information for validation
		 * @var EntityStore
		 */
		private EntityStore $entityStore;
		
		/**
		 * Constructor - initializes the validator with entity schema information
		 * @param EntityStore $entityStore The entity store containing schema definitions
		 */
		public function __construct(EntityStore $entityStore) {
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Main validation entry point - performs comprehensive query validation
		 * @param AstRetrieve $ast The parsed query AST to validate
		 * @throws QuelException If any validation fails
		 */
		public function validate(AstRetrieve $ast): void {
			// First, recursively validate all nested queries in temporary ranges
			// This ensures inner queries are valid before validating the outer query
			$this->validateNestedQueries($ast);
			
			// Step 1: Validate basic structural integrity
			$this->validateNoDuplicateRanges($ast);
			$this->validateAtLeastOneRangeWithoutVia($ast);
			$this->validateRangesOnlyReferenceOtherRanges($ast);
			
			// Step 2: Validate against schema - ensure entities exist
			$this->processWithVisitor($ast, EntityReferenceValidator::class, $this->entityStore);
			
			// Step 3: Validate relationship definitions in 'via' clauses
			$this->validateRangeViaRelations($ast);
			
			// Step 4: Validate property references against schema
			$this->processWithVisitor($ast, EntityPropertyValidator::class, $this->entityStore);
			
			// Step 5: Ensure expressions are not used inappropriately on entities
			$this->processWithVisitor($ast, NoExpressionsAllowedOnEntitiesValidator::class);
			
			// Step 6: Validate SQL compliance rules (aggregate placement)
			$this->validateNoAggregatesInWhereClause($ast);
		}
		
		/**
		 * Recursively validate all nested queries in temporary range definitions.
		 * Ensures that inner queries are valid before the outer query is validated.
		 *
		 * @param AstRetrieve $ast The query AST containing potential nested queries
		 * @throws QuelException If any nested query validation fails
		 */
		private function validateNestedQueries(AstRetrieve $ast): void {
			foreach ($ast->getRanges() as $range) {
				// Only validate temporary ranges that contain nested queries
				if ($range instanceof AstRangeDatabase && $range->getQuery() !== null) {
					$innerQuery = $range->getQuery();
					
					// Recursively validate the inner query with full validation pipeline
					$this->validate($innerQuery);
				}
			}
		}
		
		/**
		 * Generic method to process AST with a visitor pattern.
		 * @param AstRetrieve $ast The AST to process
		 * @param string $visitorClass The visitor class name to instantiate
		 * @param mixed ...$args Arguments to pass to visitor constructor
		 * @return object The visitor instance after processing
		 */
		private function processWithVisitor(AstRetrieve $ast, string $visitorClass, ...$args): object {
			$visitor = new $visitorClass(...$args);
			$ast->accept($visitor);
			return $visitor;
		}
		
		/**
		 * Validates that no duplicate range names exist in the AST.
		 * In SQL, each table reference must have a unique alias within a query.
		 * This validation ensures ObjectQuel range names follow the same rule.
		 * @param AstRetrieve $ast The AST to validate
		 * @throws QuelException If duplicate range names are detected
		 */
		private function validateNoDuplicateRanges(AstRetrieve $ast): void {
			// Extract all range names from the query into an array
			// Each range (table/entity) must have a unique name for proper SQL generation
			$rangeNames = array_map(fn($e) => $e->getName(), $ast->getRanges());
			
			// Count occurrences of each range name and filter to find duplicates
			// array_count_values() returns ['name1' => 1, 'name2' => 2, 'name3' => 1]
			// array_filter() keeps only entries where count > 1 (duplicates)
			$duplicates = array_filter(array_count_values($rangeNames), fn($count) => $count > 1);
			
			// If any duplicates were found, throw a validation error
			if (!empty($duplicates)) {
				// Extract just the duplicate range names (keys from the filtered array)
				$duplicateNames = array_keys($duplicates);
				
				// Throw an informative error listing all duplicate range names
				// This helps developers identify exactly which ranges are causing conflicts
				throw new QuelException(
					"Duplicate range name(s) detected: " . implode(', ', $duplicateNames) .
					". Each range name must be unique within a query."
				);
			}
		}
		
		/**
		 * Validates that at least one range exists without a 'via' clause to serve as the FROM clause.
		 * In SQL, every query must have a primary FROM table. Other tables are joined to this base.
		 * In ObjectQuel, a range without a 'via' clause serves as this primary table.
		 * @param AstRetrieve $ast The AST to validate
		 * @throws QuelException If no range without 'via' clause exists
		 */
		private function validateAtLeastOneRangeWithoutVia(AstRetrieve $ast): void {
			// Search through all ranges to find at least one that can serve as the main FROM table
			foreach ($ast->getRanges() as $range) {
				// Check if this is a database range (actual table) without a join property
				// A range without a join property means it's not dependent on another table
				// and can serve as the primary data source (FROM clause in SQL)
				if ($range instanceof AstRangeDatabase && $range->getJoinProperty() === null) {
					return; // Found a valid primary range - validation passes
				}
			}
			
			// If we reach here, all ranges have 'via' clauses or join properties
			// This would result in invalid SQL since every table would be a JOIN without a FROM
			throw new QuelException(
				"The query must include at least one range definition without a 'via' clause. " .
				"This serves as the 'FROM' clause in SQL and is essential for defining the data source."
			);
		}
		
		/**
		 * Validates that ranges only reference other ranges in their join properties.
		 * When a range uses a join property to connect to other tables, all entity references
		 * in that join must correspond to other ranges defined in the same query.
		 * @param AstRetrieve $ast The AST to validate
		 * @throws QuelException If ranges reference invalid entities
		 */
		private function validateRangesOnlyReferenceOtherRanges(AstRetrieve $ast): void {
			// Create a validator that ensures join properties only reference other defined ranges
			// This prevents situations where a join tries to reference an entity that isn't included in the query
			$validator = new RangeOnlyReferencesOtherRanges();
			
			// Examine each range to validate its join property references
			foreach ($ast->getRanges() as $range) {
				// Get the join property that defines how this range connects to other tables
				$joinProperty = $range->getJoinProperty();
				
				// Only validate ranges that actually have join properties
				// Main ranges without joins don't need this validation
				if ($joinProperty !== null) {
					try {
						$joinProperty->accept($validator);
					} catch (QuelException $e) {
						// Re-throw with the specific range name for better debugging context
						// This helps identify which range has the invalid reference
						throw new QuelException(sprintf($e->getMessage(), $range->getName()));
					}
				}
			}
		}
		
		/**
		 * Validates that 'via' clause relations are valid and exist.
		 * The 'via' clause in ObjectQuel can specify complex relationship paths through
		 * intermediate entities. This validation ensures all entities and properties
		 * in these relationship paths actually exist in the schema.
		 * @param AstRetrieve $ast The AST to validate
		 * @throws QuelException If invalid relations are found
		 */
		private function validateRangeViaRelations(AstRetrieve $ast): void {
			// Examine each table/range in the query to validate their 'via' relationships
			foreach ($ast->getRanges() as $range) {
				// Get the join property that defines how this range connects to other tables
				$joinProperty = $range->getJoinProperty();
				
				// Only validate ranges that actually have join properties
				// Main tables or ranges without joins don't need 'via' validation
				if ($joinProperty !== null) {
					try {
						// Create a validator to check that all 'via' relations in the join property are valid
						// This verifies that intermediate entities and properties exist in the entity store
						$validator = new ValidateRelationInViaValid($this->entityStore, $range->getEntityName());
						
						// Apply the validator to the join property tree
						// This traverses all parts of the join definition looking for invalid 'via' references
						$joinProperty->accept($validator);
						
					} catch (QuelException $e) {
						// Re-throw the exception with the range name for better error context
						// This helps developers identify which specific range/table has the invalid 'via' relation
						throw new QuelException(sprintf($e->getMessage(), $range->getName()));
					}
				}
			}
		}
		
		/**
		 * Validates that no aggregate functions are present in WHERE clause conditions.
		 * SQL standard prohibits aggregate functions (COUNT, SUM, AVG, MIN, MAX) in WHERE clauses.
		 * They should only appear in SELECT, HAVING, or ORDER BY clauses.
		 * @param AstRetrieve $ast The AST to validate
		 * @throws QuelException If aggregate functions are found in WHERE conditions
		 */
		private function validateNoAggregatesInWhereClause(AstRetrieve $ast): void {
			// Early exit if there are no WHERE conditions to validate
			if ($ast->getConditions() === null) {
				return;
			}
			
			// Define all aggregate function AST node types that are prohibited in WHERE clauses
			// This covers standard SQL aggregate functions and their variations
			$aggregateTypes = [
				AstCount::class,    // COUNT() function
				AstCountU::class,   // COUNT(DISTINCT) function
				AstAvg::class,      // AVG() function
				AstAvgU::class,     // AVG(DISTINCT) function
				AstMin::class,      // MIN() function
				AstMax::class,      // MAX() function
				AstSum::class,      // SUM() function
				AstSumU::class      // SUM(DISTINCT) function
			];
			
			try {
				// Create a visitor that searches for any of the prohibited aggregate
				// function types in the condition tree
				$visitor = new ContainsNode($aggregateTypes);
				
				// Traverse the WHERE clause conditions looking for aggregate functions
				// If any are found, the visitor will throw an exception
				$ast->getConditions()->accept($visitor);
				
				// If we reach this point, no aggregate functions were found (validation passed)
				
			} catch (\Exception $e) {
				// Extract the aggregate function name from the exception message.
				// Exception message contains the class name like "AstCount", so we extract "COUNT"
				$nodeType = strtoupper(substr($e->getMessage(), 3));
				
				// Throw a user-friendly error explaining the SQL rule violation
				throw new QuelException("Aggregate function '{$nodeType}' is not allowed in WHERE clause");
			}
		}
	}