<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors\Handlers;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearch;
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
		
		/** @var array Reference to query parameters array for prepared statements */
		private array $parameters;
		
		/** @var string Current part of query being processed (SELECT, WHERE, SORT, etc.) */
		private string $partOfQuery;
		
		/** @var mixed Reference to the main visitor instance for delegating node processing */
		private mixed $mainVisitor; // Reference to main visitor
		
		/**
		 * Constructor - initializes the SQL builder helper with required dependencies
		 * @param EntityStore $entityStore Entity metadata store
		 * @param array $parameters Reference to parameters array for prepared statements
		 * @param string $partOfQuery Current query part being processed
		 * @param mixed|null $mainVisitor Optional reference to main visitor instance
		 */
		public function __construct(EntityStore $entityStore, array &$parameters, string $partOfQuery, mixed $mainVisitor = null) {
			$this->entityStore = $entityStore;
			$this->parameters = &$parameters; // Store reference to allow parameter modification
			$this->partOfQuery = $partOfQuery;
			$this->mainVisitor = $mainVisitor;
		}
	
		/**
		 * Get the EntityStore instance
		 * @return EntityStore The entity store containing metadata
		 */
		public function getEntityStore(): EntityStore {
			return $this->entityStore;
		}
		
		/**
		 * Builds a fully qualified column name for SQL queries based on an AST identifier
		 * Converts an ObjectQuel identifier (like "user.name") into a SQL column reference
		 * (like "u.name_column"). Handles both entity identifiers and property identifiers.
		 * @param AstIdentifier $identifier The AST identifier to convert
		 * @return string Fully qualified SQL column name or empty string if invalid
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
			
			// Get the entity name for metadata lookup
			$entityName = $identifier->getEntityName();
			
			if (empty($entityName)) {
				return '';
			}
			
			// Handle case where identifier refers to the entity itself (primary key)
			if ($this->identifierIsEntity($identifier)) {
				$identifierColumns = $this->entityStore->getIdentifierColumnNames($entityName);
				
				if (empty($identifierColumns)) {
					return '';
				}
				
				// Return first identifier column (usually primary key)
				return "{$rangeName}.{$identifierColumns[0]}";
			}
			
			// Handle property identifiers - need to check if there's a property name
			if (!$identifier->hasNext()) {
				return '';
			}
			
			// Get the property name from the next node in the identifier chain
			$property = $identifier->getNext()->getName();
			
			if (empty($property)) {
				return '';
			}
			
			// Look up the database column name for this property
			$columnMap = $this->entityStore->getColumnMap($entityName);
			
			if (!isset($columnMap[$property])) {
				return '';
			}
			
			// Return fully qualified column name
			return "{$rangeName}.{$columnMap[$property]}";
		}
		
		/**
		 * Builds the join condition SQL from a join property AST
		 * Delegates to the main visitor to process join condition AST nodes and
		 * return the appropriate SQL string. Falls back to creating a new visitor
		 * if main visitor is not available.
		 * @param AstInterface $joinCondition The AST node representing the join condition
		 * @return string SQL join condition or empty string on error
		 */
		public function buildJoinCondition(AstInterface $joinCondition): string {
			// Check if we have access to the main visitor
			if (!$this->mainVisitor) {
				// Fallback: create new visitor only if main visitor not available
				$visitor = new QuelToSQLConvertToString(
					$this->entityStore,
					$this->parameters,
					$this->partOfQuery
				);
				
				try {
					// Process the join condition AST node
					$joinCondition->accept($visitor);
					return $visitor->getResult();
				} catch (\Exception $e) {
					// Return empty string on any processing error
					return '';
				}
			}
			
			// Use main visitor's visitNodeAndReturnSQL method for consistency
			try {
				return $this->mainVisitor->visitNodeAndReturnSQL($joinCondition);
			} catch (\Exception $e) {
				// Return empty string on any processing error
				return '';
			}
		}
		
		/**
		 * Generates SQL for entity column selections with proper aliasing
		 * Creates a comma-separated list of all columns for an entity with aliases
		 * in the format "table.column as `alias.property`". This is used in SELECT
		 * clauses when selecting entire entities.
		 * @param AstIdentifier $ast The entity identifier
		 * @return string Comma-separated list of aliased columns or empty string
		 */
		public function buildEntityColumns(AstIdentifier $ast): string {
			$result = [];
			$range = $ast->getRange();
			$rangeName = $range->getName(); // Table alias
			
			// Get all column mappings for this entity
			$columnMap = $this->entityStore->getColumnMap($ast->getEntityName());
			
			// Build aliased column selections for each property
			foreach ($columnMap as $item => $value) {
				// Format: table.column as `alias.property`
				$result[] = "{$rangeName}.{$value} as `{$rangeName}.{$item}`";
			}
			
			return implode(",", $result);
		}
		
		/**
		 * Builds search conditions for multiple identifiers based on parsed search terms
		 * Creates WHERE clause conditions for search functionality. Supports OR, AND,
		 * and NOT search terms across multiple entity properties/columns.
		 * @param AstSearch $search The search AST node containing identifiers
		 * @param array $parsed Parsed search terms (or_terms, and_terms, not_terms)
		 * @param string $searchKey Unique key for parameter naming
		 * @return array Array of SQL condition strings
		 */
		public function buildSearchConditions(AstSearch $search, array $parsed, string $searchKey): array {
			$conditions = [];
			
			// Process each identifier (column) in the search
			foreach ($search->getIdentifiers() as $identifier) {
				// Convert identifier to SQL column name
				$columnName = $this->buildColumnName($identifier);
				
				// Build conditions for this specific field
				$fieldConditions = $this->buildFieldConditions($columnName, $parsed, $searchKey);
				
				// Add grouped conditions for this field
				if (!empty($fieldConditions)) {
					$conditions[] = '(' . implode(' AND ', $fieldConditions) . ')';
				}
			}
			
			return $conditions;
		}
		
		/**
		 * Builds identifier column with COALESCE for sorting
		 * Creates sortable column expressions that handle NULL values appropriately.
		 * Uses COALESCE to provide default values for nullable columns during sorting.
		 * @param AstIdentifier $ast The identifier to create sortable column for
		 * @return string SQL column expression with appropriate NULL handling
		 */
		public function buildSortableColumn(AstIdentifier $ast): string {
			$range = $ast->getRange();
			$rangeName = $range->getName();
			$entityName = $ast->getEntityName();
			$propertyName = $ast->getNext()->getName();
			$columnMap = $this->entityStore->getColumnMap($entityName);
			
			// For non-sorting contexts, return simple column reference
			if ($this->partOfQuery !== "SORT") {
				return $rangeName . "." . $columnMap[$propertyName];
			}
			
			// Get column annotations to check nullability and type
			$annotations = $this->entityStore->getAnnotations($entityName);
			
			$annotationsOfProperty = array_values(array_filter(
				$annotations[$propertyName]->toArray(),
				function ($e) {
					return $e instanceof Column;
				}
			));
			
			// If column is not nullable, no COALESCE needed
			// For nullable integer columns, use 0 as default
			// For other nullable columns, use empty string as default
			if (!$annotationsOfProperty[0]->isNullable()) {
				return $rangeName . "." . $columnMap[$propertyName];
			} elseif ($annotationsOfProperty[0]->getType() === "integer") {
				return "COALESCE({$rangeName}.{$columnMap[$propertyName]}, 0)";
			} else {
				return "COALESCE({$rangeName}.{$columnMap[$propertyName]}, '')";
			}
		}
		
		/**
		 * Returns true if the identifier is an entity, false if not
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
		 * Builds field-specific conditions for different term types (OR, AND, NOT)
		 * Creates SQL conditions for a single field based on parsed search terms.
		 * Handles three types of search logic: OR (any term matches), AND (all terms
		 * must match), and NOT (terms must not match).
		 * @param string $columnName The SQL column name to search in
		 * @param array $parsed Parsed search terms with or_terms, and_terms, not_terms
		 * @param string $searchKey Unique key for parameter naming
		 * @return array Array of SQL condition groups
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
		 * Builds conditions for a specific term type (or_terms, and_terms, not_terms)
		 * Creates individual SQL LIKE/NOT LIKE conditions for each search term
		 * and adds the corresponding parameters to the parameters array.
		 * @param string $columnName The SQL column name to search in
		 * @param array $terms Array of search terms for this type
		 * @param array $config Configuration with operator and comparison type
		 * @param string $termType The type of terms being processed
		 * @param string $searchKey Unique key for parameter naming
		 * @return array Array of individual SQL conditions
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