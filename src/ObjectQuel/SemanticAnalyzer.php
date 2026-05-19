<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel;
	
	use Quellabs\ObjectQuel\Annotations\Orm\SourceField;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
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
	use Quellabs\ObjectQuel\Planner\Helpers\AstUtilities;
	use Quellabs\ObjectQuel\Planner\Helpers\RangeUtilities;
	
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
		 * @throws SemanticException|EntityResolutionException If any validation fails
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
			$this->validateNoSubqueryRangeInOuterProjection($ast);
			
			// Step 4b: Validates that a subquery's own projection does not select a bare entity.
			//           A subquery defines a column-set contract; retrieve(y) erases that contract
			//           and makes the derived table's schema implicit and unverifiable.
			$this->validateNoEntityInSubqueryProjection($ast);
			
			// Step 4b2: Validates that the outer projection does not select a bare json source range.
			//            retrieve(y) where y is a json source produces empty arrays at runtime
			//            because the engine has no schema to hydrate fields from.
			$this->validateNoBareJsonSourceInProjection($ast);
			
			// Step 4c: Validates that WHERE conditions only reference fields that subquery ranges
			//          actually export. A subquery's projection is its contract; reaching into
			//          unexported fields must be a compile-time error, not a silent patch.
			$this->validateSubqueryRangeWhereReferences($ast);
			
			// Step 4d: Validates that the outer retrieve list only references fields that subquery
			//          ranges actually export. Mirrors 4c for the projection rather than WHERE.
			$this->validateSubqueryRangeProjectionReferences($ast);
			
			// Step 5: Validates that REGEXP is not used in the VALUES portion of the query
			$this->validateNoRegExpInValueList($ast);
			
			// Step 6: Ensure expressions are not used inappropriately on entities
			$this->processWithVisitor($ast, ValidateNoEntityExpressions::class);
			
			// Step 7: Validate SQL compliance rules (aggregates cannot be put in WHERE)
			$this->validateNoAggregatesInWhereClause($ast);
			
			// Step 8: Validate that all identifiers in each search() call reference the same range
			$this->validateSearchIdentifierRanges($ast);
			
			// Step 9: Validate that @SourceField annotations without an explicit range are not
			//         ambiguous when multiple JSON sources are present in the same query
			$this->validateUnambiguousSourceFieldAnnotations($ast);
			
			// Step 10: Validate that no aggregate references both database and non-database
			//          ranges — such aggregates have no defined execution strategy
			$this->validateNoMixedRangeAggregates($ast);
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
		 * Validates that WHERE conditions do not reference fields that subquery ranges do not export.
		 *
		 * A subquery range's projection list is its contract with the outer query.
		 * Referencing x.id in a WHERE clause when the subquery for x only exports hello
		 * is a semantic error: the field does not exist from the outer query's perspective.
		 *
		 * This check must run at semantic analysis time rather than in the optimizer so that
		 * it fires unconditionally regardless of which optimizer path the query takes.
		 *
		 * @param AstRetrieve $ast The AST to validate
		 * @throws SemanticException If a WHERE clause references an unexported subquery field
		 */
		private function validateSubqueryRangeWhereReferences(AstRetrieve $ast): void {
			// No WHERE clause means nothing to check
			if ($ast->getConditions() === null) {
				return;
			}
			
			// Build a map of subquery range name → exported field names for fast lookup.
			// By this point, validateNoBareEntityInSubqueryProjection has already rejected
			// any subquery that projects a bare entity, so every entry is an explicit list.
			$subqueryExports = [];
			
			foreach ($ast->getRanges() as $range) {
				if (!$range instanceof AstRangeDatabaseSubquery) {
					continue;
				}
				
				$subqueryExports[$range->getName()] = array_map(fn($alias) => $alias->getName(), $range->getQuery()->getValues());
			}
			
			// Nothing to check if there are no subquery ranges in this query
			if (empty($subqueryExports)) {
				return;
			}
			
			// Walk the WHERE clause and check every root identifier that points at a subquery range
			$collector = new CollectNodes(AstIdentifier::class);
			$ast->getConditions()->accept($collector);
			
			foreach ($collector->getCollectedNodes() as $node) {
				// Only root nodes — child segments of x.foo.bar are not standalone references
				if ($node->hasParentIdentifier()) {
					continue;
				}
				
				// Skip if no range found
				$rangeName = $node->getRange()?->getName();
				
				if ($rangeName === null || !array_key_exists($rangeName, $subqueryExports)) {
					continue;
				}
				
				// If the field is not in the projection list, throw
				$field = $node->getPropertyName();
				
				if (!in_array($field, $subqueryExports[$rangeName], true)) {
					throw new SemanticException(sprintf(
						"Field '%s.%s' referenced in WHERE clause is not exported by subquery range '%s'.",
						$node->getName(),
						$field,
						$rangeName
					));
				}
			}
		}
		
		/**
		 * Validates that the outer retrieve list only references fields that subquery ranges export.
		 *
		 * Mirrors validateSubqueryRangeWhereReferences but covers the projection (retrieve list)
		 * rather than the WHERE clause. Both enforce the same contract: a subquery's projection
		 * is the complete set of fields the outer query may reference.
		 *
		 * @throws SemanticException If the outer projection references an unexported subquery field
		 */
		private function validateSubqueryRangeProjectionReferences(AstRetrieve $ast): void {
			// Build a map of subquery range name → exported field names for fast lookup.
			$subqueryExports = [];
			
			foreach ($ast->getRanges() as $range) {
				if (!$range instanceof AstRangeDatabaseSubquery) {
					continue;
				}
				
				$subqueryExports[$range->getName()] = array_map(fn($alias) => $alias->getName(), $range->getQuery()->getValues());
			}
			
			// Nothing to check if there are no subquery ranges in this query
			if (empty($subqueryExports)) {
				return;
			}
			
			// Walk the outer retrieve list and check every identifier that points at a subquery range
			foreach ($ast->getValues() as $alias) {
				$expression = $alias->getExpression();
				
				if (!$expression instanceof AstIdentifier) {
					continue;
				}
				
				// Only root identifiers — hasParentIdentifier() is false for the range segment
				if ($expression->hasParentIdentifier()) {
					continue;
				}
				
				$rangeName = $expression->getRange()?->getName();
				
				if ($rangeName === null || !array_key_exists($rangeName, $subqueryExports)) {
					continue;
				}
				
				// If the field is not in the export list, throw
				$field = $expression->getPropertyName();
				
				if (!in_array($field, $subqueryExports[$rangeName], true)) {
					throw new SemanticException(sprintf(
						"Field '%s.%s' referenced in retrieve list is not exported by subquery range '%s'.",
						$expression->getName(),
						$field,
						$rangeName
					));
				}
			}
		}
		
		/**
		 * Validates that a subquery's own retrieve list does not project a bare entity variable.
		 *
		 * A subquery range defines an explicit column-set contract for the outer query.
		 * Projecting a bare entity (e.g. retrieve(y) instead of retrieve(y.id, y.title))
		 * erases that contract: the derived table's schema becomes implicit, unverifiable
		 * at compile time, and impossible to enforce in WHERE-clause export checks.
		 *
		 * @throws SemanticException If any subquery projects a bare entity identifier
		 */
		/**
		 * Validates that a subquery's own retrieve list does not project a bare entity variable.
		 *
		 * A subquery range defines an explicit column-set contract for the outer query.
		 * Projecting a bare entity (e.g. retrieve(y) instead of retrieve(y.id, y.title))
		 * erases that contract: the derived table's schema becomes implicit, unverifiable
		 * at compile time, and impossible to enforce in WHERE-clause export checks.
		 *
		 * @throws SemanticException If any subquery projects a bare entity identifier
		 */
		private function validateNoEntityInSubqueryProjection(AstRetrieve $ast): void {
			foreach ($ast->getRanges() as $range) {
				if (!$range instanceof AstRangeDatabaseSubquery) {
					continue;
				}
				
				foreach ($range->getQuery()->getValues() as $alias) {
					$expression = $alias->getExpression();
					
					if ($expression instanceof AstIdentifier && !$expression->hasNext()) {
						throw new SemanticException(
							"A subquery range must project explicit properties, not an entire entity. " .
							"Use retrieve(y.id, y.title) instead of retrieve(y)."
						);
					}
				}
			}
		}
		
		/**
		 * Validates that the outer projection does not select a bare json source range.
		 *
		 * Json source ranges have no defined schema. Selecting the entire range (e.g. retrieve(y))
		 * produces empty arrays at runtime because the engine has no fields to hydrate from.
		 * Explicit property selection (e.g. retrieve(y.field)) is required.
		 *
		 * @throws SemanticException If a bare json source range identifier is selected
		 */
		private function validateNoBareJsonSourceInProjection(AstRetrieve $ast): void {
			foreach ($ast->getValues() as $alias) {
				$expression = $alias->getExpression();
				
				if (
					$expression instanceof AstIdentifier &&
					$expression->getRange() instanceof AstRangeJsonSource &&
					!$expression->hasNext()
				) {
					throw new SemanticException(
						"A json source range must project explicit properties, not the entire range. " .
						"Use retrieve(y.field) instead of retrieve(y)."
					);
				}
			}
		}
		
		/**
		 * Validates that the outer projection does not select an entire subquery range.
		 *
		 * Subquery ranges represent derived tables and cannot be hydrated as a single value.
		 * Expressions like `x` are invalid when `x` is a subquery range; users must select
		 * concrete properties such as `x.id`.
		 *
		 * @throws SemanticException If a subquery range identifier is selected without a property
		 */
		private function validateNoSubqueryRangeInOuterProjection(AstRetrieve $ast): void {
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
		
		/**
		 * Validates that no @SourceField annotation without an explicit range is ambiguous.
		 *
		 * Ambiguity arises when all of the following are true for a single property:
		 *   - The property carries a @SourceField annotation with no 'range' parameter.
		 *   - The entity owning that property appears in the retrieve projection.
		 *   - The query declares more than one json_source() range.
		 *
		 * In that situation the hydrator cannot determine which JSON source to read the
		 * field value from, so the problem must be caught here rather than silently
		 * picking the wrong source or failing at runtime.
		 *
		 * The check is deliberately scoped to entity ranges that appear in the projection
		 * list (getValues()), not every entity range in the query. An entity that is only
		 * used as a join anchor but not retrieved cannot be enriched, so its @SourceField
		 * annotations are irrelevant here.
		 *
		 * @param AstRetrieve $ast The AST to validate.
		 * @return void
		 * @throws SemanticException When an ambiguous @SourceField annotation is found.
		 * @throws EntityResolutionException
		 */
		private function validateUnambiguousSourceFieldAnnotations(AstRetrieve $ast): void {
			// Count how many JSON source ranges this query declares.
			// If there is at most one, no @SourceField annotation can ever be ambiguous,
			// so we can exit immediately without touching the entity store.
			$jsonRangeCount = 0;
			
			foreach ($ast->getRanges() as $range) {
				if ($range instanceof AstRangeJsonSource) {
					$jsonRangeCount++;
				}
			}
			
			// Zero or one JSON source — range inference is always unambiguous
			if ($jsonRangeCount <= 1) {
				return;
			}
			
			// Collect the names of all JSON ranges for inclusion in error messages
			$jsonRangeNames = [];
			
			foreach ($ast->getRanges() as $range) {
				if ($range instanceof AstRangeJsonSource) {
					$jsonRangeNames[] = $range->getName();
				}
			}
			
			// Inspect each value in the projection list and look for entity ranges
			// whose class carries ambiguous @SourceField annotations
			foreach ($ast->getValues() as $alias) {
				// Fetch the Expression
				$expression = $alias->getExpression();
				
				// Only top-level entity identifiers (no parent, no chained property)
				// can own @SourceField annotations — scalar projections and property
				// paths are not entity objects and cannot be enriched
				if (!$expression instanceof AstIdentifier) {
					continue;
				}
				
				// Check if this is the base identifier
				if ($expression->hasParentIdentifier() || $expression->hasNext()) {
					continue;
				}
				
				// JSON source ranges themselves are not entities — skip them
				if ($expression->getRange() instanceof AstRangeJsonSource) {
					continue;
				}
				
				// Resolve the entity class name; skip if unavailable (e.g. subquery ranges)
				$entityName = $expression->getEntityName();
				
				if ($entityName === null) {
					continue;
				}
				
				// Fetch all @SourceField annotations declared on this entity's properties.
				// getAnnotationsOfType() returns array<propertyName, array<int, SourceField>>.
				$metadata = $this->entityStore->getMetadata($entityName);
				$jsonFieldAnnotations = $metadata->getAnnotationsOfType(SourceField::class);
				
				// Check each annotated property for a missing range parameter
				foreach ($jsonFieldAnnotations as $propertyName => $annotations) {
					foreach ($annotations as $annotation) {
						// An explicit range is always unambiguous — nothing to check
						if ($annotation->getRange() !== null) {
							continue;
						}
						
						// No explicit range + multiple JSON sources = ambiguity
						throw new SemanticException(sprintf(
							"Property '%s::$%s' has a @SourceField annotation without a 'range' parameter, " .
							"but the query declares multiple JSON sources (%s). " .
							"Add range=\"alias\" to the annotation to resolve the ambiguity.",
							$entityName,
							$propertyName,
							implode(', ', $jsonRangeNames)
						));
					}
				}
			}
		}
		
		/**
		 * Validates that no aggregate function references both database ranges and
		 * non-database ranges (e.g. JSON sources) in the same expression.
		 *
		 * Such an aggregate has no defined execution strategy: the SQL engine cannot
		 * see non-database data, and the in-memory evaluator cannot run correlated
		 * SQL subqueries. The problem must be caught here rather than producing a
		 * silent wrong result or a confusing runtime error in the optimizer.
		 *
		 * @param AstRetrieve $ast The AST to validate.
		 * @return void
		 * @throws SemanticException
		 */
		private function validateNoMixedRangeAggregates(AstRetrieve $ast): void {
			foreach (AstUtilities::collectAggregateNodes($ast) as $aggregate) {
				// Fetch all aggregate notes
				$ranges = RangeUtilities::collectRangesFromNode($aggregate);
				
				// An aggregate with no range references is a constant expression — skip it
				if (empty($ranges)) {
					continue;
				}
				
				// Partition into database and non-database ranges
				$databaseRanges = array_filter($ranges, fn($r) => $r instanceof AstRangeDatabase);
				$nonDatabaseRanges = array_filter($ranges, fn($r) => !$r instanceof AstRangeDatabase);
				
				// Mixed: both sides are non-empty — no execution strategy exists for this
				if (!empty($databaseRanges) && !empty($nonDatabaseRanges)) {
					$dbNames = implode(', ', array_map(fn($r) => "'{$r->getName()}'", $databaseRanges));
					$extNames = implode(', ', array_map(fn($r) => "'{$r->getName()}'", $nonDatabaseRanges));
					
					throw new SemanticException(sprintf(
						"Aggregate '%s' mixes database ranges (%s) with non-database ranges (%s). "
						. "An aggregate must reference either database ranges or external source ranges, not both.",
						$aggregate->getType(),
						$dbNames,
						$extNames
					));
				}
			}
		}
	}