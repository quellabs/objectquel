<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
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
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\AddNamespacesToEntities;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\AddRangeToEntityWhenItsMissing;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\AliasPlugAliasPattern;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsNode;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityExistenceValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityPlugMacros;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityProcessMacro;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityProcessRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityPropertyValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\GatherReferenceJoinValues;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\NoExpressionsAllowedOnEntitiesValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\RangeOnlyReferencesOtherRanges;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\TransformRelationInViaToPropertyLookup;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ValidateRelationInViaValid;
	
	/**
	 * Main ObjectQuel query processor that handles parsing and validation
	 * This class orchestrates the complete query processing pipeline
	 */
	class ObjectQuel {
		private EntityStore $entityStore;
		private int $fullQueryResultCount;
		
		/**
		 * Constructor to inject the EntityManager dependencies.
		 * @param EntityManager $entityManager The entity manager providing store and connection
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityStore = $entityManager->getEntityStore();
			$this->fullQueryResultCount = 0;
		}
		
		/**
		 * Parses a Quel query and returns its validated AST representation.
		 * @param string $query The Quel query string to parse
		 * @return AstRetrieve|null The validated AST or null if parsing fails
		 * @throws QuelException If parsing, validation, or processing fails
		 */
		public function parse(string $query): ?AstRetrieve {
			try {
				// Convert the raw query string into an Abstract Syntax Tree
				$ast = $this->parseQueryToAst($query);
				
				// Run the AST through a comprehensive validation and optimization pipeline
				$this->processAstThroughValidationPipeline($ast);
				
				// The AST is now fully validated
				return $ast;
				
			} catch (ParserException $e) {
				// Handle parsing failures by wrapping in domain-specific exception
				// This provides consistent error handling while preserving original error context
				// ParserException indicates issues in the parsing phase specifically
				throw new QuelException("Query parsing failed: " . $e->getMessage(), 0, $e);
			}
		}
		
		/**
		 * Returns the full query results count when paginating
		 * @return int
		 */
		public function getFullQueryResultCount(): int {
			return $this->fullQueryResultCount;
		}
		
		// ========== PARSING METHODS ==========
		
		/**
		 * Parses the query string into an AST using lexer and parser.
		 * @param string $query The query string to parse
		 * @return AstRetrieve The parsed AST
		 * @throws QuelException If the AST is not a retrieve operation
		 */
		private function parseQueryToAst(string $query): AstRetrieve {
			try {
				// Create a lexer to break the query string into tokens (keywords, identifiers, operators, etc.)
				$lexer = new Lexer($query);
				
				// Create a parser that takes the tokenized input and builds an Abstract Syntax Tree
				$parser = new Parser($lexer);
				
				// Execute the parsing process to generate the AST representation of the query
				// This transforms the linear token sequence into a hierarchical tree structure
				$ast = $parser->parse();
				
				// Ensure the parsed AST represents a RETRIEVE operation
				// This method specifically handles RETRIEVE queries
				if (!$ast instanceof AstRetrieve) {
					throw new QuelException("Invalid query type: expected retrieve operation");
				}
				
				// Return the validated AST ready for further processing
				return $ast;
				
			} catch (LexerException | ParserException $e) {
				// Catch parsing errors and wrap them in a domain-specific exception
				// This provides a consistent error interface while preserving the original error details
				// The original exception is chained for debugging purposes
				throw new QuelException("Query parsing failed: " . $e->getMessage(), 0, $e);
			}
		}
		
		/**
		 * Processes the AST through the complete validation and transformation pipeline.
		 * @param AstRetrieve $ast The AST to process
		 * @return void
		 * @throws QuelException
		 */
		private function processAstThroughValidationPipeline(AstRetrieve $ast): void {
			// Processing phase - Transform and enhance the AST
			$this->runProcessingPhase($ast);
			
			// Validation phase - Ensure AST integrity and correctness
			$this->runValidationPhase($ast);
			
			// Final processing phase - Apply final transformations
			$this->runFinalProcessingPhase($ast);
		}
		
		/**
		 * Executes the processing phase of the validation pipeline.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function runProcessingPhase(AstRetrieve $ast): void {
			$this->processWithVisitor($ast, EntityPlugMacros::class, $ast->getMacros());
			$this->processWithVisitor($ast, EntityProcessRange::class, $ast->getRanges());
			$this->processWithVisitor($ast, EntityProcessMacro::class, $ast->getMacros());
			$this->plugMissingRanges($ast);
			$this->processWithVisitor($ast, AddNamespacesToEntities::class, $this->entityStore, $ast->getRanges(), $ast->getMacros());
			$this->transformViaRelations($ast);
		}
		
		/**
		 * Executes the validation phase of the pipeline.
		 * @throws QuelException
		 */
		private function runValidationPhase(AstRetrieve $ast): void {
			$this->validateNoDuplicateRanges($ast);
			$this->validateAtLeastOneRangeWithoutVia($ast);
			$this->validateRangesOnlyReferenceOtherRanges($ast);
			$this->processWithVisitor($ast, EntityExistenceValidator::class, $this->entityStore);
			$this->validateRangeViaRelations($ast);
			$this->processWithVisitor($ast, EntityPropertyValidator::class, $this->entityStore);
			$this->processWithVisitor($ast, NoExpressionsAllowedOnEntitiesValidator::class);
			$this->validateNoAggregatesInWhereClause($ast);
		}
		
		/**
		 * Executes the final processing phase of the pipeline.
		 */
		private function runFinalProcessingPhase(AstRetrieve $ast): void {
			$this->processWithVisitor($ast, AliasPlugAliasPattern::class);
			$this->addReferencedValuesToQuery($ast);
		}
		
		// ========== VALIDATION METHODS ==========
		
		/**
		 * Validates that no duplicate range names exist in the AST.
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
						// Apply the validator to the join property
						// This checks that all entity references in the join correspond to other ranges in the query
						// Example: if range "orders" joins on "orders.customer_id = customers.id",
						// this validates that "customers" is also defined as a range in the query
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
						// Example: validates that in "User via Profile.user_id", both Profile entity and user_id property exist
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
				AstSumU::class      // SUMU() function
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
		
		// ========== PROCESSING METHODS ==========
		
		/**
		 * Generic method to process AST with a visitor pattern.
		 * @param AstRetrieve $ast The AST to process
		 * @param string $visitorClass The visitor class name
		 * @param mixed ...$args Arguments to pass to visitor constructor
		 * @return object The visitor instance after processing
		 */
		private function processWithVisitor(AstRetrieve $ast, string $visitorClass, ...$args): object {
			$visitor = new $visitorClass(...$args);
			$ast->accept($visitor);
			return $visitor;
		}
		
		/**
		 * Adds missing ranges to entities when they're referenced without explicit range definitions.
		 */
		private function plugMissingRanges(AstRetrieve $ast): void {
			// Use a visitor pattern to traverse the AST and identify entities that are referenced
			// but don't have corresponding range definitions (table joins)
			// AddRangeToEntityWhenItsMissing finds cases like "user.name" where "user" table isn't joined
			$processor = $this->processWithVisitor($ast, AddRangeToEntityWhenItsMissing::class);
			
			// Add all the missing ranges that the visitor discovered
			// Each range represents a table that needs to be joined to satisfy field references
			foreach ($processor->getRanges() as $range) {
				// Add the missing range/table to the query's FROM/JOIN clause
				// This ensures all referenced entities are properly included in the SQL
				$ast->addRange($range);
			}
		}
		
		/**
		 * Transforms 'via' relations into property lookups for SQL generation.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function transformViaRelations(AstRetrieve $ast): void {
			// Process each table/range in the query to handle 'via' relationship definitions
			foreach ($ast->getRanges() as $range) {
				// Get the join property that defines how this range connects to other tables
				$joinProperty = $range->getJoinProperty();
				
				// Skip ranges that don't have join properties (like the main table)
				if ($joinProperty === null) {
					continue;
				}
				
				// Create a converter to transform 'via' relations into direct property references
				// 'Via' relations are indirect relationships that go through intermediate entities
				// Example: User -> Profile via user_id, Profile -> Avatar via profile_id
				// This gets converted to direct property lookups for SQL generation
				$converter = new TransformRelationInViaToPropertyLookup($this->entityStore, $range);
				
				// Transform the join property itself to resolve any 'via' relationships
				// This converts complex relationship definitions into simple field-to-field mappings
				$range->setJoinProperty($converter->processNodeSide($joinProperty));
				
				// Apply the converter to the entire range to handle any other 'via' references
				// This ensures all parts of the range definition are properly transformed
				$range->accept($converter);
			}
		}

		/**
		 * Adds referenced field values to the query's value list for join conditions.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function addReferencedValuesToQuery(AstRetrieve $ast): void {
			// Early exit if there are no conditions to process
			// Without conditions, there won't be any referenced fields to gather
			if ($ast->getConditions() === null) {
				return;
			}
			
			// Use a visitor pattern to traverse the AST and collect all identifiers
			// that are referenced in join conditions but not already in the SELECT list
			// GatherReferenceJoinValues is a specialized visitor that finds these missing references
			$visitor = $this->processWithVisitor($ast, GatherReferenceJoinValues::class);
			
			// Process each identifier that was found by the visitor
			foreach ($visitor->getIdentifiers() as $identifier) {
				// Create a deep copy of the identifier to avoid modifying the original
				// This ensures we don't accidentally affect other parts of the query tree
				$clonedIdentifier = $identifier->deepClone();
				
				// Wrap the cloned identifier in an alias using its complete name
				// This creates a proper SELECT field that can be referenced in joins
				$alias = new AstAlias($identifier->getCompleteName(), $clonedIdentifier);
				
				// Mark this field as invisible in the final result set
				// These are technical fields needed for joins, not user-requested data
				// This prevents them from appearing in the output while still being available for JOIN conditions
				$alias->setVisibleInResult(false);
				
				// Add the aliased field to the query's value list (SELECT clause)
				// This ensures the field is available for join processing even though it's not visible to users
				$ast->addValue($alias);
			}
		}
	}