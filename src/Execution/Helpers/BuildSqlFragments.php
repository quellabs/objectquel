<?php
	
	namespace Quellabs\ObjectQuel\Execution\Helpers;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\Capabilities\NullPlatformCapabilities;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNotNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstConcat;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIfNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNot;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\Execution\Visitors\BuildSqlFromAst;
	
	/**
	 * This class serves as a utility for converting ObjectQuel AST nodes into SQL fragments.
	 * It handles column name resolution, join conditions, search conditions, and entity
	 * property mapping to database columns.
	 */
	class BuildSqlFragments {
		
		/** @var EntityStore Stores entity metadata and column mappings */
		private EntityStore $entityStore;
		
		/** @var PlatformCapabilitiesInterface Database engine capability descriptor */
		private PlatformCapabilitiesInterface $platform;
		
		/** @var array<string, mixed> Reference to query parameters array for prepared statements */
		private array $parameters;
		
		/** @var string Current part of query being processed (SELECT, WHERE, SORT, etc.) */
		private string $partOfQuery;
		
		/** @var mixed Reference to the main visitor instance for delegating node processing */
		private mixed $mainVisitor;
		
		/** @var string|null When set, column aliases in buildEntityColumns use this name instead of the inner range name */
		private ?string $subqueryAliasRangeName;
		
		/**
		 * Constructor - initializes the SQL builder helper with required dependencies
		 * @param EntityStore $entityStore Entity metadata store
		 * @param array<string, mixed> $parameters Reference to parameters array for prepared statements
		 * @param string $partOfQuery Current query part being processed
		 * @param mixed|null $mainVisitor Optional reference to main visitor instance
		 * @param PlatformCapabilitiesInterface $platform Database engine capability descriptor
		 * @param string|null $subqueryAliasRangeName When set, buildEntityColumns() aliases columns
		 *        using this name instead of the inner range name, so derived table columns match
		 *        what the outer query expects (e.g. "x.id" instead of "y.id")
		 */
		public function __construct(
			EntityStore                   $entityStore,
			array                         &$parameters,
			string                        $partOfQuery,
			mixed                         $mainVisitor = null,
			PlatformCapabilitiesInterface $platform = new NullPlatformCapabilities(),
			?string                       $subqueryAliasRangeName = null
		) {
			$this->entityStore = $entityStore;
			$this->parameters = &$parameters; // Store reference to allow parameter modification
			$this->partOfQuery = $partOfQuery;
			$this->mainVisitor = $mainVisitor;
			$this->platform = $platform;
			$this->subqueryAliasRangeName = $subqueryAliasRangeName;
		}
		
		/**
		 * Get the EntityStore instance
		 * @return EntityStore The entity store containing metadata
		 */
		public function getEntityStore(): EntityStore {
			return $this->entityStore;
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
				$visitor = new BuildSqlFromAst(
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
		 * @throws EntityResolutionException
		 */
		public function buildEntityColumns(AstIdentifier $ast): string {
			$result = [];
			$range = $ast->getRange();
			
			if (!$range) {
				throw new \LogicException(
					"buildEntityColumns called with an identifier that has no range"
				);
			}
			
			// Fetch the range name
			$rangeName = $range->getName();
			
			// Alias name for subqueries
			$aliasRangeName = $this->subqueryAliasRangeName ?? $rangeName;
			
			// Get all column mappings for this entity
			$entityName = $ast->getEntityName();
			
			if (empty($entityName)) {
				throw new \LogicException(
					"buildEntityColumns called with an identifier that has no entity name for range '{$rangeName}'"
				);
			}
			
			// Fetch the column map
			$columnMap = $this->entityStore->getColumnMap($entityName);
			
			// Build aliased column selections for each property
			foreach ($columnMap as $item => $value) {
				// Format: table.column as `alias.property`
				$result[] = "{$rangeName}.{$value} as `{$aliasRangeName}.{$item}`";
			}
			
			return implode(",", $result);
		}
		
		/**
		 * Process logical NOT operator
		 * Wraps the child expression in a SQL NOT().
		 * @param AstNot $ast The NOT AST node
		 * @return string SQL NOT expression
		 */
		public function handleNot(AstNot $ast): string {
			return 'NOT(' . $this->visitNodeAndReturnSQL($ast->getExpression()) . ')';
		}
		
		/**
		 * Process NULL literal values
		 * Converts an AstNull node to the SQL NULL keyword.
		 * @param AstNull $ast The NULL AST node
		 * @return string SQL NULL literal
		 */
		public function handleNull(AstNull $ast): string {
			return 'NULL';
		}
		
		/**
		 * Process boolean literal values
		 * Converts an AstBool node to SQL boolean representation.
		 * @param AstBool $ast The boolean AST node
		 * @return string SQL boolean literal ("true" or "false")
		 */
		public function handleBool(AstBool $ast): string {
			return $ast->getValue() ? 'true' : 'false';
		}
		
		/**
		 * Process numeric literal values
		 * Converts an AstNumber node to its SQL numeric representation.
		 * @param AstNumber $ast The number AST node
		 * @return string SQL numeric literal
		 */
		public function handleNumber(AstNumber $ast): string {
			return $ast->getValue();
		}
		
		/**
		 * Process string literal values
		 * Converts an AstString node to a properly escaped SQL string literal.
		 * @param AstString $ast The string AST node
		 * @return string SQL string literal with proper escaping
		 */
		public function handleString(AstString $ast): string {
			return '"' . $this->escapeSqlString($ast->getValue()) . '"';
		}
		
		/**
		 * Process concatenation operations and convert to SQL CONCAT function
		 * Takes an AstConcat node containing multiple parameters and generates
		 * a SQL CONCAT function call with all parameters properly processed.
		 * @param AstConcat $concat The concatenation AST node
		 * @return string SQL CONCAT function call
		 */
		public function handleConcat(AstConcat $concat): string {
			$parts = array_map(
				fn($param) => $this->visitNodeAndReturnSQL($param),
				$concat->getParameters()
			);
			
			return 'CONCAT(' . implode(', ', $parts) . ')';
		}
		
		/**
		 * IFNULL() serves as a simple COALESCE. If the expression returns NULL, use the alt value.
		 * @param AstIfNull $ast
		 * @return string
		 */
		public function handleIfNull(AstIfNull $ast): string {
			return sprintf(
				'COALESCE(%s, %s)',
				$this->visitNodeAndReturnSQL($ast->getExpression()),
				$this->visitNodeAndReturnSQL($ast->getAltValue())
			);
		}
		
		/**
		 * Process parameter placeholders for prepared statements
		 * Converts an AstParameter node to a SQL parameter placeholder.
		 * @param AstParameter $ast The parameter AST node
		 * @return string SQL parameter placeholder (e.g. :paramName)
		 */
		public function handleParameter(AstParameter $ast): string {
			return ':' . $ast->getName();
		}
		
		/**
		 * Process SQL IN clauses
		 * Converts an AstIn node to a SQL IN clause with proper formatting.
		 * @param AstIn $ast The IN clause AST node
		 * @return string Complete SQL IN clause
		 */
		public function handleIn(AstIn $ast): string {
			$identifier = $this->visitNodeAndReturnSQL($ast->getIdentifier());
			
			$values = array_map(
				fn($item) => $this->visitNodeAndReturnSQL($item),
				$ast->getParameters()
			);
			
			return $identifier . ' IN(' . implode(', ', $values) . ')';
		}
		
		/**
		 * Handle NULL checking operations
		 * Converts an AstCheckNull node to a SQL "IS NULL" condition.
		 * @param AstCheckNull $ast The null check AST node
		 * @return string SQL IS NULL condition
		 */
		public function handleCheckNull(AstCheckNull $ast): string {
			return $this->visitNodeAndReturnSQL($ast->getExpression()) . ' IS NULL';
		}
		
		/**
		 * Handle NOT NULL checking operations
		 * Converts an AstCheckNotNull node to a SQL "IS NOT NULL" condition.
		 * @param AstCheckNotNull $ast The not null check AST node
		 * @return string SQL IS NOT NULL condition
		 */
		public function handleCheckNotNull(AstCheckNotNull $ast): string {
			return $this->visitNodeAndReturnSQL($ast->getExpression()) . ' IS NOT NULL';
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
	}