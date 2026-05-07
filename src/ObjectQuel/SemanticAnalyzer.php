<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAggregate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRegExp;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\NodeTypeValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityExistenceValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityPropertyExistenceValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\NoExpressionsAllowedOnEntitiesValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\RangeOnlyReferencesOtherRanges;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ViaClauseValidator;
	
	/**
	 * SemanticAnalyzer class responsible for validating ObjectQuel query ASTs
	 */
	class SemanticAnalyzer {
		
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
		 * @param bool $isSubquery True if we are validating a nested query
		 * @throws SemanticException If any validation fails
		 */
		public function validate(AstRetrieve $ast, bool $isSubquery = false): void {
			// First, recursively validate all nested queries in temporary ranges
			// This ensures inner queries are valid before validating the outer query
			$this->validateNestedQueries($ast);
			
			// Step 1: Validate basic structural integrity
			$this->validateNoRegExpInValueList($ast);
			$this->validateNoDuplicateRanges($ast);
			$this->validateNoEntireSubqueryRangesInValueList($ast);
			$this->validateAtLeastOneRangeWithoutVia($ast);
			$this->validateRangesOnlyReferenceOtherRanges($ast);
			
			// Step 2: Validate against schema - ensure entities exist
			$this->processWithVisitor($ast, EntityExistenceValidator::class, $this->entityStore);
			
			// Step 3: Validate relationship definitions in 'via' clauses
			$this->validateRangeViaRelations($ast);
			
			// Step 4: Validate property references against schema
			$this->processWithVisitor($ast, EntityPropertyExistenceValidator::class, $this->entityStore);
			
			// Step 5: Ensure expressions are not used inappropriately on entities
			$this->processWithVisitor($ast, NoExpressionsAllowedOnEntitiesValidator::class);
			
			// Step 6: Validate SQL compliance rules (aggregate placement)
			$this->validateNoAggregatesInWhereClause($ast);
		}
		
		/**
		 * Recursively validate all nested queries in temporary range definitions.
		 * Ensures that inner queries are valid before the outer query is validated.
		 * @param AstRetrieve $ast The query AST containing potential nested queries
		 * @throws SemanticException If any nested query validation fails
		 */
		private function validateNestedQueries(AstRetrieve $ast): void {
			foreach ($ast->getRanges() as $range) {
				// Only handle AstRangeDatabaseSubquery
				if (!$range instanceof AstRangeDatabaseSubquery) {
					continue;
				}
				
				// Recursively validate the inner query with full validation pipeline
				$this->validate($range->getQuery(), true);
			}
		}
		
		/**
		 * Generic method to process AST with a visitor pattern.
		 * @param AstRetrieve $ast The AST to process
		 * @param class-string<AstVisitorInterface> $visitorClass The visitor class name to instantiate
		 * @param mixed ...$args Arguments to pass to visitor constructor
		 * @return void The visitor instance after processing
		 */
		private function processWithVisitor(AstRetrieve $ast, string $visitorClass, ...$args): void {
			$visitor = new $visitorClass(...$args);
			$ast->accept($visitor);
		}
		
		/**
		 * Validates that no duplicate range names exist in the AST.
		 * In SQL, each table reference must have a unique alias within a query.
		 * This validation ensures ObjectQuel range names follow the same rule.
		 * @param AstRetrieve $ast The AST to validate
		 * @throws SemanticException If duplicate range names are detected
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
				throw new SemanticException(
					"Duplicate range name(s) detected: " . implode(', ', $duplicateNames) .
					". Each range name must be unique within a query."
				);
			}
		}
		
		/**
		 * Validates that no entire subquery ranges appear in the value list.
		 *
		 * Subquery ranges are derived tables with no entity mapping behind them.
		 * Retrieving one as a whole (e.g. "retrieve(x)" where x is a subquery range)
		 * is meaningless — the derived table produces multiple named columns like
		 * "x.id", "x.title" etc., none of which map to a single row key "x".
		 * The result would always be null. Users must specify individual properties
		 * (e.g. "retrieve(x.id)") so the hydrator can look up the correct column.
		 *
		 * This is the outer-query counterpart to validateNoEntireEntitiesInValueList(),
		 * which guards the inner query side.
		 *
		 * @param AstRetrieve $ast The AST to validate
		 * @throws SemanticException If a bare subquery range identifier is found in the value list
		 */
		private function validateNoEntireSubqueryRangesInValueList(AstRetrieve $ast): void {
			foreach ($ast->getValues() as $value) {
				// Fetch the expression
				$expression = $value->getExpression();
				
				// Only interested in identifiers that refer directly to a subquery range
				// without any property access chained on (i.e. "x" not "x.id")
				if (
					$expression instanceof AstIdentifier &&
					$expression->getRange() instanceof AstRangeDatabaseSubquery &&
					!$expression->hasNext()
				) {
					throw new SemanticException(
						"Retrieving an entire subquery range is not allowed. " .
						"Please specify individual properties (e.g. x.id instead of x)."
					);
				}
			}
		}
		
		/**
		 * Validates that at least one range exists without a 'via' clause to serve as the FROM clause.
		 * In SQL, every query must have a primary FROM table. Other tables are joined to this base.
		 * In ObjectQuel, a range without a 'via' clause serves as this primary table.
		 * @param AstRetrieve $ast The AST to validate
		 * @throws SemanticException If no range without 'via' clause exists
		 */
		private function validateAtLeastOneRangeWithoutVia(AstRetrieve $ast): void {
			// Search through all ranges to find at least one that can serve as the main FROM table
			foreach ($ast->getRanges() as $range) {
				// Check if this is a database range (actual table) without a join property
				// A range without a join property means it's not dependent on another table
				// and can serve as the primary data source (FROM clause in SQL)
				$isDatabaseRange = $range instanceof AstRangeDatabase || $range instanceof AstRangeDatabaseSubquery;
				
				if ($isDatabaseRange && $range->getJoinProperty() === null) {
					return; // Found a valid primary range - validation passes
				}
			}
			
			// If we reach here, all ranges have 'via' clauses or join properties
			// This would result in invalid SQL since every table would be a JOIN without a FROM
			throw new SemanticException(
				"The query must include at least one range definition without a 'via' clause. " .
				"This serves as the 'FROM' clause in SQL and is essential for defining the data source."
			);
		}
		
		/**
		 * Validates that ranges only reference other ranges in their join properties.
		 * When a range uses a join property to connect to other tables, all entity references
		 * in that join must correspond to other ranges defined in the same query.
		 * @param AstRetrieve $ast The AST to validate
		 * @throws SemanticException If ranges reference invalid entities
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
					} catch (SemanticException $e) {
						// Re-throw with the specific range name for better debugging context
						// This helps identify which range has the invalid reference
						throw new SemanticException(sprintf($e->getMessage(), $range->getName()));
					}
				}
			}
		}
		
		/**
		 * Validate that the parsed expression is allowed in field lists.
		 * @param AstRetrieve $ast
		 * @throws SemanticException if expression type is not allowed in field lists
		 */
		private function validateNoRegExpInValueList(AstRetrieve $ast): void {
			foreach ($ast->getValues() as $value) {
				if ($value->getExpression() instanceof AstRegExp) {
					throw new SemanticException(
						'Regular expressions are not allowed in the value list. Please remove the regular expression.'
					);
				}
			}
		}
		
		/**
		 * Validates that 'via' clause relations are valid and exist.
		 * The 'via' clause in ObjectQuel can specify complex relationship paths through
		 * intermediate entities. This validation ensures all entities and properties
		 * in these relationship paths actually exist in the schema.
		 * @param AstRetrieve $ast The AST to validate
		 * @throws SemanticException If invalid relations are found
		 */
		private function validateRangeViaRelations(AstRetrieve $ast): void {
			// Examine each table/range in the query to validate their 'via' relationships
			foreach ($ast->getRanges() as $range) {
				// Get the join property that defines how this range connects to other tables
				$joinProperty = $range->getJoinProperty();
				
				// Get the entity name
				$entityName = $range->getEntityName();
				
				// Only validate ranges that actually have join properties
				// Main tables or ranges without joins don't need 'via' validation
				if ($joinProperty !== null && $entityName !== null) {
					try {
						// Create a validator to check that all 'via' relations in the join property are valid
						// This verifies that intermediate entities and properties exist in the entity store
						$validator = new ViaClauseValidator($this->entityStore, $entityName);
						
						// Apply the validator to the join property tree
						// This traverses all parts of the join definition looking for invalid 'via' references
						$joinProperty->accept($validator);
						
					} catch (SemanticException $e) {
						// Re-throw the exception with the range name for better error context
						// This helps developers identify which specific range/table has the invalid 'via' relation
						throw new SemanticException(sprintf($e->getMessage(), $range->getName()));
					}
				}
			}
		}
		
		/**
		 * Validates that no aggregate functions are present in WHERE clause conditions.
		 * SQL standard prohibits aggregate functions (COUNT, SUM, AVG, MIN, MAX) in WHERE clauses.
		 * They should only appear in SELECT, HAVING, or ORDER BY clauses.
		 * @param AstRetrieve $ast The AST to validate
		 * @throws SemanticException If aggregate functions are found in WHERE conditions
		 */
		private function validateNoAggregatesInWhereClause(AstRetrieve $ast): void {
			// Early exit if there are no WHERE conditions to validate
			if ($ast->getConditions() === null) {
				return;
			}
			
			try {
				// Create a visitor that searches for any of the prohibited aggregate
				// function types in the condition tree
				$visitor = new NodeTypeValidator([AstAggregate::class]);
				
				// Traverse the WHERE clause conditions looking for aggregate functions
				// If any are found, the visitor will throw an exception
				$ast->getConditions()->accept($visitor);
				
				// If we reach this point, no aggregate functions were found (validation passed)
				
			} catch (\Exception $e) {
				// Extract the aggregate function name from the exception message.
				// Exception message contains the class name like "AstCount", so we extract "COUNT"
				$nodeType = strtoupper(substr($e->getMessage(), 3));
				
				// Throw a user-friendly error explaining the SQL rule violation
				throw new SemanticException("Aggregate function '{$nodeType}' is not allowed in WHERE clause");
			}
		}
	}