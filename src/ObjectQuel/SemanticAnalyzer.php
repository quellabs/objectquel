<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAggregate;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRegExp;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearch;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\DetectRestrictedNodeType;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ValidateEntityPropertyExists;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ValidateNoEntityExpressions;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectRangeReferences;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ValidateUnambiguousProperty;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ValidateRangesDeclared;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\ValidateRelationshipPath;
	
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
		 * @throws SemanticException If any validation fails
		 */
		public function validate(AstRetrieve $ast): void {
			// First, recursively validate all nested queries in temporary ranges
			// This ensures inner queries are valid before validating the outer query
			foreach ($ast->getRanges() as $range) {
				if ($range instanceof AstRangeDatabaseSubquery) {
					$this->validate($range->getQuery());
				}
			}
			
			// ==============================================================================
			// Query validation
			// ==============================================================================
			
			// Step 1: Validate that the projection list is not empty
			$this->validatePopulatedProjections($ast);

			// ==============================================================================
			// Range validation
			// ==============================================================================
			
			// Step 1: Validate that all ranges have a unique name
			$this->validateNoDuplicateRanges($ast);
			
			// Step 2: Validate that every database range exists in the entity store
			$this->validateEntityInRangeExists($ast);
			
			// Step 3: Validate that there is at least one range without a VIA clause.
			//         This range will act as the FROM clause of the SELECT query.
			$this->validateAtLeastOneRangeWithoutVia($ast);
			
			// Step 3: Validate that each root identifier links to a range that exists
			$this->processWithVisitor($ast, ValidateRangesDeclared::class, $this->entityStore);
			
			// Step 4: Validate that via clauses do not form circular dependencies
			$this->validateNoCircularViaDependencies($ast);
			
			// ==============================================================================
			// Property validation
			// ==============================================================================
			
			// Step 1: Check for ambiguous properties that the prefilter could not resolve
			$this->validateUnambiguousProperties($ast);
			
			// Step 2: Validate property references against schema
			$this->processWithVisitor($ast, ValidateEntityPropertyExists::class, $this->entityStore);
			
			// Step 3: Validate that referenced relationships lead back to the entity
			$this->validateRelationshipPaths($ast);
			
			// Step 4: Validates that the value list does not directly select entire subquery ranges.
			$this->validateNoBareSubqueryRangesInValueList($ast);
			
			// Step 5: Validates that REGEXP is not used in the VALUES portion of the query
			$this->validateNoRegExpInValueList($ast);
			
			// Step 6: Ensure expressions are not used inappropriately on entities
			$this->processWithVisitor($ast, ValidateNoEntityExpressions::class);
			
			// Step 7: Validate SQL compliance rules (aggregates cannot be put in WHERE)
			$this->validateNoAggregatesInWhereClause($ast);
			
			// Step 8: Validate that all identifiers in each search() call reference the same range
			$this->validateSearchIdentifierRanges($ast);
		}
		
		/**
		 * Validates that all unqualified property references in the query are unambiguous.
		 *
		 * An unqualified property is ambiguous when more than one range in the query
		 * exposes a property with the same name. In that case the engine cannot
		 * determine which range the user intended and a SemanticException is thrown.
		 *
		 * Unknown properties are intentionally not validated here — other validators
		 * such as EntityPropertyExistenceValidator handle that case.
		 *
		 * @param AstRetrieve $ast The AST to validate
		 */
		private function validateUnambiguousProperties(AstRetrieve $ast): void {
			$validator = new ValidateUnambiguousProperty($this->entityStore, $ast->getRanges());
			$ast->accept($validator);
		}
		
		/**
		 * Validates that all database-backed ranges reference existing entities.
		 * @param AstRetrieve $ast The retrieve AST containing the ranges to validate.
		 * @return void
		 * @throws SemanticException Thrown when a referenced entity does not exist.
		 */
		private function validateEntityInRangeExists(AstRetrieve $ast): void {
			foreach ($ast->getRanges() as $range) {
				// Only database ranges can reference entities.
				if (!$range instanceof AstRangeDatabase) {
					continue;
				}
				
				// Skip ranges that do not reference an entity.
				$entityName = $range->getEntityName();
				
				// Ensure the referenced entity exists in the entity store.
				if (!$this->entityStore->exists($entityName)) {
					throw new SemanticException(
						"The range {$range->getName()} references entity '{$entityName}', but that entity does not exist."
					);
				}
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
		 * Ensures the value list does not contain bare subquery ranges.
		 *
		 * Subquery ranges represent derived tables and cannot be hydrated as a single value.
		 * Expressions like `x` are invalid when `x` is a subquery range; users must select
		 * concrete properties such as `x.id`.
		 *
		 * @throws SemanticException If a bare subquery range identifier is selected
		 */
		private function validateNoBareSubqueryRangesInValueList(AstRetrieve $ast): void {
			foreach ($ast->getValues() as $value) {
				$expression = $value->getExpression();
				
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
				$isDatabaseRange =
					$range instanceof AstRangeDatabase ||
					$range instanceof AstRangeDatabaseSubquery ||
					$range instanceof AstRangeJsonSource;
				
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
		private function validateRelationshipPaths(AstRetrieve $ast): void {
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
						$validator = new ValidateRelationshipPath($this->entityStore, $entityName);
						
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
				$visitor = new DetectRestrictedNodeType([AstAggregate::class]);
				
				// Traverse the WHERE clause conditions looking for aggregate functions
				// If any are found, the visitor will throw an exception
				$ast->getConditions()->accept($visitor);
				
				// If we reach this point, no aggregate functions were found (validation passed)
				
			} catch (\Exception $e) {
				// Extract the aggregate function name from the exception message.
				// Exception message contains the class name like "AstCount", so we extract "COUNT"
				$nodeType = strtoupper(substr($e->getMessage(), 3));
				
				// Throw a user-friendly error explaining the SQL rule violation
				throw new SemanticException("Aggregate function {$nodeType} is not allowed in WHERE clause");
			}
		}
		
		/**
		 * Validates that all identifiers inside each search() call reference the same range.
		 *
		 * Mixing ranges inside a single search() call (e.g. search(x.title, y.name, 'term'))
		 * is not supported: the database strategy requires a single table for MATCH...AGAINST
		 * or a LIKE chain, and the in-memory strategy evaluates one row at a time from a
		 * single source. A search() spanning multiple ranges has no defined semantics.
		 *
		 * @param AstRetrieve $ast The AST to validate
		 * @throws SemanticException If any search() call mixes identifiers from different ranges
		 */
		private function validateSearchIdentifierRanges(AstRetrieve $ast): void {
			// Nothing to validate if there is no WHERE clause
			if ($ast->getConditions() === null) {
				return;
			}
			
			// Collect all search() nodes from the WHERE clause tree
			$collector = new CollectNodes(AstSearch::class);
			$ast->getConditions()->accept($collector);
			
			foreach ($collector->getCollectedNodes() as $searchNode) {
				// Build a set of distinct range names referenced by this search() call.
				// Using an associative array as a set so duplicates are naturally collapsed.
				$rangeNames = [];
				
				foreach ($searchNode->getIdentifiers() as $identifier) {
					$rangeName = $identifier->getSourceRange()?->getName();
					
					// Unresolved identifiers have no range yet — skip them here;
					// ValidateRangesDeclared will catch them separately.
					if ($rangeName !== null) {
						$rangeNames[$rangeName] = true;
					}
				}
				
				// More than one distinct range name means the call mixes sources,
				// which has no defined execution semantics.
				if (count($rangeNames) > 1) {
					throw new SemanticException(sprintf(
						"All identifiers in a search() call must reference the same range, but found multiple ranges: %s",
						implode(', ', array_keys($rangeNames))
					));
				}
			}
		}
		
		/**
		 * Validates that the query contains at least one value in the projection list.
		 * An empty retrieve() clause has no defined meaning and cannot be translated
		 * to valid SQL — every query must select at least one property or expression.
		 * @param AstRetrieve $ast The AST to validate
		 * @throws SemanticException If the projection list is empty
		 */
		
		/**
		 * Validates that the query contains at least one value in the projection list.
		 * An empty retrieve() clause has no defined meaning and cannot be translated
		 * to valid SQL — every query must select at least one property or expression.
		 * @param AstRetrieve $ast The AST to validate
		 * @throws SemanticException If the projection list is empty
		 */
		private function validatePopulatedProjections(AstRetrieve $ast): void {
			if (empty($ast->getValues())) {
				throw new SemanticException(
					"The retrieve() clause cannot be empty. " .
					"Specify at least one property or expression to retrieve."
				);
			}
		}
		
		/**
		 * Validates that 'via' clause dependencies between ranges do not form a cycle.
		 *
		 * A cycle occurs when range A depends on range B via its join property, and range B
		 * directly or transitively depends on range A. Such cycles cannot be resolved into
		 * a valid SQL join order and indicate a query construction error.
		 *
		 * Algorithm: Kahn's BFS topological sort over the range dependency graph.
		 *   1. Build a dependency map: for each range, collect all ranges referenced in its
		 *      join property expression (by finding EntityRoot/EntityReference identifiers).
		 *   2. Compute in-degrees and seed a queue with ranges that have no dependencies.
		 *   3. Process the queue; if not all ranges are scheduled, a cycle exists.
		 *
		 * @param AstRetrieve $ast The AST to validate
		 * @throws SemanticException If a circular via dependency is detected
		 */
		private function validateNoCircularViaDependencies(AstRetrieve $ast): void {
			// Collect all range names for reference during dependency extraction
			$allRangeNames = [];
			
			foreach ($ast->getRanges() as $range) {
				$allRangeNames[$range->getName()] = true;
			}
			
			// Build dependency map: rangeName → list of range names it depends on via its join property
			$dependencies = [];
			
			foreach ($ast->getRanges() as $range) {
				$rangeName = $range->getName();
				$dependencies[$rangeName] = [];
				
				$joinProperty = $range->getJoinProperty();
				
				if ($joinProperty === null) {
					// No join property means no via clause — this range has no dependencies
					continue;
				}
				
				// Walk the join expression and collect all EntityRoot/EntityReference identifiers
				// that reference other declared ranges. These are the ranges this one depends on.
				$referencedRanges = $this->extractRangeReferences($joinProperty, $allRangeNames);
				
				foreach ($referencedRanges as $referencedRange) {
					// A range cannot depend on itself
					if ($referencedRange !== $rangeName) {
						$dependencies[$rangeName][] = $referencedRange;
					}
				}
			}
			
			// Precompute reverse adjacency: dependents[$dep] = ranges that depend on $dep
			$dependents = [];
			
			foreach ($dependencies as $rangeName => $deps) {
				foreach ($deps as $dep) {
					$dependents[$dep][] = $rangeName;
				}
			}
			
			// Kahn's algorithm: compute in-degree for each range
			$inDegree = [];
			
			foreach ($dependencies as $rangeName => $deps) {
				$inDegree[$rangeName] = count($deps);
			}
			
			// Seed queue with all ranges that have no dependencies (in-degree 0)
			$queue = [];
			
			foreach ($inDegree as $rangeName => $degree) {
				if ($degree === 0) {
					$queue[] = $rangeName;
				}
			}
			
			$scheduled = 0;
			
			while (!empty($queue)) {
				$current = array_shift($queue);
				$scheduled++;
				
				// Decrement in-degree for all ranges that depend on $current
				foreach ($dependents[$current] ?? [] as $dependent) {
					$inDegree[$dependent]--;
					
					if ($inDegree[$dependent] === 0) {
						$queue[] = $dependent;
					}
				}
			}
			
			// If not all ranges were scheduled, a cycle exists in the via dependency graph
			if ($scheduled !== count($dependencies)) {
				throw new SemanticException(
					"Circular 'via' dependency detected between ranges. " .
					"Range definitions must not form a cycle — each range must be reachable " .
					"from a base range that has no 'via' clause."
				);
			}
		}
		
		/**
		 * Walks an expression tree and collects the names of all declared ranges
		 * referenced by EntityRoot or EntityReference identifier nodes.
		 *
		 * Used by validateNoCircularViaDependencies() to extract which ranges a
		 * join expression depends on, without assuming the expression is a simple
		 * identifier chain — it may contain function calls, binary operators, etc.
		 *
		 * @param AstInterface $expression The join property expression to inspect
		 * @param array<string, bool> $knownRangeNames Map of declared range names for fast lookup
		 * @return string[] List of referenced range names found in the expression
		 */
		private function extractRangeReferences(AstInterface $expression, array $knownRangeNames): array {
			$collector = new CollectRangeReferences($knownRangeNames);
			$expression->accept($collector);
			return $collector->getReferencedRanges();
		}
	}