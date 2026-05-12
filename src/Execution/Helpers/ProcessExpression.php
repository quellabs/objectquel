<?php
	
	namespace Quellabs\ObjectQuel\Execution\Helpers;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\FullTextIndex;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsEmpty;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsFloat;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsInteger;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsNumeric;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRegExp;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearch;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearchFullText;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearchLike;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearchScore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\Capabilities\NullPlatformCapabilities;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeBinary;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;
	
	/**
	 * ExpressionHandler - Converts AST expression nodes to SQL equivalents
	 *
	 * This handler class is responsible for processing various AST (Abstract Syntax Tree)
	 * nodes and converting them into their corresponding SQL representations. It handles
	 * expressions, type checking operations, wildcard patterns, regular expressions,
	 * and various SQL functions like CONCAT, IN, NULL checks, etc.
	 *
	 * Key responsibilities:
	 * - Convert binary operations to SQL operators
	 * - Transform wildcard patterns (*,?) to SQL LIKE syntax (%,_)
	 * - Handle type checking functions (is_numeric, is_integer, is_float)
	 * - Process string concatenation, null checks, and parameter binding
	 * - Support regular expression matching in SQL
	 */
	class ProcessExpression {
		
		/**
		 * Wildcard character mappings for converting user-friendly patterns to SQL LIKE syntax
		 *
		 * Maps common wildcard characters to their SQL LIKE equivalents:
		 * - '%' and '_' are escaped to prevent conflicts with existing SQL wildcards
		 * - '*' becomes '%' (matches any sequence of characters)
		 * - '?' becomes '_' (matches exactly one character)
		 */
		private const array WILDCARD_MAPPINGS = [
			'%' => '\\%',   // Escape existing SQL wildcards to treat them as literals
			'_' => '\\_',   // Escape existing SQL wildcards to treat them as literals
			'*' => '%',     // Convert asterisk to SQL any-sequence wildcard
			'?' => '_'      // Convert question mark to SQL single-character wildcard
		];
		
		/**
		 * Regular expression patterns for runtime type checking
		 *
		 * These patterns validate string representations of different numeric types:
		 * - NUMERIC: Matches both integers and floating-point numbers
		 * - INTEGER: Matches only whole numbers (positive or negative)
		 * - FLOAT: Matches only decimal numbers with a decimal point
		 */
		private const array REGEX_PATTERNS = [
			'NUMERIC' => '^-?[0-9]+(\\.[0-9]+)?$',  // Optional decimal part for integers and floats
			'INTEGER' => '^-?[0-9]+$',               // Only digits, no decimal point allowed
			'FLOAT'   => '^-?[0-9]+\\.[0-9]+$'       // Requires decimal point with digits on both sides
		];
		
		/** @var EntityStore EntityStore holds entity metadata */
		private EntityStore $entityStore;
		
		/** @var ResolveType Helper for determining data types from AST nodes */
		private ResolveType $typeInference;
		
		/** @var array<string, mixed> Reference to the parameter array for prepared statements */
		private array $parameters;
		
		/** @var mixed Reference to the main visitor to avoid circular dependencies */
		private mixed $mainVisitor;
		
		/** @var PlatformCapabilitiesInterface Describes what the connected database engine supports */
		private PlatformCapabilitiesInterface $platform;
		
		/**
		 * Constructor - Initialize the expression handler with required dependencies
		 * @param EntityStore $entityStore EntityStore holds entity metadata
		 * @param ResolveType $typeInference Helper for type analysis
		 * @param array<string, mixed> $parameters Reference to parameters array for prepared statements
		 * @param mixed $mainVisitor Reference to the main AST visitor (avoids circular dependency)
		 * @param PlatformCapabilitiesInterface $platform Database engine capability descriptor
		 */
		public function __construct(
			EntityStore                   $entityStore,
			ResolveType                   $typeInference,
			array                         &$parameters,
			mixed                         $mainVisitor,
			PlatformCapabilitiesInterface $platform = new NullPlatformCapabilities()
		) {
			$this->entityStore = $entityStore;
			$this->typeInference = $typeInference;
			$this->parameters = &$parameters;
			$this->mainVisitor = $mainVisitor;
			$this->platform = $platform;
		}
		
		/**
		 * Handle generic binary expressions with special case processing
		 *
		 * Processes standard binary operations (=, <>, +, -, etc.) between two AST nodes.
		 * Includes special handling for:
		 * - Wildcard string patterns (converted to SQL LIKE)
		 * - Regular expression patterns (converted to SQL REGEXP)
		 * - Standard comparison and arithmetic operations
		 *
		 * @param NodeBinary $ast The AST node representing the binary expression
		 * @param string $operator The SQL operator to use (=, <>, +, -, etc.)
		 * @return string The resulting SQL expression
		 */
		public function handleGenericExpression(NodeBinary $ast, string $operator): string {
			// Special processing for equality/inequality operators
			if (in_array($operator, ['=', '<>'], true)) {
				$rightAst = $ast->getRight();
				
				// Check if right side is a wildcard string pattern
				if ($rightAst instanceof AstString) {
					$wildcardResult = $this->handleWildcardString($rightAst, $ast, $operator);
					
					if ($wildcardResult !== null) {
						return $wildcardResult;
					}
				}
				
				// Check if right side is a regular expression pattern
				if ($rightAst instanceof AstRegExp) {
					return $this->handleRegularExpression($rightAst, $ast, $operator);
				}
			}
			
			// Standard binary operation: visit both sides and combine with operator
			$leftResult = $this->visitNodeAndReturnSQL($ast->getLeft());
			$rightResult = $this->visitNodeAndReturnSQL($ast->getRight());
			
			return "{$leftResult} {$operator} {$rightResult}";
		}
		
		/**
		 * Handle empty value checking
		 *
		 * Converts an is_empty() function call to appropriate SQL conditions.
		 * Different logic based on the value type:
		 * - NULL values: always return 1 (true)
		 * - Numbers: return 1 if value is 0, otherwise 0
		 * - Booleans: return 1 if false, otherwise 0
		 * - Strings: return 1 if empty string, otherwise 0
		 * - Identifiers: build conditional SQL based on inferred type
		 *
		 * @param AstIsEmpty $ast The is_empty AST node
		 * @return string SQL condition checking for empty values
		 * @throws EntityResolutionException
		 */
		public function handleIsEmpty(AstIsEmpty $ast): string {
			// Fetch value
			$valueNode = $ast->getValue();
			
			// If the node is NULL, return '1'
			if ($valueNode instanceof AstNull) {
				return '1';
			}
			
			// Cast to float to correctly handle values like 0.5 (would be truthy, not empty)
			if ($valueNode instanceof AstNumber) {
				return (float)$valueNode->getValue() == 0 ? '1' : '0';
			}

			// Bool equals to 1 or 0
			if ($valueNode instanceof AstBool) {
				return !$valueNode->getValue() ? '1' : '0';
			}
			
			// Empty string means '1'
			if ($valueNode instanceof AstString) {
				return $valueNode->getValue() === '' ? '1' : '0';
			}
			
			// Identifier: build dynamic check based on inferred type
			$inferredType = $this->typeInference->inferReturnType($valueNode);
			$string = $this->visitNodeAndReturnSQL($valueNode);
			
			if ($inferredType === 'integer' || $inferredType === 'float') {
				return "({$string} IS NULL OR {$string} = 0)";
			} else {
				return "({$string} IS NULL OR {$string} = '')";
			}
		}
		
		/**
		 * Handle numeric type checking
		 * Converts an is_numeric() function call to SQL regex pattern matching.
		 * @param AstIsNumeric $ast The is_numeric AST node
		 * @return string SQL condition checking if value is numeric
		 */
		public function handleIsNumeric(AstIsNumeric $ast): string {
			return $this->handleTypeCheckWithPattern($ast, 'NUMERIC');
		}
		
		/**
		 * Handle integer type checking
		 * Converts an is_integer() function call to SQL regex pattern matching.
		 * @param AstIsInteger $ast The is_integer AST node
		 * @return string SQL condition checking if value is an integer
		 */
		public function handleIsInteger(AstIsInteger $ast): string {
			return $this->handleTypeCheckWithPattern($ast, 'INTEGER');
		}
		
		/**
		 * Handle float type checking
		 * Converts an is_float() function call to SQL regex pattern matching.
		 * @param AstIsFloat $ast The is_float AST node
		 * @return string SQL condition checking if value is a float
		 */
		public function handleIsFloat(AstIsFloat $ast): string {
			return $this->handleTypeCheckWithPattern($ast, 'FLOAT');
		}
		
		/**
		 * Convert search() to SQL.
		 *
		 * AstSearch is an intermediate node that only exists between parsing and
		 * planning. It must be rewritten into AstSearchFullText or AstSearchLike
		 * before execution reaches this point. Receiving one here is a programming
		 * error in plan construction.
		 *
		 * @param AstSearch $search
		 * @return string
		 */
		public function handleSearch(AstSearch $search): string {
			throw new \LogicException(
				'AstSearch reached the executor without being resolved. ' .
				'SearchStrategyResolver must run on every ExecutionStage during planning.'
			);
		}
			
		/**
		 * Convert a full-text search() to a MATCH(...) AGAINST(... IN BOOLEAN MODE) condition.
		 *
		 * The raw search string is passed directly to AGAINST so MySQL's boolean-mode
		 * parser handles +/- prefixes natively, avoiding a redundant parse of the terms.
		 *
		 * @param AstSearchFullText $search The full-text search AST node
		 * @return string SQL MATCH...AGAINST condition wrapped in parentheses
		 */
		public function handleSearchFullText(AstSearchFullText $search): string {
			// Each AstSearchFullText uses a unique key to avoid parameter name collisions
			// when multiple search() calls appear in the same query.
			$sql = $this->buildFullTextCondition(
				$search->getIdentifiers(),
				$search->getSearchString(),
				uniqid()
			);
			
			return '(' . $sql . ')';
		}
		
		/**
		 * Convert a LIKE-chain search() to a series of LIKE / NOT LIKE conditions.
		 *
		 * Term buckets (or_terms, and_terms, not_terms) are read directly from the node
		 * when the search string was a literal at planning time. When the search string
		 * is a runtime parameter, the buckets are null and the string is parsed here
		 * once the parameter value is available in $this->parameters.
		 *
		 * Each identifier in the search list produces its own group of LIKE conditions.
		 * The groups are joined with OR so a match in any searched column satisfies the
		 * condition.
		 *
		 * @param AstSearchLike $search The LIKE-chain search AST node
		 * @return string SQL OR-joined LIKE conditions wrapped in parentheses
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		public function handleSearchLike(AstSearchLike $search): string {
			// getParsed() returns null when the search string was a runtime parameter
			// at planning time; parse it now that the value is available.
			$parsed = $search->getParsed() ?? $search->parseSearchData($this->parameters);
			
			$conditions = [];
			
			foreach ($search->getIdentifiers() as $identifier) {
				// Build LIKE / NOT LIKE conditions for this column
				$columnName = $this->buildColumnName($identifier);
				$fieldConditions = $this->buildFieldConditions($columnName, $parsed, $search->getSearchKey());
				
				// Group the conditions for this column with AND, then collect them
				if (!empty($fieldConditions)) {
					$conditions[] = '(' . implode(' AND ', $fieldConditions) . ')';
				}
			}
			
			return '(' . implode(' OR ', $conditions) . ')';
		}
		
		/**
		 * Convert a search_score() call to a MATCH...AGAINST value expression.
		 *
		 * Returns the relevance score of the full-text search as a numeric SQL expression,
		 * suitable for use in SELECT clause column lists and ORDER BY clauses.
		 *
		 * When no @FullTextIndex annotation covers the searched columns, returns 0.0 so
		 * that the query succeeds without ranking. Add a @FullTextIndex to the entity to
		 * enable real relevance scoring.
		 *
		 * @param AstSearchScore $searchScore The search_score AST node
		 * @return string SQL MATCH...AGAINST expression, or '0.0' if no full-text index exists
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		public function handleSearchScore(AstSearchScore $searchScore): string {
			$identifiers = $searchScore->getIdentifiers();
			
			if (!$this->isFullTextIndex($identifiers)) {
				return '0.0';
			}
			
			return $this->buildFullTextCondition($identifiers, $searchScore->getSearchString(), uniqid());
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
		 * @throws EntityResolutionException
		 * @throws QuelException
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

			// Implode the column list
			$columnList = implode(', ', $columns);
			
			// Match existing parameter or create a new one
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
			
			// Return SQL
			return "MATCH({$columnList}) AGAINST({$term} IN BOOLEAN MODE)";
		}
		
		/**
		 * Builds a fully qualified column name for SQL queries based on an AST identifier.
		 * Handles both entity-based ranges (with metadata) and temporary table ranges
		 * (derived from subqueries).
		 * @param AstIdentifier $identifier The AST identifier to convert
		 * @return string Fully qualified SQL column name or empty string if no range
		 * @throws \LogicException
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		public function buildColumnName(AstIdentifier $identifier): string {
			// Get the range (table alias) from the identifier
			$range = $identifier->getRange();
			
			// Do not continue if the range is empty
			if ($range === null) {
				throw new QuelException("Range is not defined");
			}
			
			// Extract the range name (table alias)
			// Get the entity name to determine range type
			$rangeName = $range->getName();
			$entityName = $identifier->getEntityName() ?? '';
			
			// Check if this is a temporary table (no entity name)
			if ($identifier->getType() === IdentifierType::SubqueryRoot) {
				return $this->buildColumnNameForTemporaryTable($identifier, $rangeName);
			} else {
				return $this->buildColumnNameForEntity($identifier, $rangeName, $entityName);
			}
		}
		
		
		
		/**
		 * Builds a SQL column expression for the given identifier.
		 *
		 * In SORT context, wraps nullable columns in COALESCE() to push NULLs to a
		 * predictable position (0 for integers, '' for everything else). In all other
		 * contexts, returns a plain table.column reference.
		 *
		 * @param AstIdentifier $ast The identifier to resolve to a SQL expression.
		 * @param string $partOfQuery
		 * @return string SQL column expression, optionally wrapped in COALESCE().
		 * @throws \LogicException|EntityResolutionException
		 */
		public function buildSortableColumn(AstIdentifier $ast, string $partOfQuery): string {
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
				return "{$rangeName}.`{$rangeName}.{$propertyName}`";
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
			if ($partOfQuery !== "SORT") {
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
			
			// Validate that there is no next
			$nextNode = $identifier->getNext();
			
			if ($nextNode === null) {
				throw new \LogicException(
					"Temporary table identifier '{$rangeName}' returned null for next node despite hasNext() being true"
				);
			}
			
			// Get the column name from the next node in the identifier chain
			$columnName = $nextNode->getCompleteName();
			
			if (empty($columnName)) {
				throw new \LogicException(
					"Property node for temporary table '{$rangeName}' returned an empty name"
				);
			}
			
			// Column aliases in derived tables are stored as "rangeName.property" (e.g. "x.id"),
			// so reference them with the range prefix to match the subquery's SELECT aliases.
			return "`{$rangeName}`.`{$rangeName}.{$columnName}`";
		}
		
		/**
		 * Builds column name for entity-based ranges using entity metadata.
		 * Converts entity properties to their corresponding database column names
		 * using the entity store's column mappings.
		 * @param AstIdentifier $identifier The AST identifier to convert
		 * @param string $rangeName The table alias
		 * @param string $entityName The entity class name
		 * @return string Fully qualified SQL column name
		 * @throws \LogicException|EntityResolutionException
		 */
		private function buildColumnNameForEntity(AstIdentifier $identifier, string $rangeName, string $entityName): string {
			// Handle case where identifier refers to the entity itself (primary key)
			if ($identifier->getType() === IdentifierType::EntityReference) {
				throw new \LogicException(
					"Identifier '{$rangeName}' refers to entity '{$entityName}' as a whole — a specific property is required here. " .
					"This should have been caught by the semantic validator. Check that entity-level identifiers are rejected in scalar function arguments and expressions."
				);
			}
			
			// Get the property name from the next node in the identifier chain
			$next = $identifier->getNext();
			
			// If none exists, there is something fundamentally broken
			if ($next === null) {
				throw new \LogicException(
					"Identifier for entity '{$entityName}' has no property node in chain"
				);
			}
			
			// Fetch the next property
			$property = $next->getName();
			
			// If none exists, there is something fundamentally broken
			if (empty($property)) {
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
			return "`{$rangeName}`.`{$columnMap[$property]}`";
		}

		/**
		 * Handles type validation for numeric, integer, and float types using
		 * predefined regex patterns. Optimizes for literal values and uses
		 * SQL REGEXP for dynamic values.
		 * @param AstIsNumeric|AstIsInteger|AstIsFloat $ast The type check AST node
		 * @param string $patternKey The key for the regex pattern to use
		 * @return string SQL condition for type checking
		 */
		private function handleTypeCheckWithPattern(AstIsNumeric|AstIsInteger|AstIsFloat $ast, string $patternKey): string {
			$valueNode = $ast->getValue();
			
			// String literal: use SQL REGEXP with the pattern
			if ($valueNode instanceof AstString) {
				$escaped = "'" . $this->escapeSqlString($valueNode->getValue()) . "'";
				return "{$escaped} REGEXP '" . self::REGEX_PATTERNS[$patternKey] . "'";
			}
			
			// Numeric literal: evaluate at compile time
			if ($valueNode instanceof AstNumber) {
				return match ($patternKey) {
					'NUMERIC' => '1',
					'INTEGER' => !str_contains($valueNode->getValue(), '.') ? '1' : '0',
					'FLOAT' => str_contains($valueNode->getValue(), '.') ? '1' : '0',
					default => '0',
				};
			}
			
			// Boolean or null literal: never numeric
			if ($valueNode instanceof AstBool || $valueNode instanceof AstNull) {
				return '0';
			}
			
			// Identifier: use type inference to avoid a runtime REGEXP where possible
			$inferredType = $this->typeInference->inferReturnType($valueNode);
			$string = $this->visitNodeAndReturnSQL($valueNode);
			
			return match ([$patternKey, $inferredType]) {
				['NUMERIC', 'integer'], ['NUMERIC', 'float'] => '1',
				['INTEGER', 'integer'] => '1',
				['INTEGER', 'float'] => '0',
				['FLOAT', 'float'] => '1',
				['FLOAT', 'integer'] => '0',
				default => "{$string} REGEXP '" . self::REGEX_PATTERNS[$patternKey] . "'",
			};
		}
		
		/**
		 * Handle wildcard string patterns for SQL LIKE conversion.
		 *
		 * Returns the LIKE expression if the string contains wildcard characters (* or ?),
		 * or null if no wildcards are present (letting standard processing handle it).
		 *
		 * @param AstString $rightAst The string containing potential wildcards
		 * @param NodeBinary $ast The full expression
		 * @param string $operator The comparison operator (= or <>)
		 * @return string|null The LIKE expression, or null if no wildcards found
		 */
		private function handleWildcardString(AstString $rightAst, NodeBinary $ast, string $operator): ?string {
			$stringValue = $rightAst->getValue();
			
			if (!str_contains($stringValue, '*') && !str_contains($stringValue, '?')) {
				return null;
			}
			
			$leftResult = $this->visitNodeAndReturnSQL($ast->getLeft());
			
			$stringValue = str_replace(
				array_keys(self::WILDCARD_MAPPINGS),
				array_values(self::WILDCARD_MAPPINGS),
				$stringValue
			);
			
			$likeOperator = $operator === '=' ? ' LIKE ' : ' NOT LIKE ';
			
			return "{$leftResult}{$likeOperator}\"" . $this->escapeSqlString($stringValue) . '"';
		}
		
		/**
		 * Handle regular expression patterns for SQL REGEXP / REGEXP_LIKE conversion.
		 *
		 * When the pattern carries flags (e.g. /pattern/i) AND the connected database
		 * supports REGEXP_LIKE(), emits REGEXP_LIKE(col, "pattern", "flags") so that
		 * the flags are honoured (e.g. case-insensitive matching).
		 *
		 * When the platform does not support REGEXP_LIKE, or no flags are present,
		 * falls back to the plain col REGEXP "pattern" form and flags are ignored.
		 * In that case case-sensitivity is determined by the column's collation.
		 *
		 * @param AstRegExp $rightAst The regex pattern AST node
		 * @param NodeBinary $ast The full expression
		 * @param string $operator The comparison operator (= or <>)
		 * @return string The REGEXP or REGEXP_LIKE expression
		 */
		private function handleRegularExpression(AstRegExp $rightAst, NodeBinary $ast, string $operator): string {
			$leftResult = $this->visitNodeAndReturnSQL($ast->getLeft());
			$flags = $rightAst->getFlags();
			
			// Use REGEXP_LIKE(col, pattern, flags) when flags are present and the
			// platform supports it (MySQL 8.0+). This is the only way to pass flags
			// to the regex engine in MySQL — the plain REGEXP operator has no flag syntax.
			if ($flags !== '' && $this->platform->supportsRegexpLike()) {
				$not = $operator === '<>' ? 'NOT ' : '';
				return "{$not}REGEXP_LIKE({$leftResult}, \"{$rightAst->getValue()}\", \"{$flags}\")";
			}
			
			// Fallback: plain REGEXP. Flags are dropped — behavior depends on collation.
			$regexpOperator = $operator === '=' ? ' REGEXP ' : ' NOT REGEXP ';
			return "{$leftResult}{$regexpOperator}\"{$rightAst->getValue()}\"";
		}
		
		/**
		 * Checks whether all given identifiers belong to the same entity and whether
		 * that entity has a FullTextIndex covering all of them. Returns the matching
		 * FullTextIndex if found, null otherwise.
		 * @param AstIdentifier[] $identifiers
		 * @return bool
		 * @throws EntityResolutionException
		 */
		private function isFullTextIndex(array $identifiers): bool {
			// Don't do anything if identifiers is empty
			if (empty($identifiers)) {
				return false;
			}
			
			// All identifiers must belong to the same entity for a single MATCH() to be valid
			$entityNames = array_unique(array_map(fn($id) => $id->getEntityName(), $identifiers));
			
			// MATCH() across multiple entities would require separate MATCH() calls per entity,
			// which can't be expressed as a single full-text condition
			if (count($entityNames) !== 1) {
				return false;
			}
			
			// Grab entity name
			$entityName = reset($entityNames);
			
			// Temporary table ranges have no entity name and therefore no annotation metadata
			if (empty($entityName)) {
				return false;
			}
			
			// Collect the property names being searched
			$propertyNames = $this->extractPropertyNames($identifiers);
			return $this->entityStore->getFullTextIndexForColumns($entityName, $propertyNames) !== null;
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
		 * Escape a string value for safe inclusion in a SQL literal.
		 *
		 * NOTE: This centralizes escaping so it can be swapped for a PDO/mysqli
		 * real_escape_string call once a connection reference is available here.
		 * Do not inline addslashes() calls elsewhere in this class.
		 *
		 * @param string $value Raw string value
		 * @return string Escaped string safe for embedding between SQL quotes
		 */
		private function escapeSqlString(string $value): string {
			// addslashes() is a stopgap. Replace this body with:
			//   return $this->connection->real_escape_string($value);
			// or route through the parameter binding system when that becomes feasible.
			return addslashes($value);
		}
		
		/**
		 * Visit an AST node and return its SQL representation.
		 * Delegates to the main visitor to maintain proper isolation.
		 * @param AstInterface $node The AST node to process
		 * @return string The SQL representation of the node
		 */
		private function visitNodeAndReturnSQL(AstInterface $node): string {
			return $this->mainVisitor->visitNodeAndReturnSQL($node);
		}
	}