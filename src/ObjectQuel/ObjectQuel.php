<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\OneToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\RequiredRelation;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExists;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\AddNamespacesToEntities;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\AddRangeToEntityWhenItsMissing;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\AliasPlugAliasPattern;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsCheckIsNullForRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsNode;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ContainsRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityExistenceValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityPlugMacros;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityProcessMacro;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityProcessRange;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\EntityPropertyValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\GatherReferenceJoinValues;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\GetMainEntityInAst;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\GetMainEntityInAstException;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\NoExpressionsAllowedOnEntitiesValidator;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\RangeOnlyReferencesOtherRanges;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\TransformRelationInViaToPropertyLookup;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ValidateRelationInViaValid;
	
	/**
	 * Main ObjectQuel query processor that handles parsing, validation, and SQL conversion.
	 *
	 * This class orchestrates the complete query processing pipeline from parsing ObjectQuel
	 * syntax through validation and transformation to SQL generation.
	 */
	class ObjectQuel {
		private EntityStore $entityStore;
		private DatabaseAdapter $connection;
		private int $fullQueryResultCount;
		
		/**
		 * Constructor to inject the EntityManager dependencies.
		 * @param EntityManager $entityManager The entity manager providing store and connection
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityStore = $entityManager->getEntityStore();
			$this->connection = $entityManager->getConnection();
			$this->fullQueryResultCount = 0;
		}
		
		/**
		 * Parses a Quel query and returns its validated AST representation.
		 * @param string $query The Quel query string to parse
		 * @param array $parameters Query parameters for substitution
		 * @return AstRetrieve|null The validated AST or null if parsing fails
		 * @throws QuelException If parsing, validation, or processing fails
		 */
		public function parse(string $query, array $parameters = []): ?AstRetrieve {
			try {
				// Convert the raw query string into an Abstract Syntax Tree
				$ast = $this->parseQueryToAst($query);
				
				// Run the AST through a comprehensive validation and optimization pipeline
				$this->processAstThroughValidationPipeline($ast);
				
				// Check if the query requires pagination (has window clauses)
				if ($this->requiresPagination($ast)) {
					// Apply pagination logic using the provided parameters
					// This may involve primary key fetching, result counting, and query modification
					// Parameters are needed for pagination to work with bound values in conditions
					$this->processPagination($ast, $parameters);
				}
				
				// The AST is now fully validated, optimized, and ready for SQL generation
				return $ast;
				
			} catch (ParserException $e) {
				// Handle parsing failures by wrapping in domain-specific exception
				// This provides consistent error handling while preserving original error context
				// ParserException indicates issues in the parsing phase specifically
				throw new QuelException("Query parsing failed: " . $e->getMessage(), 0, $e);
			}
		}
		
		/**
		 * Convert AstRetrieve node to SQL
		 * @param AstRetrieve $retrieve The AST to convert
		 * @param array $parameters Query parameters (passed by reference)
		 * @return string The generated SQL query
		 */
		public function convertToSQL(AstRetrieve $retrieve, array &$parameters): string {
			$quelToSQL = new QuelToSQL($this->entityStore, $parameters);
			return $quelToSQL->convertToSQL($retrieve);
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
			$this->setOnlyRangeToRequired($ast);
			$this->setRangesRequiredThroughAnnotations($ast);
			$this->setRangesRequiredThroughWhereClause($ast);
			$this->setRangesNotRequiredThroughNullChecks($ast);
			$this->processExistsOperators($ast);
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
		 * Sets the single range in an AST retrieve operation as required.
		 * Only applies the required flag when exactly one range exists.
		 * @param AstRetrieve $ast The AST retrieve object containing ranges
		 * @return void
		 */
		private function setOnlyRangeToRequired(AstRetrieve $ast): void {
			// Get all ranges from the AST retrieve object
			$ranges = $ast->getRanges();
			
			// Only set as required if there's exactly one range
			// This prevents ambiguity when multiple ranges exist
			if (count($ranges) === 1) {
				// Mark the single range as required
				$ranges[0]->setRequired();
			}
		}
		
		/**
		 * Sets ranges as required based on RequiredRelation annotations.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function setRangesRequiredThroughAnnotations(AstRetrieve $ast): void {
			// Get the main/primary table/range for this query
			// This serves as the reference point for checking relationship annotations
			$mainRange = $this->getMainRange($ast);
			
			// Early exit if we can't determine the main range
			// Without a main range, we can't properly evaluate relationship annotations
			if ($mainRange === null) {
				return;
			}
			
			// Examine each table/range in the query to check for annotation-based requirements
			foreach ($ast->getRanges() as $range) {
				// Only process ranges that meet the criteria for annotation-based requirement checking
				// This filters out ranges that are already required or don't have join properties
				if (!$this->shouldSetRangeRequired($range)) {
					continue;
				}
				
				// Get the join property that defines how this range connects to other tables
				// This contains the left and right identifiers that form the join condition
				$joinProperty = $range->getJoinProperty();
				$left = $joinProperty->getLeft();
				$right = $joinProperty->getRight();
				
				// Normalize the join relationship so that $left always represents the current range
				// Join conditions can be written as "A.id = B.id" or "B.id = A.id"
				// We need consistent ordering to properly check annotations
				if ($right->getEntityName() === $range->getEntityName()) {
					// Swap left and right if right side matches current range
					[$left, $right] = [$right, $left];
				}
				
				// Verify that after normalization, left side actually belongs to current range
				// This is a safety check to ensure our relationship mapping is correct
				if ($left->getEntityName() === $range->getEntityName()) {
					// Check entity annotations to determine if this relationship should be required
					// This examines @RequiredRelation or similar annotations that force INNER JOINs
					$this->checkAndSetRangeRequired($mainRange, $range, $left, $right);
				}
			}
		}
		
		/**
		 * Sets ranges as required when they're used in WHERE clause conditions.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function setRangesRequiredThroughWhereClause(AstRetrieve $ast): void {
			// Early exit if there are no WHERE conditions to analyze
			// Without conditions, there are no references to examine
			if ($ast->getConditions() === null) {
				return;
			}
			
			// Examine each table/range involved in the query
			foreach ($ast->getRanges() as $range) {
				try {
					// Only process ranges that are currently marked as NOT required (optional)
					// Required ranges don't need this check since they're already marked as needed
					if ($range->isRequired()) {
						continue;
					}
				
					// Create a visitor to search for any references to this specific range/table
					// in the WHERE clause conditions
					$visitor = new ContainsRange($range->getName());
					
					// Traverse the condition tree looking for references to this range
					// The visitor will detect patterns like "table.field = value" or "table.id IN (...)"
					$ast->getConditions()->accept($visitor);
					
					// If we reach this point, no references to this range were found in WHERE clause
					// The range remains optional (no action needed)
					
				} catch (\Exception $e) {
					// Exception indicates that references to this range were found in WHERE conditions
					// When a table/range is referenced in WHERE clause, it must be required
					// because filtering on a field requires the record to exist (INNER JOIN behavior)
					// Mark this range as required, converting it from LEFT JOIN to INNER JOIN
					$range->setRequired();
				}
			}
		}
		
		/**
		 * Sets ranges as not required when NULL checks are used in WHERE clause.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function setRangesNotRequiredThroughNullChecks(AstRetrieve $ast): void {
			// Early exit if there are no WHERE conditions to analyze
			// Without conditions, there can't be any NULL checks to process
			if ($ast->getConditions() === null) {
				return;
			}
			
			// Examine each table/range involved in the query
			foreach ($ast->getRanges() as $range) {
				// Only process ranges that are currently marked as required
				// Non-required ranges don't need this optimization check
				if ($range->isRequired()) {
					try {
						// Create a specialized visitor to check if there are IS NULL conditions
						// for fields belonging to this specific range/table
						$visitor = new ContainsCheckIsNullForRange($range->getName());
						
						// Traverse the condition tree to look for NULL checks on this range
						// The visitor will examine all conditions and detect patterns like "table.field IS NULL"
						$ast->getConditions()->accept($visitor);
						
						// If we reach this point, no NULL checks were found for this range
						// The range remains required (no action needed)
						
					} catch (\Exception $e) {
						// Exception indicates that IS NULL checks were found for this range
						// When a field is checked for NULL, the associated table/range becomes optional
						// because NULL checks imply the record might not exist (LEFT JOIN scenario)
						// Mark this range as not required, converting it from INNER JOIN to LEFT JOIN
						$range->setRequired(false);
					}
				}
			}
		}
		
		/**
		 * Processes EXISTS operators by removing them from conditions and setting ranges as required.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function processExistsOperators(AstRetrieve $ast): void {
			// Get the WHERE conditions from the query
			$conditions = $ast->getConditions();
			
			// Early exit if there are no conditions to process
			// Without conditions, there can't be any EXISTS operators to handle
			if ($conditions === null) {
				return;
			}
			
			// Extract all EXISTS operators from the condition tree
			// This method traverses the AST to find EXISTS clauses and removes them from their current location
			// Returns a list of the extracted EXISTS operators for further processing
			$existsList = $this->extractExistsOperators($ast, $conditions);
			
			// Process each extracted EXISTS operator
			foreach ($existsList as $exists) {
				// Convert the EXISTS operator into a required range/join
				// Instead of using EXISTS in the WHERE clause, this marks the related table/range as required
				// This is an optimization that can convert EXISTS subqueries into more efficient JOINs
				$this->setRangeRequiredForExists($ast, $exists);
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
		
		// ========== PAGINATION METHODS ==========
		
		/**
		 * Determines if the query requires pagination processing.
		 * @param AstRetrieve $ast
		 * @return bool
		 */
		private function requiresPagination(AstRetrieve $ast): bool {
			return $ast->getWindow() !== null && !$ast->getSortInApplicationLogic();
		}
		
		/**
		 * Processes pagination for the query.
		 * @param AstRetrieve $ast
		 * @param array $parameters
		 * @return void
		 */
		private function processPagination(AstRetrieve $ast, array $parameters): void {
			// Get primary key information for the main table/range being queried
			// This is essential for pagination as we need to identify unique records
			$primaryKeyInfo = $this->entityStore->fetchPrimaryKeyOfMainRange($ast);
			
			// If we can't determine the primary key, we can't safely paginate
			// This might happen with complex queries, views, or entities without proper key definitions
			if ($primaryKeyInfo === null) {
				return;
			}
			
			// Check for query directives that might affect pagination behavior
			$directives = $ast->getDirectives();
			
			// Look for the 'InValuesAreFinal' directive which indicates that any IN conditions
			// in the query are already finalized and don't need additional validation/processing
			// This is an optimization flag that can skip the validation phase of pagination
			$skipValidation = isset($directives['InValuesAreFinal']) && $directives['InValuesAreFinal'] === true;
			
			// Choose the appropriate pagination strategy based on the directive
			if ($skipValidation) {
				// Fast path: Skip the validation step and process pagination directly
				// Used when we know the IN conditions are already properly constructed
				$this->processPaginationSkippingValidation($ast, $parameters, $primaryKeyInfo);
			} else {
				// Standard path: Use the full validation approach which fetches all primary keys first
				// This is the safer, more comprehensive method for most queries
				$this->processPaginationWithValidation($ast, $parameters, $primaryKeyInfo);
			}
		}
		
		/**
		 * Processes pagination by fetching all primary keys first.
		 * @param AstRetrieve $ast
		 * @param array $parameters
		 * @param array $primaryKeyInfo
		 * @return void
		 */
		private function processPaginationWithValidation(AstRetrieve $ast, array $parameters, array $primaryKeyInfo): void {
			// First pass: Execute a lightweight query to fetch only the primary keys
			// This avoids loading full records when we only need to determine pagination boundaries
			$primaryKeys = $this->fetchAllPrimaryKeysForPagination($ast, $parameters, $primaryKeyInfo);
			
			// Store the total count of results before pagination for potential use elsewhere
			// (e.g., displaying "showing X of Y results" to users)
			$this->fullQueryResultCount = count($primaryKeys);
			
			// Early exit if no records match the query conditions
			if (empty($primaryKeys)) {
				return;
			}
			
			// Apply pagination logic to get only the subset of primary keys for the requested page
			// Uses the window (offset) and window size (limit) from the AST
			$filteredKeys = $this->getPageSubset($primaryKeys, $ast->getWindow(), $ast->getWindowSize());
			
			// Handle edge case where pagination parameters result in no valid results
			// (e.g., requesting page 100 when there are only 50 total pages)
			if (empty($filteredKeys)) {
				// Add a condition that will never match (like "1=0") to make the query return empty results
				// This is more efficient than letting the database process the full query
				$this->addImpossibleCondition($ast);
				return;
			}
			
			// Modify the original query to include an IN condition that limits results
			// to only the primary keys we determined should be on this page
			// This ensures the final query returns exactly the records we want, in the right order
			$this->addInConditionForPagination($ast, $primaryKeyInfo, $filteredKeys);
		}
		
		/**
		 * Processes pagination by directly manipulating existing IN() values.
		 * @param AstRetrieve $ast
		 * @param array $parameters
		 * @param array $primaryKeyInfo
		 * @return void
		 */
		private function processPaginationSkippingValidation(AstRetrieve $ast, array $parameters, array $primaryKeyInfo): void {
			try {
				// Fetch IN() statement
				$astIdentifier = $this->createPrimaryKeyIdentifier($primaryKeyInfo);
				$visitor = new GetMainEntityInAst($astIdentifier);
				$ast->getConditions()->accept($visitor);
				
				// If no exception, fall back to validation method
				$this->processPaginationWithValidation($ast, $parameters, $primaryKeyInfo);
				
			} catch (GetMainEntityInAstException $exception) {
				$astObject = $exception->getAstObject();
				$this->fullQueryResultCount = count($astObject->getParameters());
				
				$filteredParams = array_slice(
					$astObject->getParameters(),
					$ast->getWindow() * $ast->getWindowSize(),
					$ast->getWindowSize()
				);
				
				$astObject->setParameters($filteredParams);
			}
		}
		
		// ========== HELPER METHODS ==========
		
		/**
		 * Returns the main range (first range without join property).
		 * @param AstRetrieve $ast
		 * @return AstRangeDatabase|null
		 */
		private function getMainRange(AstRetrieve $ast): ?AstRangeDatabase {
			foreach ($ast->getRanges() as $range) {
				if ($range instanceof AstRangeDatabase && $range->getJoinProperty() === null) {
					return $range;
				}
			}
			
			return null;
		}
		
		/**
		 * Checks if a range should be set as required based on its join property structure.
		 * @param $range
		 * @return bool
		 */
		private function shouldSetRangeRequired($range): bool {
			$joinProperty = $range->getJoinProperty();
			
			return $joinProperty instanceof AstExpression &&
				$joinProperty->getLeft() instanceof AstIdentifier &&
				$joinProperty->getRight() instanceof AstIdentifier;
		}
		
		/**
		 * Checks annotations and sets range as required if needed.
		 * @param AstRangeDatabase $mainRange
		 * @param AstRangeDatabase $range
		 * @param AstIdentifier $left
		 * @param AstIdentifier $right
		 * @return void
		 */
		private function checkAndSetRangeRequired(AstRangeDatabase $mainRange, AstRangeDatabase $range, AstIdentifier $left, AstIdentifier $right): void {
			// Determine which identifier belongs to the main range to establish perspective
			$isMainRange = $right->getRange() === $mainRange;
			
			// Extract property and entity names from the perspective of the "own" side
			// If right is main range, then right is "own" and left is "related", otherwise vice versa
			$ownPropertyName = $isMainRange ? $right->getName() : $left->getName();
			$ownEntityName = $isMainRange ? $right->getEntityName() : $left->getEntityName();
			
			// Extract property and entity names from the perspective of the "related" side
			$relatedPropertyName = $isMainRange ? $left->getName() : $right->getName();
			$relatedEntityName = $isMainRange ? $left->getEntityName() : $right->getEntityName();
			
			// Retrieve all annotations for the "own" entity from the entity store
			$entityAnnotations = $this->entityStore->getAnnotations($ownEntityName);
			
			// Iterate through each set of annotations for the entity
			foreach ($entityAnnotations as $annotations) {
				// Quick check: skip if this annotation set doesn't contain required relation annotations
				if (!$this->containsRequiredRelationAnnotation($annotations->toArray())) {
					continue;
				}
				
				// Examine each individual annotation in the set
				foreach ($annotations as $annotation) {
					// Check if this annotation defines a required relationship that matches our current relationship
					// (comparing entity names and property names on both sides)
					if ($this->isMatchingRequiredRelation($annotation, $relatedEntityName, $ownPropertyName, $relatedPropertyName)) {
						$range->setRequired();
						return;
					}
				}
			}
		}
		
		/**
		 * Checks if annotations contain RequiredRelation.
		 * @param array $annotations
		 * @return bool
		 */
		private function containsRequiredRelationAnnotation(array $annotations): bool {
			foreach ($annotations as $annotation) {
				if ($annotation instanceof RequiredRelation) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Checks if annotation matches the required relation criteria.
		 * @param $annotation
		 * @param string $relatedEntityName
		 * @param string $ownPropertyName
		 * @param string $relatedPropertyName
		 * @return bool
		 */
		private function isMatchingRequiredRelation($annotation, string $relatedEntityName, string $ownPropertyName, string $relatedPropertyName): bool {
			return ($annotation instanceof ManyToOne || $annotation instanceof OneToOne) &&
				$annotation->getTargetEntity() === $relatedEntityName &&
				$annotation->getRelationColumn() === $ownPropertyName &&
				$annotation->getInversedBy() === $relatedPropertyName;
		}
		
		/**
		 * Extracts EXISTS operators from conditions and handles different scenarios.
		 * @param AstRetrieve $ast
		 * @param $conditions
		 * @return array
		 */
		private function extractExistsOperators(AstRetrieve $ast, $conditions): array {
			if ($conditions instanceof AstExists) {
				$ast->setConditions(null);
				return [$conditions];
			}
			
			if ($conditions instanceof AstBinaryOperator) {
				$existsList = [];
				$this->extractExistsFromBinaryOperator($ast, $conditions, $existsList);
				return $existsList;
			}
			
			return [];
		}
		
		/**
		 * Recursively extracts EXISTS operators from binary operations.
		 * @param AstInterface|null $parent
		 * @param AstInterface $item
		 * @param array $list
		 * @param bool $parentLeft
		 * @return void
		 */
		private function extractExistsFromBinaryOperator(?AstInterface $parent, AstInterface $item, array &$list, bool $parentLeft = false): void {
			if (!$this->canProcessBinaryOperations($item)) {
				return;
			}
			
			// Process branches recursively
			if ($item->getLeft() instanceof AstBinaryOperator) {
				$this->extractExistsFromBinaryOperator($item, $item->getLeft(), $list, true);
			}
			
			if ($item->getRight() instanceof AstBinaryOperator) {
				$this->extractExistsFromBinaryOperator($item, $item->getRight(), $list, false);
			}
			
			// Handle special case: exists AND/OR exists as only condition
			$left = $item->getLeft();
			$right = $item->getRight();
			
			if ($parent instanceof AstRetrieve && $left instanceof AstExists && $right instanceof AstExists) {
				$list[] = $left;
				$list[] = $right;
				$parent->setConditions(null);
				return;
			}
			
			// Handle EXISTS in left branch
			if ($left instanceof AstExists) {
				$list[] = $left;
				$this->setChildInParent($parent, $right, $parentLeft);
			}
			
			// Handle EXISTS in right branch
			if ($right instanceof AstExists) {
				$list[] = $right;
				$this->setChildInParent($parent, $left, $parentLeft);
			}
		}
		
		/**
		 * Checks if the item can be processed for binary operations.
		 * @param AstInterface $item
		 * @return bool
		 */
		private function canProcessBinaryOperations(AstInterface $item): bool {
			return
				$item instanceof AstTerm ||
				$item instanceof AstBinaryOperator ||
				$item instanceof AstExpression ||
				$item instanceof AstFactor;
		}
		
		/**
		 * Sets the appropriate child relationship between parent and item nodes.
		 * @param AstInterface|null $parent
		 * @param AstInterface $item
		 * @param bool $parentLeft
		 * @return void
		 */
		private function setChildInParent(?AstInterface $parent, AstInterface $item, bool $parentLeft): void {
			// Handle special case for AstRetrieve nodes - they use conditions instead of left/right children
			if ($parent instanceof AstRetrieve) {
				$parent->setConditions($item);
				return;
			}
			
			// Check if the parent node supports binary operations (has left/right children)
			// If not, we can't process it further
			if (!$this->canProcessBinaryOperations($parent)) {
				return;
			}
			
			// For binary operations, determine which side (left or right) to assign the item
			if ($parentLeft) {
				// Assign item as the left operand of the binary operation
				$parent->setLeft($item);
			} else {
				// Assign item as the right operand of the binary operation
				$parent->setRight($item);
			}
		}
		
		/**
		 * Sets the range as required for an EXISTS operation.
		 * @param AstRetrieve $ast
		 * @param AstExists $exists
		 * @return void
		 */
		private function setRangeRequiredForExists(AstRetrieve $ast, AstExists $exists): void {
			$existsRange = $exists->getIdentifier()->getRange();
			
			foreach ($ast->getRanges() as $range) {
				if ($range->getName() === $existsRange->getName()) {
					$range->setRequired();
					break;
				}
			}
		}
		
		/**
		 * Factory method to create primary key identifiers.
		 * @param array $primaryKeyInfo
		 * @return AstIdentifier
		 */
		private function createPrimaryKeyIdentifier(array $primaryKeyInfo): AstIdentifier {
			$astIdentifier = new AstIdentifier($primaryKeyInfo['entityName']);
			$astIdentifier->setRange(clone $primaryKeyInfo['range']);
			$astIdentifier->setNext(new AstIdentifier($primaryKeyInfo['primaryKey']));
			return $astIdentifier;
		}
		
		/**
		 * Adds an impossible condition (1 = 0) for empty result sets.
		 * @param AstRetrieve $ast
		 * @return void
		 */
		private function addImpossibleCondition(AstRetrieve $ast): void {
			$condition = new AstBinaryOperator(new AstNumber(1), new AstNumber(0), "=");
			
			if ($ast->getConditions() === null) {
				$ast->setConditions($condition);
			} else {
				$ast->setConditions(new AstBinaryOperator($ast->getConditions(), $condition, "AND"));
			}
		}
		
		/**
		 * Adds IN condition for pagination filtering.
		 * @param AstRetrieve $ast
		 * @param array $primaryKeyInfo
		 * @param array $filteredKeys
		 * @return void
		 */
		private function addInConditionForPagination(AstRetrieve $ast, array $primaryKeyInfo, array $filteredKeys): void {
			$astIdentifier = $this->createPrimaryKeyIdentifier($primaryKeyInfo);
			$parameters = array_map(fn($item) => new AstNumber($item), $filteredKeys);
			
			// Check if AstIn already exists and replace its parameters
			try {
				$visitor = new GetMainEntityInAst($astIdentifier);
				$ast->getConditions()->accept($visitor);
			} catch (GetMainEntityInAstException $exception) {
				$exception->getAstObject()->setParameters($parameters);
				return;
			}
			
			// Create new AstIn condition
			$astIn = new AstIn($astIdentifier, $parameters);
			
			if ($ast->getConditions() === null) {
				$ast->setConditions($astIn);
			} else {
				$ast->setConditions(new AstBinaryOperator($ast->getConditions(), $astIn, "AND"));
			}
		}
		
		/**
		 * Fetches all primary keys for pagination by temporarily modifying the query.
		 * @param AstRetrieve $ast
		 * @param array $parameters
		 * @param array $primaryKeyInfo
		 * @return array
		 */
		private function fetchAllPrimaryKeysForPagination(AstRetrieve $ast, array $parameters, array $primaryKeyInfo): array {
			// Store original state
			$originalValues = $ast->getValues();
			$originalUnique = $ast->getUnique();
			
			try {
				// Modify query to get only primary keys
				$ast->setUnique(true);
				$astIdentifier = $this->createPrimaryKeyIdentifier($primaryKeyInfo);
				$ast->setValues([new AstAlias("primary", $astIdentifier)]);
				
				// Execute modified query
				$sql = $this->convertToSQL($ast, $parameters);
				return $this->connection->GetCol($sql, $parameters);
				
			} finally {
				// Always restore original state
				$ast->setValues($originalValues);
				$ast->setUnique($originalUnique);
			}
		}
		
		/**
		 * Gets the subset of primary keys for the current page.
		 * @param array $primaryKeys
		 * @param int $window
		 * @param int $windowSize
		 * @return array
		 */
		private function getPageSubset(array $primaryKeys, int $window, int $windowSize): array {
			return array_slice($primaryKeys, $window * $windowSize, $windowSize);
		}
	}