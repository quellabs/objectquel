<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors\Handlers;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\FullTextIndex;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\Capabilities\NullPlatformCapabilities;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearch;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearchScore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\QuelToSQLConvertToString;
	
	/**
	 * This class serves as a utility for converting ObjectQuel AST nodes into SQL fragments.
	 * It handles column name resolution, join conditions, search conditions, and entity
	 * property mapping to database columns.
	 */
	class SqlBuilderHelper {
		
		/** @var EntityStore Stores entity metadata and column mappings */
		private EntityStore $entityStore;
		
		/** @var PlatformCapabilitiesInterface Database engine capability descriptor */
		private PlatformCapabilitiesInterface $platform;
		
		/** @var array<string, mixed> Reference to query parameters array for prepared statements */
		private array $parameters;
		
		/** @var string Current part of query being processed (SELECT, WHERE, SORT, etc.) */
		private string $partOfQuery;
		
		/** @var mixed Reference to the main visitor instance for delegating node processing */
		private mixed $mainVisitor; // Reference to main visitor
		
		/**
		 * Constructor - initializes the SQL builder helper with required dependencies
		 * @param EntityStore $entityStore Entity metadata store
		 * @param array<string, mixed> $parameters Reference to parameters array for prepared statements
		 * @param string $partOfQuery Current query part being processed
		 * @param mixed|null $mainVisitor Optional reference to main visitor instance
		 */
		public function __construct(
			EntityStore                   $entityStore,
			array                         &$parameters,
			string                        $partOfQuery,
			mixed                         $mainVisitor = null,
			PlatformCapabilitiesInterface $platform = new NullPlatformCapabilities()
		) {
			$this->entityStore = $entityStore;
			$this->parameters = &$parameters; // Store reference to allow parameter modification
			$this->partOfQuery = $partOfQuery;
			$this->mainVisitor = $mainVisitor;
			$this->platform = $platform;
		}
		
		/**
		 * Get the EntityStore instance
		 * @return EntityStore The entity store containing metadata
		 */
		public function getEntityStore(): EntityStore {
			return $this->entityStore;
		}
		
		/**
		 * Builds a fully qualified column name for SQL queries based on an AST identifier.
		 * Handles both entity-based ranges (with metadata) and temporary table ranges
		 * (derived from subqueries).
		 *
		 * @param AstIdentifier $identifier The AST identifier to convert
		 * @return string Fully qualified SQL column name or empty string if no range
		 * @throws \LogicException
		 * @throws EntityResolutionException
		 */
		public function buildColumnName(AstIdentifier $identifier): string {
			// Get the range (table alias) from the identifier
			$range = $identifier->getRange();
			if (!$range) {
				return '';
			}
			
			// Extract the range name (table alias)
			$rangeName = $range->getName();
			
			if (empty($rangeName)) {
				return '';
			}
			
			// Get the entity name to determine range type
			$entityName = $identifier->getEntityName();
			
			// Check if this is a temporary table (no entity name)
			if (empty($entityName)) {
				return $this->buildColumnNameForTemporaryTable($identifier, $rangeName);
			} else {
				return $this->buildColumnNameForEntity($identifier, $rangeName, $entityName);
			}
		}
		
		/**
		 * Builds column name for entity-based ranges using entity metadata.
		 * Converts entity properties to their corresponding database column names
		 * using the entity store's column mappings.
		 *
		 * @param AstIdentifier $identifier The AST identifier to convert
		 * @param string $rangeName The table alias
		 * @param string $entityName The entity class name
		 * @return string Fully qualified SQL column name
		 * @throws \LogicException|EntityResolutionException
		 */
		private function buildColumnNameForEntity(AstIdentifier $identifier, string $rangeName, string $entityName): string {
			// Handle case where identifier refers to the entity itself (primary key)
			if ($this->identifierIsEntity($identifier)) {
				throw new \LogicException(
					"Identifier '{$rangeName}' refers to entity '{$entityName}' as a whole — a specific property is required here. " .
					"This should have been caught by the semantic validator. Check that entity-level identifiers are rejected in scalar function arguments and expressions."
				);
			}
			
			// Get the property name from the next node in the identifier chain
			$next = $identifier->getNext();
			
			if ($next === null) {
				// A non-entity identifier must have a property node — missing one is an AST bug
				throw new \LogicException(
					"Identifier for entity '{$entityName}' has no property node in chain"
				);
			}
			
			// Fetch the next property
			$property = $next->getName();
			
			// If none is there, the AST is broken
			if (empty($property)) {
				// A valid AST node must return a non-empty name
				throw new \LogicException(
					"Property node for entity '{$entityName}' returned an empty name"
				);
			}
			
			// Look up the database column name for this property
			$columnMap = $this->entityStore->getColumnMap($entityName);
			
			// Throw when the property is not present. This should already
			// have been checked in the semantic analyzer.
			if (!isset($columnMap[$property])) {
				throw new \LogicException(
					"Property '{$property}' has no column mapping in entity '{$entityName}'"
				);
			}
			
			// Return fully qualified column name
			return "{$rangeName}.{$columnMap[$property]}";
		}
		
		/**
		 * Builds column name for temporary table ranges (subquery results).
		 * Uses the identifier's own name as the column name since temporary tables
		 * have no entity metadata. The column names come directly from the subquery's
		 * SELECT clause aliases.
		 *
		 * @param AstIdentifier $identifier The AST identifier to convert
		 * @param string $rangeName The table alias
		 * @return string Fully qualified SQL column name
		 * @throws \LogicException
		 */
		private function buildColumnNameForTemporaryTable(AstIdentifier $identifier, string $rangeName): string {
			// For temporary tables, we can't reference the table itself without a property
			if (!$identifier->hasNext()) {
				throw new \LogicException(
					"Temporary table identifier '{$rangeName}' has no property node in chain"
				);
			}
			
			// Get the column name from the next node in the identifier chain
			// For temporary tables, this is the column alias from the subquery's SELECT
			$nextNode = $identifier->getNext();
			
			if ($nextNode === null) {
				throw new \LogicException(
					"Temporary table identifier '{$rangeName}' returned null for next node despite hasNext() being true"
				);
			}
			
			$columnName = $nextNode->getCompleteName();
			
			if (empty($columnName)) {
				throw new \LogicException(
					"Property node for temporary table '{$rangeName}' returned an empty name"
				);
			}
			
			// Return fully qualified column name using the identifier's name directly
			// No mapping needed since temporary table columns are already in SQL format
			return "{$rangeName}.`{$columnName}`";
		}
		
		/**
		 * Builds the join condition SQL from a join property AST.
		 * Delegates to the main visitor to process join condition AST nodes and
		 * return the appropriate SQL string. Falls back to creating a new visitor
		 * if main visitor is not available.
		 * @param AstInterface $joinCondition The AST node representing the join condition
		 * @return string SQL join condition
		 */
		public function buildJoinCondition(AstInterface $joinCondition): string {
			// Check if we have access to the main visitor
			if (!$this->mainVisitor) {
				// Fallback: create new visitor only if main visitor not available
				$visitor = new QuelToSQLConvertToString(
					$this->entityStore,
					$this->parameters,
					$this->partOfQuery,
					$this->platform
				);
				
				// Process the join condition AST node
				$joinCondition->accept($visitor);
				return $visitor->getResult();
			}
			
			// Use main visitor's visitNodeAndReturnSQL method for consistency
			return $this->mainVisitor->visitNodeAndReturnSQL($joinCondition);
		}
		
		/**
		 * Generates SQL for entity column selections with proper aliasing.
		 * Creates a comma-separated list of all columns for an entity with aliases
		 * in the format "table.column as `alias.property`". This is used in SELECT
		 * clauses when selecting entire entities.
		 * @param AstIdentifier $ast The entity identifier
		 * @return string Comma-separated list of aliased columns
		 * @throws \LogicException
		 */
		public function buildEntityColumns(AstIdentifier $ast): string {
			$result = [];
			$range = $ast->getRange();
			
			if (!$range) {
				throw new \LogicException(
					"buildEntityColumns called with an identifier that has no range"
				);
			}
			
			$rangeName = $range->getName();
			
			// Get all column mappings for this entity
			$entityName = $ast->getEntityName();
			
			if (empty($entityName)) {
				throw new \LogicException(
					"buildEntityColumns called with an identifier that has no entity name for range '{$rangeName}'"
				);
			}
			
			$columnMap = $this->entityStore->getColumnMap($entityName);
			
			// Build aliased column selections for each property
			foreach ($columnMap as $item => $value) {
				// Format: table.column as `alias.property`
				$result[] = "{$rangeName}.{$value} as `{$rangeName}.{$item}`";
			}
			
			return implode(",", $result);
		}
		
		/**
		 * Builds search conditions for multiple identifiers based on parsed search terms.
		 *
		 * If all identifiers belong to the same entity and that entity has a FullTextIndex
		 * covering all the searched columns, emits a single MATCH...AGAINST condition.
		 * Otherwise falls back to LIKE chains for maximum compatibility.
		 *
		 * @param AstSearch $search The search AST node containing identifiers
		 * @param array{
		 *     or_terms: string[],
		 *     and_terms: string[],
		 *     not_terms: string[]
		 * } $parsed
		 * @param string $searchKey Unique key for parameter naming
		 * @return string[] Array of SQL condition strings
		 */
		public function buildSearchConditions(AstSearch $search, array $parsed, string $searchKey): array {
			$fullTextIndex = $this->detectFullTextIndex($search->getIdentifiers());
			
			if ($fullTextIndex !== null) {
				return [$this->buildFullTextCondition($search->getIdentifiers(), $search->getSearchString(), $searchKey)];
			}
			
			// Fall back to LIKE chains per identifier
			$conditions = [];
			
			foreach ($search->getIdentifiers() as $identifier) {
				$columnName = $this->buildColumnName($identifier);
				$fieldConditions = $this->buildFieldConditions($columnName, $parsed, $searchKey);
				
				if (!empty($fieldConditions)) {
					$conditions[] = '(' . implode(' AND ', $fieldConditions) . ')';
				}
			}
			
			return $conditions;
		}
		
		/**
		 * Builds a MATCH...AGAINST expression for use in SELECT / ORDER BY clauses.
		 *
		 * Unlike buildSearchConditions() which wraps the result in a WHERE condition,
		 * this method returns the raw MATCH...AGAINST value expression so it can be
		 * used as a numeric score column.
		 *
		 * When no FullTextIndex covers the requested columns, returns the literal 0.0.
		 * All rows score equally, meaning no relevance ranking occurs, but the query
		 * succeeds. Add a @FullTextIndex annotation to the entity to enable real scoring.
		 *
		 * @param AstSearchScore $searchScore The search_score AST node
		 * @return string SQL MATCH...AGAINST expression, or '0.0' if no full-text index exists
		 */
		public function buildSearchScoreExpression(AstSearchScore $searchScore): string {
			$identifiers = $searchScore->getIdentifiers();
			$fullTextIndex = $this->detectFullTextIndex($identifiers);
			
			if ($fullTextIndex === null) {
				return '0.0';
			}
			
			return $this->buildFullTextCondition($identifiers, $searchScore->getSearchString(), uniqid());
		}
		
		/**
		 * Checks whether all given identifiers belong to the same entity and whether
		 * that entity has a FullTextIndex covering all of them. Returns the matching
		 * FullTextIndex if found, null otherwise.
		 * @param AstIdentifier[] $identifiers
		 * @return FullTextIndex|null
		 */
		private function detectFullTextIndex(array $identifiers): ?FullTextIndex {
			// Don't do anything if identifiers is empty
			if (empty($identifiers)) {
				return null;
			}
			
			// All identifiers must belong to the same entity for a single MATCH() to be valid
			$entityNames = array_unique(array_map(fn($id) => $id->getEntityName(), $identifiers));
			
			// MATCH() across multiple entities would require separate MATCH() calls per entity,
			// which can't be expressed as a single full-text condition
			if (count($entityNames) !== 1) {
				return null;
			}
			
			// Grab entity name
			$entityName = reset($entityNames);
			
			// Temporary table ranges have no entity name and therefore no annotation metadata
			if (empty($entityName)) {
				return null;
			}
			
			// Collect the property names being searched
			$propertyNames = $this->extractPropertyNames($identifiers);
			return $this->entityStore->getFullTextIndexForColumns($entityName, $propertyNames);
		}
		
		/**
		 * Builds a single MATCH...AGAINST condition from a raw search string node.
		 *
		 * The search string is passed directly to MySQL in boolean mode, which parses
		 * the +/- prefixes natively. This avoids the double-parse that would occur if
		 * we reconstructed the boolean string from the already-parsed terms array.
		 *
		 * Used by both search() (boolean condition) and search_score() (value expression)
		 * to ensure both code paths produce identical SQL for the same inputs.
		 *
		 * @param AstIdentifier[] $identifiers
		 * @param AstString|AstParameter $searchString The raw search term node
		 * @param string $searchKey Unique key for parameter naming
		 * @return string SQL MATCH...AGAINST expression
		 */
		private function buildFullTextCondition(array $identifiers, AstString|AstParameter $searchString, string $searchKey): string {
			// MATCH() requires bare column names — no table alias prefix.
			// buildColumnName() returns `alias.column`; we extract only the column part.
			$columns = array_map(function ($id) {
				$full = $this->buildColumnName($id);
				// Strip "alias." prefix if present — MATCH(content) not MATCH(p.content)
				$dotPos = strpos($full, '.');
				return $dotPos !== false ? substr($full, $dotPos + 1) : $full;
			}, $identifiers);
			
			$columnList = implode(', ', $columns);
			
			if ($searchString instanceof AstParameter) {
				// Pass the caller's named parameter through directly.
				// MATCH...AGAINST() rejects named params with MySQL native prepares —
				// DatabaseAdapter::execute() enables emulated prepares for MATCH queries.
				$term = ':' . $searchString->getName();
			} else {
				// Inline string literal — bind under a unique ft_ key
				$paramName = 'ft_' . $searchKey;
				$this->parameters[$paramName] = $searchString->getValue();
				$term = ':' . $paramName;
			}
			
			return "MATCH({$columnList}) AGAINST({$term} IN BOOLEAN MODE)";
		}
		
		/**
		 * Extracts the property name from each identifier in the chain.
		 * For an identifier like p.name, the property name is "name" (the next node).
		 * For an entity-level identifier with no next, returns the identifier's own name.
		 *
		 * @param AstIdentifier[] $identifiers
		 * @return string[]
		 */
		private function extractPropertyNames(array $identifiers): array {
			return array_map(function (AstIdentifier $identifier) {
				$next = $identifier->getNext();
				
				if ($next !== null) {
					return $next->getName();
				} else {
					return $identifier->getName();
				}
			}, $identifiers);
		}
		
		/**
		 * Builds a SQL column expression for the given identifier.
		 *
		 * In SORT context, wraps nullable columns in COALESCE() to push NULLs to a
		 * predictable position (0 for integers, '' for everything else). In all other
		 * contexts, returns a plain table.column reference.
		 *
		 * @param AstIdentifier $ast The identifier to resolve to a SQL expression.
		 * @return string SQL column expression, optionally wrapped in COALESCE().
		 * @throws \LogicException
		 */
		public function buildSortableColumn(AstIdentifier $ast): string {
			// Fetch the range
			$range = $ast->getRange();
			
			// Alias identifiers (e.g. `score` from `score=search_score(...)`) have no
			// range and no property chain. Return the bare name so ORDER BY score works.
			if ($range === null) {
				return $ast->getName();
			}
			
			// Resolve the next node now so static analysis can track its nullability
			// in one place, avoiding a redundant hasNext() + getNext() double-check.
			$nextNode = $ast->getNext();
			
			// Range is set but there's no property — treat as alias-style reference.
			if ($nextNode === null) {
				return $ast->getName();
			}
			
			// Temporary table ranges carry no entity metadata; the property name from
			// the subquery's SELECT is already a valid column name, so use it directly.
			$rangeName = $range->getName();
			$propertyName = $nextNode->getName();
			$entityName = $ast->getEntityName();
			
			if (empty($entityName)) {
				return "{$rangeName}.{$propertyName}";
			}
			
			// Map the ORM property name to its physical database column name.
			$columnMap = $this->entityStore->getColumnMap($entityName);
			
			if (!isset($columnMap[$propertyName])) {
				// If semantic validation ran correctly this should never happen
				throw new \LogicException(
					"Property '{$propertyName}' has no column mapping in entity '{$entityName}'"
				);
			}
			
			// Create the column
			$columnRef = "{$rangeName}.{$columnMap[$propertyName]}";
			
			// Outside a SORT clause there is no need for NULL handling; return as-is.
			if ($this->partOfQuery !== "SORT") {
				return $columnRef;
			}
			
			// In SORT context, find the Column annotation for this property so we can
			// inspect nullability and type. Filter to Column instances only since a
			// property may carry multiple annotation types (e.g. Index, Relation).
			$annotations = $this->entityStore->getAnnotations($entityName);
			$columnAnnotations = array_values(array_filter(
				$annotations[$propertyName] ?? [],
				fn($annotation) => $annotation instanceof Column
			));
			
			// No Column annotation found — can't determine nullability, return as-is.
			if (empty($columnAnnotations)) {
				return $columnRef;
			}
			
			// Non-nullable columns sort correctly without COALESCE.
			$columnAnnotation = $columnAnnotations[0];
			
			if (!$columnAnnotation->isNullable()) {
				return $columnRef;
			}
			
			// Nullable columns need a COALESCE default so NULLs sort consistently.
			// Integers default to 0 (sorts before positive values);
			// everything else defaults to '' (sorts before any non-empty string).
			$default = $columnAnnotation->getType() === "integer" ? "0" : "''";
			return "COALESCE({$columnRef}, {$default})";
		}
		
		/**
		 * Returns true if the identifier is an entity, false if not.
		 * Determines whether an AST identifier refers to an entire entity (table)
		 * or a specific property within an entity. Entity identifiers don't have
		 * a "next" node in the identifier chain.
		 * @param AstInterface $ast The AST node to check
		 * @return bool True if identifier represents an entity, false otherwise
		 */
		public function identifierIsEntity(AstInterface $ast): bool {
			return (
				$ast instanceof AstIdentifier &&           // Must be an identifier
				$ast->getRange() instanceof AstRangeDatabase && // Must have database range
				!$ast->hasNext()                          // Must not have property chain
			);
		}
		
		/**
		 * Builds field-specific conditions for different term types (OR, AND, NOT).
		 * Creates SQL conditions for a single field based on parsed search terms.
		 * Handles three types of search logic: OR (any term matches), AND (all terms
		 * must match), and NOT (terms must not match).
		 * @param string $columnName The SQL column name to search in
		 * @param array{
		 *     or_terms: string[],
		 *     and_terms: string[],
		 *     not_terms: string[]
		 * } $parsed
		 * @param string $searchKey Unique key for parameter naming
		 * @return string[] Array of SQL condition groups
		 */
		private function buildFieldConditions(string $columnName, array $parsed, string $searchKey): array {
			$fieldConditions = [];
			
			// Define term types and their SQL operators
			$termTypes = [
				'or_terms'  => ['operator' => 'OR', 'comparison' => 'LIKE'],      // Any term matches
				'and_terms' => ['operator' => 'AND', 'comparison' => 'LIKE'],     // All terms match
				'not_terms' => ['operator' => 'AND', 'comparison' => 'NOT LIKE']  // No terms match
			];
			
			// Process each term type
			foreach ($termTypes as $termType => $config) {
				$termConditions = $this->buildTermConditions(
					$columnName,
					$parsed[$termType],
					$config,
					$termType,
					$searchKey
				);
				
				// Group conditions for this term type
				if (!empty($termConditions)) {
					$fieldConditions[] = '(' . implode(" {$config['operator']} ", $termConditions) . ')';
				}
			}
			
			return $fieldConditions;
		}
		
		/**
		 * Builds conditions for a specific term type (or_terms, and_terms, not_terms).
		 * Creates individual SQL LIKE/NOT LIKE conditions for each search term
		 * and adds the corresponding parameters to the parameters array.
		 * @param string $columnName The SQL column name to search in
		 * @param string[] $terms Array of search terms for this type
		 * @param array{operator: string, comparison: string} $config Configuration with operator and comparison type
		 * @param string $termType The type of terms being processed
		 * @param string $searchKey Unique key for parameter naming
		 * @return string[] Array of individual SQL conditions
		 */
		private function buildTermConditions(
			string $columnName,
			array  $terms,
			array  $config,
			string $termType,
			string $searchKey
		): array {
			$termConditions = [];
			
			// Create a condition for each search term
			foreach ($terms as $index => $term) {
				// Generate unique parameter name
				$paramName = "{$termType}{$searchKey}{$index}";
				
				// Create SQL condition with parameter placeholder
				$termConditions[] = "{$columnName} {$config['comparison']} :{$paramName}";
				
				// Add parameter with wildcard wrapping for LIKE operations
				$this->parameters[$paramName] = "%{$term}%";
			}
			
			return $termConditions;
		}
	}