<?php
	
	namespace Quellabs\ObjectQuel\Execution\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Execution\Helpers\BuildSqlFragments;
	use Quellabs\ObjectQuel\Execution\Helpers\ProcessAggregate;
	use Quellabs\ObjectQuel\Execution\Helpers\ProcessExpression;
	use Quellabs\ObjectQuel\Execution\Helpers\ResolveType;
	use Quellabs\ObjectQuel\Execution\SqlGeneratorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNotNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstConcat;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIfNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsEmpty;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsFloat;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsInteger;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsNumeric;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNot;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearch;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearchFullText;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearchLike;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearchScore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\NodeBinary;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\Capabilities\NullPlatformCapabilities;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCast;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * BuildSqlFromAst - AST to SQL Converter
	 * @package Quellabs\ObjectQuel\ObjectQuel\Visitors
	 * @author Quellabs
	 */
	class BuildSqlFromAst implements SqlGeneratorInterface {
		
		/** @var EntityStore Entity storage for metadata and schema information */
		private EntityStore $entityStore;
		
		/** @var array<int, string> SQL fragments that will be concatenated to form the final query */
		private array $result;
		
		/** @var array<string, bool> Track visited nodes to prevent infinite recursion and duplicate processing */
		private array $visitedNodes;
		
		/** @var array<string, mixed> Reference to query parameters for parameterized queries */
		private array $parameters;
		
		/** @var string Current part of the query being processed (e.g., "VALUES", "SORT", "WHERE") */
		private string $partOfQuery;
		
		// Helper classes for specialized functionality
		/** @var BuildSqlFragments Handles SQL construction and column name generation */
		private BuildSqlFragments $sqlFragmentBuilder;
		
		/** @var ResolveType Handles type inference and validation */
		private ResolveType $typeInference;
		
		/** @var ProcessAggregate Handles aggregate functions like COUNT, SUM, AVG, etc. */
		private ProcessAggregate $aggregateHandler;
		
		/** @var ProcessExpression Handles expressions, operators, and data types */
		private ProcessExpression $expressionHandler;

		/** @var PlatformCapabilitiesInterface Database engine capability descriptor */
		private PlatformCapabilitiesInterface $platform;
		
		/**
		 * Initialize the SQL converter with required dependencies
		 * @param EntityStore $store Entity storage containing schema and metadata
		 * @param array<string, mixed> $parameters Reference to parameters array for parameterized queries
		 * @param string $partOfQuery Current query part being processed (default: "VALUES")
		 * @param PlatformCapabilitiesInterface $platform Database engine capability descriptor
		 * @param string|null $subqueryAliasRangeName When non-null, column aliases in entity
		 *        expansion use this name instead of the inner range name, so derived table
		 *        columns match what the outer query expects (e.g. "x.id" instead of "y.id")
		 */
		public function __construct(
			EntityStore $store,
			array &$parameters,
			string $partOfQuery = "VALUES",
			PlatformCapabilitiesInterface $platform = new NullPlatformCapabilities(),
			?string $subqueryAliasRangeName = null
		) {
			// Initialize core properties
			$this->result = [];
			$this->visitedNodes = [];
			$this->entityStore = $store;
			$this->parameters = &$parameters; // Use reference to allow parameter modification
			$this->partOfQuery = $partOfQuery;
			$this->platform = $platform;
			
			// Initialize helper classes with proper dependencies and references
			$this->sqlFragmentBuilder = new BuildSqlFragments(
				$this->entityStore,
				$this,
				$subqueryAliasRangeName
			);
			
			$this->typeInference = new ResolveType(
				$this->entityStore
			);
			
			$this->aggregateHandler = new ProcessAggregate(
				$this->entityStore,
				$this->partOfQuery,
				$this->sqlFragmentBuilder,
				$this
			);
			
			$this->expressionHandler = new ProcessExpression(
				$this->entityStore,
				$this->typeInference,
				$this->parameters,
				$this,
				$this->platform
			);
		}
		
		/**
		 * Visit a node in the AST and process it
		 * @param AstInterface $node The AST node to visit and process
		 */
		public function visitNode(AstInterface $node): void {
			// Generate unique identifier for this node instance
			$objectHash = spl_object_hash($node);
			
			// Skip if already visited to prevent infinite recursion
			if (isset($this->visitedNodes[$objectHash])) {
				return;
			}
			
			// Extract class name and determine handler method name.
			// All AST node classes must follow the 'Ast' prefix convention.
			$className = $this->extractClassName($node);
			
			if (!str_starts_with($className, 'Ast')) {
				// A node reached the visitor that doesn't follow the naming convention.
				// This is a programming error — flag it loudly in debug mode rather
				// than silently producing incomplete SQL.
				throw new \LogicException(
					sprintf(
						'%s: node class "%s" does not follow the Ast* naming convention.',
						self::class,
						$className
					)
				);
			}
			
			// Determine the method that handles the method
			$handleMethod = 'handle' . substr($className, 3); // Remove 'Ast' prefix
			
			// If the handler does not exist, something is very wrong
			if (!method_exists($this, $handleMethod)) {
				throw new \LogicException(
					sprintf(
						'%s: no handler found for node class "%s" (expected method "%s").',
						self::class,
						$className,
						$handleMethod
					)
				);
			}

			// Call the appropriate handler
			$this->{$handleMethod}($node);
			
			// Mark this node as visited
			$this->addToVisitedNodes($node);
		}
		
		/**
		 * Visit a node and return the generated SQL without adding to main result.
		 * Saves and restores $this->result so the main buffer is unaffected even if
		 * an exception propagates out of the handler.
		 * @param AstInterface $node The AST node to process
		 * @return string The generated SQL for this node
		 */
		public function visitNodeAndReturnSQL(AstInterface $node): string {
			// Swap in a clean buffer so visitNode writes only this node's output
			$saved = $this->result;
			$this->result = [];
			
			try {
				$this->visitNode($node);
				$sql = implode("", $this->result);
			} finally {
				// Always restore the main buffer, even if visitNode throws
				$this->result = $saved;
			}
			
			return $sql;
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
			return $this->expressionHandler->buildColumnName($identifier);
		}
		
		/**
		 * Get the generated SQL query
		 * @return string The final SQL query as a concatenated string
		 */
		public function getResult(): string {
			return implode("", $this->result);
		}
		
		// =========================
		// AST NODE HANDLERS
		// =========================
		
		/**
		 * Process an AstAlias node
		 * @param AstAlias $ast The alias node to process
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		protected function handleAlias(AstAlias $ast): void {
			// Fetch expression
			$expression = $ast->getExpression();
			
			// search_score() aliased as a SELECT column: score=search_score(p.content, :term)
			// Emit only the MATCH...AGAINST expression — QuelToSQL::getFieldNames() appends
			// AS `name` itself for all non-entity expressions, so we must not add it here.
			if ($expression instanceof AstSearchScore) {
				$this->addToVisitedNodes($expression);
				$this->result[] = $this->expressionHandler->handleSearchScore($expression);
				return;
			}
			
			// Only process if the expression is an identifier
			if (!$expression instanceof AstIdentifier) {
				return;
			}
			
			// Check if this identifier represents an entity
			if ($this->identifierIsEntity($expression)) {
				$this->addToVisitedNodes($expression);
				$this->handleEntity($expression);
				return;
			}
			
			// Otherwise, process as a regular expression
			$ast->getExpression()->accept($this);
		}
		
		/**
		 * Process an AstIdentifier node
		 * @param AstIdentifier $ast The identifier node to process
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		protected function handleIdentifier(AstIdentifier $ast): void {
			// Mark this identifier as visited
			$this->addToVisitedNodes($ast);
			
			// Skip JSON source ranges (handled differently)
			if ($ast->getRange() instanceof AstRangeJsonSource) {
				return;
			}
			
			// Generate appropriate SQL based on query context
			if ($this->partOfQuery === "SORT") {
				// For sorting, we need sortable column references
				$this->result[] = $this->expressionHandler->buildSortableColumn($ast, $this->partOfQuery);
			} else {
				// For other contexts, use regular column names
				$this->result[] = $this->expressionHandler->buildColumnName($ast);
			}
		}
		
		/**
		 * Process an entity by generating SQL for all its columns
		 * When an entity is referenced (typically through an alias), we need to
		 * generate SQL that selects all columns belonging to that entity.
		 * @param AstIdentifier  $ast The entity identifier node
		 * @throws EntityResolutionException
		 */
		protected function handleEntity(AstIdentifier $ast): void {
			$this->result[] = $this->sqlFragmentBuilder->buildEntityColumns($ast);
		}
		
		/**
		 * Process a generic expression node
		 * Expressions are mathematical or logical operations that can contain
		 * operators and operands.
		 * @param AstExpression $ast The expression node to process
		 */
		protected function handleExpression(AstExpression $ast): void {
			$this->result[] = $this->expressionHandler->handleGenericExpression($ast, $ast->getOperator());
		}
		
		/**
		 * Process a term node (part of mathematical expressions)
		 * Terms typically represent multiplication, division, and similar operations
		 * in the expression hierarchy.
		 * @param AstTerm $ast The term node to process
		 */
		protected function handleTerm(AstTerm $ast): void {
			$this->result[] = $this->expressionHandler->handleGenericExpression($ast, $ast->getOperator());
		}
		
		/**
		 * Process a factor node (atomic parts of expressions)
		 * Factors are the most basic components of mathematical expressions,
		 * such as numbers, variables, or parenthesized sub-expressions.
		 * @param AstFactor $ast The factor node to process
		 */
		protected function handleFactor(AstFactor $ast): void {
			$this->result[] = $this->expressionHandler->handleGenericExpression($ast, $ast->getOperator());
		}
		
		/**
		 * Process a cast expression node.
		 *
		 * Emits either:
		 *   CAST(col AS TYPE)   for MySQL, MariaDB, SQLite, SQL Server
		 *   col::TYPE           for PostgreSQL (CastStyle::DoubleColon)
		 *
		 * The SQL type token (e.g. SIGNED, DOUBLE, TEXT) is resolved from
		 * PlatformCapabilitiesInterface::getSupportedCastTypes() using the
		 * canonical QUEL cast type stored on the node (e.g. int, float, string).
		 *
		 * @param AstCast $ast The cast node to process
		 */
		protected function handleCast(AstCast $ast): void {
			// Mark this node visited before recursing so the inner expression
			// is not visited a second time through the normal accept() path.
			$this->addToVisitedNodes($ast);

			// Resolve the SQL type token for the target engine.
			// The semantic analyser has already verified that this cast type is
			// supported, so the key is guaranteed to exist here.
			$supportedTypes = $this->platform->getSupportedCastTypes();
			$sqlType = $supportedTypes[$ast->getCastType()] ?? strtoupper($ast->getCastType());

			// Generate the SQL fragment for the inner expression
			$innerSql = $this->visitNodeAndReturnSQL($ast->getExpression());

			// Emit standard SQL CAST(), supported by all engines
			$this->result[] = "CAST({$innerSql} AS {$sqlType})";
		}

		/**
		 * Process a binary operator node
		 * Binary operators work on two operands (e.g., +, -, *, /, =, <, >).
		 * @param AstBinaryOperator $ast The binary operator node to process
		 */
		protected function handleBinaryOperator(AstBinaryOperator $ast): void {
			$this->result[] = $this->expressionHandler->handleGenericExpression($ast, $ast->getOperator());
		}
		
		/**
		 * Process a string concatenation operation
		 * @param AstConcat $concat The concatenation node to process
		 */
		protected function handleConcat(AstConcat $concat): void {
			$this->result[] = $this->sqlFragmentBuilder->handleConcat($concat);
		}
		
		/**
		 * Process a logical NOT operation
		 * @param AstNot $ast The NOT operation node to process
		 */
		protected function handleNot(AstNot $ast): void {
			$this->result[] = $this->sqlFragmentBuilder->handleNot($ast);
		}
		
		/**
		 * Process a NULL value
		 * @param AstNull $ast The NULL value node to process
		 */
		protected function handleNull(AstNull $ast): void {
			$this->result[] = $this->sqlFragmentBuilder->handleNull($ast);
		}
		
		/**
		 * Process a boolean value (true/false)
		 * @param AstBool $ast The boolean value node to process
		 */
		protected function handleBool(AstBool $ast): void {
			$this->result[] = $this->sqlFragmentBuilder->handleBool($ast);
		}
		
		/**
		 * Process a numeric value
		 * @param AstNumber $ast The numeric value node to process
		 */
		protected function handleNumber(AstNumber $ast): void {
			$this->result[] = $this->sqlFragmentBuilder->handleNumber($ast);
		}
		
		/**
		 * Process a string literal value
		 * @param AstString $ast The string literal node to process
		 */
		protected function handleString(AstString $ast): void {
			$this->result[] = $this->sqlFragmentBuilder->handleString($ast);
		}
		
		/**
		 * Process a query parameter placeholder
		 * Parameters are used in parameterized queries for security and performance.
		 * @param AstParameter $ast The parameter node to process
		 */
		protected function handleParameter(AstParameter $ast): void {
			$this->result[] = $this->sqlFragmentBuilder->handleParameter($ast);
		}
		
		/**
		 * Process an IN operation (value IN (list))
		 * @param AstIn $ast The IN operation node to process
		 */
		protected function handleIn(AstIn $ast): void {
			$this->result[] = $this->sqlFragmentBuilder->handleIn($ast);
		}
		
		/**
		 * Process a NULL check operation (IS NULL)
		 * @param AstCheckNull $ast The NULL check node to process
		 */
		protected function handleCheckNull(AstCheckNull $ast): void {
			$this->result[] = $this->sqlFragmentBuilder->handleCheckNull($ast);
		}
		
		/**
		 * Process a NOT NULL check operation (IS NOT NULL)
		 * @param AstCheckNotNull $ast The NOT NULL check node to process
		 */
		protected function handleCheckNotNull(AstCheckNotNull $ast): void {
			$this->result[] = $this->sqlFragmentBuilder->handleCheckNotNull($ast);
		}
		
		/**
		 * Process an empty value check operation
		 * @param AstIsEmpty $ast The empty check node to process
		 */
		protected function handleIsEmpty(AstIsEmpty $ast): void {
			$this->result[] = $this->expressionHandler->handleIsEmpty($ast);
		}
		
		/**
		 * Process a numeric type check operation
		 * @param AstIsNumeric $ast The numeric check node to process
		 */
		protected function handleIsNumeric(AstIsNumeric $ast): void {
			$this->result[] = $this->expressionHandler->handleIsNumeric($ast);
		}
		
		/**
		 * Process an integer type check operation
		 * @param AstIsInteger $ast The integer check node to process
		 */
		protected function handleIsInteger(AstIsInteger $ast): void {
			$this->result[] = $this->expressionHandler->handleIsInteger($ast);
		}
		
		/**
		 * Process a float type check operation
		 * @param AstIsFloat $ast The float check node to process
		 */
		protected function handleIsFloat(AstIsFloat $ast): void {
			$this->result[] = $this->expressionHandler->handleIsFloat($ast);
		}
		
		/**
		 * Process a search() operation in the WHERE clause.
		 *
		 * AstSearch is an intermediate node that must not reach the executor — it is
		 * always rewritten into AstSearchFullText or AstSearchLike during planning.
		 * This handler delegates to the expression handler which throws if called.
		 *
		 * @param AstSearch $search The search operation node to process
		 */
		protected function handleSearch(AstSearch $search): void {
			$this->result[] = $this->expressionHandler->handleSearch($search);
		}
		
		/**
		 * Process a full-text search() and emit a MATCH...AGAINST condition.
		 * @param AstSearchFullText $search The full-text search node to process
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		protected function handleSearchFullText(AstSearchFullText $search): void {
			$this->addToVisitedNodes($search);
			$this->result[] = $this->expressionHandler->handleSearchFullText($search);
		}
		
		/**
		 * Process a LIKE-chain search() and emit LIKE / NOT LIKE conditions.
		 * @param AstSearchLike $search The LIKE-chain search node to process
		 * @throws EntityResolutionException
		 * @throws QuelException
		 */
		protected function handleSearchLike(AstSearchLike $search): void {
			$this->addToVisitedNodes($search);
			$this->result[] = $this->expressionHandler->handleSearchLike($search);
		}
		
		/**
		 * Process a search_score() operation — returns the MATCH...AGAINST relevance score
		 * as a numeric value for use in SELECT and ORDER BY clauses.
		 * @param AstSearchScore $searchScore The search_score node to process
		 */
		protected function handleSearchScore(AstSearchScore $searchScore): void {
			$this->result[] = $this->expressionHandler->handleSearchScore($searchScore);
		}
		
		/**
		 * Process a subquery
		 * @param AstSubquery $subquery
		 */
		protected function handleSubquery(AstSubquery $subquery): void {
			$this->result[] = $this->aggregateHandler->handleSubquery($subquery);
		}
		
		/**
		 * Process a CASE expression (CASE WHEN ... THEN ... ELSE ... END)
		 * @param AstCase $ast The CASE expression node to process
		 */
		protected function handleCase(AstCase $ast): void {
			$this->result[] = $this->aggregateHandler->handleCase($ast);
		}
		
		/**
		 * Process a COUNT aggregate function
		 * COUNT returns the number of rows that match the criteria.
		 * @param AstCount $count The COUNT function node to process
		 */
		protected function handleCount(AstCount $count): void {
			$this->result[] = $this->aggregateHandler->handleCount($count);
		}
		
		/**
		 * Process a COUNT UNIQUE (DISTINCT) aggregate function
		 * COUNT UNIQUE returns the number of distinct values.
		 * @param AstCountU $count The COUNT UNIQUE function node to process
		 */
		protected function handleCountU(AstCountU $count): void {
			$this->result[] = $this->aggregateHandler->handleCountU($count);
		}
		
		/**
		 * Process an AVG (average) aggregate function
		 * AVG returns the average value of a numeric column.
		 * @param AstAvg $avg The AVG function node to process
		 */
		protected function handleAvg(AstAvg $avg): void {
			$this->result[] = $this->aggregateHandler->handleAvg($avg);
		}
		
		/**
		 * Process an AVG UNIQUE (average of distinct values) aggregate function
		 * AVG UNIQUE returns the average of distinct values only.
		 * @param AstAvgU $avg The AVG UNIQUE function node to process
		 */
		protected function handleAvgU(AstAvgU $avg): void {
			$this->result[] = $this->aggregateHandler->handleAvgU($avg);
		}
		
		/**
		 * Process a MAX (maximum) aggregate function
		 * MAX returns the largest value in a column.
		 * @param AstMax $max The MAX function node to process
		 */
		protected function handleMax(AstMax $max): void {
			$this->result[] = $this->aggregateHandler->handleMax($max);
		}
		
		/**
		 * Process a MIN (minimum) aggregate function
		 * MIN returns the smallest value in a column.
		 * @param AstMin $min The MIN function node to process
		 */
		protected function handleMin(AstMin $min): void {
			$this->result[] = $this->aggregateHandler->handleMin($min);
		}
		
		/**
		 * Process a SUM aggregate function
		 * SUM returns the total of all values in a numeric column.
		 * @param AstSum $sum The SUM function node to process
		 */
		protected function handleSum(AstSum $sum): void {
			$this->result[] = $this->aggregateHandler->handleSum($sum);
		}
		
		/**
		 * Process a SUM UNIQUE (sum of distinct values) aggregate function
		 * SUM UNIQUE returns the sum of distinct values only.
		 * @param AstSumU $sum The SUM UNIQUE function node to process
		 */
		protected function handleSumU(AstSumU $sum): void {
			$this->result[] = $this->aggregateHandler->handleSumU($sum);
		}
		
		/**
		 * Process an ANY aggregate function
		 * ANY returns true if any row matches the condition, similar to EXISTS.
		 * @param AstAny $ast The ANY function node to process
		 */
		protected function handleAny(AstAny $ast): void {
			$this->result[] = $this->aggregateHandler->handleAny($ast);
		}
		
		/**
		 * Handle IFNULL function
		 * @param AstIfNull $ast
		 * @return void
		 */
		protected function handleIfNull(AstIfNull $ast): void {
			$this->result[] = $this->sqlFragmentBuilder->handleIfNull($ast);
		}
		
		/**
		 * Returns the child nodes of an AST node that must also be marked visited.
		 *
		 * This centralises child-traversal knowledge so that addToVisitedNodes() stays
		 * a simple loop and adding a new node type only requires touching this one method.
		 *
		 * Ideally this logic lives on the nodes themselves via a getChildren(): array
		 * method on AstInterface, eliminating all instanceof checks here. That refactor
		 * can be done incrementally: once AstInterface declares getChildren(), remove
		 * the corresponding branch below and rely on the interface instead.
		 *
		 * @param AstInterface $ast
		 * @return array<AstInterface|null> Direct children (nulls are filtered by addToVisitedNodes)
		 */
		private function getAstChildren(AstInterface $ast): array {
			// Chained identifier: table.column.subfield — walk the chain
			if ($ast instanceof AstIdentifier) {
				return $ast->hasNext() ? [$ast->getNext()] : [];
			}
			
			// Binary-ish expression nodes share a left/right structure
			if ($ast instanceof NodeBinary) {
				return [$ast->getLeft(), $ast->getRight()];
			}
			
			if ($ast instanceof AstAny) {
				return [$ast->getConditions(), $ast->getIdentifier()];
			}
			
			if ($ast instanceof AstSubquery) {
				return [$ast->getAggregation(), $ast->getConditions()];
			}
			
			// AstSearch, AstSearchFullText, AstSearchLike, and AstSearchScore share the same child shape
			if ($ast instanceof AstSearch || $ast instanceof AstSearchFullText || $ast instanceof AstSearchLike || $ast instanceof AstSearchScore) {
				return [...$ast->getIdentifiers(), $ast->getSearchString()];
			}
			
			return [];
		}
		
		/**
		 * Mark an AST node and all its descendants as visited.
		 * Child discovery is delegated to getAstChildren() — add new node types there,
		 * not here.
		 * @param AstInterface|null $ast The AST node to mark as visited
		 */
		protected function addToVisitedNodes(?AstInterface $ast): void {
			if ($ast === null) {
				return;
			}
			
			// Mark this node as visited using its unique object ID
			$this->visitedNodes[spl_object_hash($ast)] = true;
			
			// Recursively mark all children
			foreach ($this->getAstChildren($ast) as $child) {
				$this->addToVisitedNodes($child);
			}
		}
		
		/**
		 * Extract the class name from a fully qualified class name
		 * @param object $node The object whose class name to extract
		 * @return string The simple class name without namespace
		 */
		private function extractClassName(object $node): string {
			return basename(str_replace('\\', '/', get_class($node)));
		}
		
		/**
		 * Returns true if the identifier is an entity, false if not.
		 * Determines whether an AST identifier refers to an entire entity (table)
		 * or a specific property within an entity. Entity identifiers don't have
		 * a "next" node in the identifier chain.
		 * @param AstInterface $ast The AST node to check
		 * @return bool True if identifier represents an entity, false otherwise
		 */
		private function identifierIsEntity(AstInterface $ast): bool {
			return (
				$ast instanceof AstIdentifier &&           // Must be an identifier
				$ast->getRange() instanceof AstRangeDatabase && // Must have database range
				!$ast->hasNext()                          // Must not have property chain
			);
		}
	}