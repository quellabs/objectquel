<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	use Quellabs\ObjectQuel\EntityStore;
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
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearch;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearchScore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSubquery;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\Capabilities\NullPlatformCapabilities;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\Handlers\AggregateHandler;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\Handlers\ExpressionHandler;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\Handlers\SqlBuilderHelper;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\Handlers\TypeInferenceHelper;
	
	/**
	 * QuelToSQLConvertToString - AST to SQL Converter
	 * @package Quellabs\ObjectQuel\ObjectQuel\Visitors
	 * @author Quellabs
	 */
	class QuelToSQLConvertToString implements AstVisitorInterface {
		
		/** @var EntityStore Entity storage for metadata and schema information */
		private EntityStore $entityStore;
		
		/** @var array SQL fragments that will be concatenated to form the final query */
		private array $result;
		
		/** @var array Track visited nodes to prevent infinite recursion and duplicate processing */
		private array $visitedNodes;
		
		/** @var array Reference to query parameters for parameterized queries */
		private array $parameters;
		
		/** @var string Current part of the query being processed (e.g., "VALUES", "SORT", "WHERE") */
		private string $partOfQuery;
		
		// Helper classes for specialized functionality
		/** @var SqlBuilderHelper Handles SQL construction and column name generation */
		private SqlBuilderHelper $sqlBuilder;
		
		/** @var TypeInferenceHelper Handles type inference and validation */
		private TypeInferenceHelper $typeInference;
		
		/** @var AggregateHandler Handles aggregate functions like COUNT, SUM, AVG, etc. */
		private AggregateHandler $aggregateHandler;
		
		/** @var ExpressionHandler Handles expressions, operators, and data types */
		private ExpressionHandler $expressionHandler;

		/** @var PlatformCapabilitiesInterface Database engine capability descriptor */
		private PlatformCapabilitiesInterface $platform;
		
		/**
		 * Initialize the SQL converter with required dependencies
		 * @param EntityStore $store Entity storage containing schema and metadata
		 * @param array $parameters Reference to parameters array for parameterized queries
		 * @param string $partOfQuery Current query part being processed (default: "VALUES")
		 */
		public function __construct(EntityStore $store, array &$parameters, string $partOfQuery = "VALUES", PlatformCapabilitiesInterface $platform = new NullPlatformCapabilities()) {
			// Initialize core properties
			$this->result = [];
			$this->visitedNodes = [];
			$this->entityStore = $store;
			$this->parameters = &$parameters; // Use reference to allow parameter modification
			$this->partOfQuery = $partOfQuery;
			$this->platform = $platform;
			
			// Initialize helper classes with proper dependencies and references
			$this->sqlBuilder = new SqlBuilderHelper($this->entityStore, $this->parameters, $this->partOfQuery, $this, $this->platform);
			$this->typeInference = new TypeInferenceHelper($this->entityStore);
			$this->aggregateHandler = new AggregateHandler($this->entityStore, $this->partOfQuery, $this->sqlBuilder, $this);
			$this->expressionHandler = new ExpressionHandler($this->sqlBuilder, $this->typeInference, $this->parameters, $this, $this->platform);
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
				trigger_error(
					sprintf(
						'%s: node class "%s" does not follow the Ast* naming convention; no handler will be invoked.',
						self::class,
						$className
					),
					E_USER_WARNING
				);
				return;
			}
			
			$handleMethod = 'handle' . substr($className, 3); // Remove 'Ast' prefix
			
			// Call the appropriate handler method if it exists
			if (method_exists($this, $handleMethod)) {
				$this->{$handleMethod}($node);
			}
			
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
		 * Get the complete generated SQL query
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
			if ($this->sqlBuilder->identifierIsEntity($expression)) {
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
				$this->result[] = $this->sqlBuilder->buildSortableColumn($ast);
			} else {
				// For other contexts, use regular column names
				$this->result[] = $this->sqlBuilder->buildColumnName($ast);
			}
		}
		
		/**
		 * Process an entity by generating SQL for all its columns
		 * When an entity is referenced (typically through an alias), we need to
		 * generate SQL that selects all columns belonging to that entity.
		 * @param AstInterface $ast The entity identifier node
		 */
		protected function handleEntity(AstInterface $ast): void {
			// Ensure we're dealing with an identifier
			if (!$ast instanceof AstIdentifier) {
				return;
			}
			
			// Generate SQL for all columns of this entity
			$this->result[] = $this->sqlBuilder->buildEntityColumns($ast);
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
			$this->result[] = $this->expressionHandler->handleConcat($concat);
		}
		
		/**
		 * Process a logical NOT operation
		 * @param AstNot $ast The NOT operation node to process
		 */
		protected function handleNot(AstNot $ast): void {
			$this->result[] = $this->expressionHandler->handleNot($ast);
		}
		
		/**
		 * Process a NULL value
		 * @param AstNull $ast The NULL value node to process
		 */
		protected function handleNull(AstNull $ast): void {
			$this->result[] = $this->expressionHandler->handleNull($ast);
		}
		
		/**
		 * Process a boolean value (true/false)
		 * @param AstBool $ast The boolean value node to process
		 */
		protected function handleBool(AstBool $ast): void {
			$this->result[] = $this->expressionHandler->handleBool($ast);
		}
		
		/**
		 * Process a numeric value
		 * @param AstNumber $ast The numeric value node to process
		 */
		protected function handleNumber(AstNumber $ast): void {
			$this->result[] = $this->expressionHandler->handleNumber($ast);
		}
		
		/**
		 * Process a string literal value
		 * @param AstString $ast The string literal node to process
		 */
		protected function handleString(AstString $ast): void {
			$this->result[] = $this->expressionHandler->handleString($ast);
		}
		
		/**
		 * Process a query parameter placeholder
		 * Parameters are used in parameterized queries for security and performance.
		 * @param AstParameter $ast The parameter node to process
		 */
		protected function handleParameter(AstParameter $ast): void {
			$this->result[] = $this->expressionHandler->handleParameter($ast);
		}
		
		/**
		 * Process an IN operation (value IN (list))
		 * @param AstIn $ast The IN operation node to process
		 */
		protected function handleIn(AstIn $ast): void {
			$this->result[] = $this->expressionHandler->handleIn($ast);
		}
		
		/**
		 * Process a NULL check operation (IS NULL)
		 * @param AstCheckNull $ast The NULL check node to process
		 */
		protected function handleCheckNull(AstCheckNull $ast): void {
			$this->result[] = $this->expressionHandler->handleCheckNull($ast);
		}
		
		/**
		 * Process a NOT NULL check operation (IS NOT NULL)
		 * @param AstCheckNotNull $ast The NOT NULL check node to process
		 */
		protected function handleCheckNotNull(AstCheckNotNull $ast): void {
			$this->result[] = $this->expressionHandler->handleCheckNotNull($ast);
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
		 * Process a search operation (full-text search or pattern matching)
		 * @param AstSearch $search The search operation node to process
		 */
		protected function handleSearch(AstSearch $search): void {
			$this->result[] = $this->expressionHandler->handleSearch($search);
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
			$this->result[] = $this->expressionHandler->handleIfNull($ast);
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
			if (
				$ast instanceof AstTerm ||
				$ast instanceof AstFactor ||
				$ast instanceof AstExpression ||
				$ast instanceof AstBinaryOperator
			) {
				return [$ast->getLeft(), $ast->getRight()];
			}
			
			if ($ast instanceof AstAny) {
				return [$ast->getConditions(), $ast->getIdentifier()];
			}
			
			if ($ast instanceof AstSubquery) {
				return [$ast->getAggregation(), $ast->getConditions()];
			}
			
			// AstSearch and AstSearchScore share the same child shape
			if ($ast instanceof AstSearch || $ast instanceof AstSearchScore) {
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
			return ltrim(strrchr(get_class($node), '\\'), '\\');
		}
	}