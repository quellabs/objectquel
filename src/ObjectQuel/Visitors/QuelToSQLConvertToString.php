<?php
	
	// Namespace declaration for structured code
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	// Import the required classes and interfaces
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNotNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstConcat;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
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
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRegExp;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearch;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class QuelToSQLConvertToString
	 * Implements AstVisitor to collect entities from an AST.
	 */
	class QuelToSQLConvertToString implements AstVisitorInterface {
		
		// Wildcard character mappings for SQL LIKE patterns
		private const array WILDCARD_MAPPINGS = [
			'%' => '\\%',   // Escape existing SQL wildcards
			'_' => '\\_',   // Escape existing SQL wildcards
			'*' => '%',     // Convert asterisk to SQL any-sequence wildcard
			'?' => '_'      // Convert question mark to SQL single-character wildcard
		];
		
		// Regular expression patterns for type checking
		private const array REGEX_PATTERNS = [
			'NUMERIC' => '^-?[0-9]+(\\.[0-9]+)?$',  // Matches integers and floats
			'INTEGER' => '^-?[0-9]+$',              // Matches only integers
			'FLOAT' => '^-?[0-9]+\\.[0-9]+$'        // Matches only floats with decimal point
		];
		
		// The entity store for entity to table conversions
		private EntityStore $entityStore;
		
		// Array to store collected entities
		private array $result;
		private array $visitedNodes;
		private array $parameters;
		private string $partOfQuery;
		
		/**
		 * Constructor to initialize the entities array.
		 * @param EntityStore $store
		 * @param array $parameters
		 * @param string $partOfQuery
		 */
		public function __construct(EntityStore $store, array &$parameters, string $partOfQuery="VALUES") {
			$this->result = [];
			$this->visitedNodes = [];
			$this->entityStore = $store;
			$this->parameters = &$parameters;
			$this->partOfQuery = $partOfQuery;
		}

		/**
		 * Visit a node in the AST.
		 * @param AstInterface $node The node to visit.
		 * @return void
		 */
		public function visitNode(AstInterface $node): void {
			// Generate a unique hash for the object to prevent duplicates.
			$objectHash = spl_object_id($node);
			
			// If the object has already been visited, skip it to prevent infinite loops.
			if (isset($this->visitedNodes[$objectHash])) {
				return;
			}
			
			// Mark the object as visited.
			$this->visitedNodes[$objectHash] = true;
			
			// Determine the name of the method that will handle this specific type of Ast node.
			// The 'substr' function is used to get the relevant parts of the class name.
			$className = $this->extractClassName($node);
			$handleMethod = 'handle' . substr($className, 3);
			
			// Check if the determined method exists and call it if that is the case.
			if (method_exists($this, $handleMethod)) {
				$this->{$handleMethod}($node);
			}
		}
		
		/**
		 * Visit a node in the AST and return the SQL
		 * @param AstInterface $node The node to visit.
		 * @return string
		 */
		public function visitNodeAndReturnSQL(AstInterface $node): string {
			$pos = count($this->result);
			
			$this->visitNode($node);
			
			$slice = implode("", array_slice($this->result, $pos, 1));
			
			$this->result = array_slice($this->result, 0, $pos);
			
			return $slice;
		}
		
		/**
		 * Get the collected entities.
		 * @return string The produced string
		 */
		public function getResult(): string {
			return implode("", $this->result);
		}
		
		/**
		 * Returns true if the identifier is an entity, false if not
		 * @param AstInterface $ast
		 * @return bool
		 */
		protected function identifierIsEntity(AstInterface $ast): bool {
			return (
				$ast instanceof AstIdentifier &&
				$ast->getRange() instanceof AstRangeDatabase &&
				!$ast->hasNext()
			);
		}
		
		/**
		 * Determines the return type of the identifier by checking its annotations
		 * @param AstIdentifier $identifier Entity identifier to analyze
		 * @return string|null Column type if found in annotations, null otherwise
		 */
		private function inferReturnTypeOfIdentifier(AstIdentifier $identifier): ?string {
			// Get all annotations for the entity
			$annotationList = $this->entityStore->getAnnotations($identifier->getEntityName());
			
			// Check if identifier has annotations
			if (!isset($annotationList[$identifier->getName()])) {
				return null;
			}
			
			// Search for Column annotation to get type
			foreach ($annotationList[$identifier->getName()] as $annotation) {
				if ($annotation instanceof Column) {
					return TypeMapper::phinxTypeToPhpType($annotation->getType());
				}
			}
			
			return null;
		}
		
		/**
		 * Recursively infers the return type of AST node and its children
		 * @param AstInterface $ast Abstract syntax tree node
		 * @return string|null Inferred return type or null if none found
		 */
		public function inferReturnType(AstInterface $ast): ?string {
			// Process identifiers
			if ($ast instanceof AstIdentifier) {
				return $this->inferReturnTypeOfIdentifier($ast);
			}
			
			// Traverse down the parse tree
			if ($ast instanceof AstTerm || $ast instanceof AstFactor) {
				$left = $this->inferReturnType($ast->getLeft());
				$right = $this->inferReturnType($ast->getRight());
				
				if (($left === "float") || ($right === "float")) {
					return 'float';
				} elseif (($left === "string") || ($right === "string")) {
					return 'string';
				} else {
					return $left;
				}
			}
			
			// Default to node's declared return type
			return $ast->getReturnType();
		}
		
		/**
		 * Mark the object as visited.
		 * @param AstInterface $ast
		 * @return void
		 */
		protected function addToVisitedNodes(AstInterface $ast): void {
			// Add node to the visited list
			$this->visitedNodes[spl_object_id($ast)] = true;
			
			// Also add all AstIdentifier child properties
			if ($ast instanceof AstIdentifier && $ast->hasNext()) {
				$this->addToVisitedNodes($ast->getNext());
			}
		}
		
		/**
		 * Convert search operator to SQL
		 * @param AstSearch $search
		 * @return void
		 */
		protected function handleSearch(AstSearch $search): void {
			$searchKey = uniqid();
			$parsed = $search->parseSearchData($this->parameters);
			$conditions = $this->buildSearchConditions($search, $parsed, $searchKey);
			
			// Combine all field conditions with OR
			$this->result[] = '(' . implode(" OR ", $conditions) . ')';
		}

		
		/**
		 * Handles generic expression processing with support for special string cases.
		 * Processes AST nodes for standard comparisons and delegates wildcard/regex handling to specialized methods.
		 * @param AstInterface $ast The AST node to process
		 * @param string $operator The comparison operator
		 */
		protected function genericHandleExpression(AstInterface $ast, string $operator): void {
			// We can only call getLeft/getRight on these nodes
			if (
				!$ast instanceof AstTerm &&
				!$ast instanceof AstBinaryOperator &&
				!$ast instanceof AstExpression &&
				!$ast instanceof AstFactor
			) {
				return;
			}
			
			if (in_array($operator, ['=', '<>'], true)) {
				$rightAst = $ast->getRight();
				
				if (
					($rightAst instanceof AstString && $this->handleWildcardString($rightAst, $ast, $operator)) ||
					($rightAst instanceof AstRegExp && $this->handleRegularExpression($rightAst, $ast, $operator))
				) {
					return;
				}
			}
			
			$ast->getLeft()->accept($this);
			$this->result[] = " {$operator} ";
			$ast->getRight()->accept($this);
		}
		
		/**
		 * Processes an AstConcat object and converts it to the SQL CONCAT function.
		 * @param AstConcat $concat The AstConcat object with the parameters to process.
		 * @return void
		 */
		protected function handleConcat(AstConcat $concat): void {
			// Start the CONCAT function in SQL.
			$this->result[] = "CONCAT(";
			
			// Loop through all parameters of the AstConcat object.
			$counter = 0;
			
			foreach($concat->getParameters() as $parameter) {
				// If this is not the first item, add a comma.
				if ($counter > 0) {
					$this->result[] = ",";
				}
				
				// Accept the current parameter object and process it.
				$parameter->accept($this);
				++$counter;
			}
			
			// Close the CONCAT function in SQL.
			$this->result[] = ")";
		}
		
		/**
		 * Processes an AstExpression object
		 * @param AstExpression $ast The AstExpression object
		 * @return void
		 */
		protected function handleExpression(AstExpression $ast): void {
			$this->genericHandleExpression($ast, $ast->getOperator());
		}
		
		/**
		 * Processes an AstTerm object
		 * @param AstTerm $ast The AstTerm object
		 * @return void
		 */
		protected function handleTerm(AstTerm $ast): void {
			$this->genericHandleExpression($ast, $ast->getOperator());
		}
		
		/**
		 * Processes an AstFactor object
		 * @param AstFactor $ast The AstFactor object
		 * @return void
		 */
		protected function handleFactor(AstFactor $ast): void {
			$this->genericHandleExpression($ast, $ast->getOperator());
		}
		
		/**
		 * Processes an AstBinaryOperator object and converts it to SQL with an alias.
		 * @param AstBinaryOperator $ast The AstBinaryOperator object
		 * @return void
		 */
		protected function handleBinaryOperator(AstBinaryOperator $ast): void {
			$this->genericHandleExpression($ast, $ast->getOperator());
		}
		
		/**
		 * Processes an AstAlias object and converts it to SQL with an alias.
		 * @param AstAlias $ast The AstAlias object that contains an expression and an alias.
		 * @return void
		 */
		protected function handleAlias(AstAlias $ast): void {
			// Extract the expression part from the alias (e.g., in "users u", this gets "users")
			$expression = $ast->getExpression();
			
			// Early return if the expression is not an identifier
			// This handles cases where the expression might be a subquery or other complex expression
			if (!$expression instanceof AstIdentifier) {
				return;
			}
			
			// Check if this identifier represents an entity (table/model reference)
			// Entities need special handling compared to regular column references
			if ($this->identifierIsEntity($expression)) {
				// Mark this node as visited to prevent infinite recursion
				// This is important for circular references in the AST
				$this->addToVisitedNodes($expression);
				
				// Handle entity-specific processing (likely adds table name to FROM clause)
				$this->handleEntity($expression);
				return;
			}
			
			// For non-entity identifiers (columns, functions, etc.),
			// use the visitor pattern to process the expression normally
			// This will handle the SQL generation for the expression part of the alias
			$ast->getExpression()->accept($this);
		}
		
		/**
		 * Add NOT to the output stream
		 * @param AstNot $ast
		 * @return void
		 */
		protected function handleNot(AstNot $ast): void {
			$this->result[] = " NOT ";
		}
		
		/**
		 * Processes an AstNull object
		 * @param AstNull $ast The AstNull object
		 * @return void
		 */
		protected function handleNull(AstNull $ast): void {
			$this->result[] = 'null';
		}
		
		/**
		 * Processes an AstBool object
		 * @param AstBool $ast The AstBool object
		 * @return void
		 */
		protected function handleBool(AstBool $ast): void {
			$this->result[] = $ast->getValue() ? "true" : "false";
		}
		
		/**
		 * Processes an AstNumber object
		 * @param AstNumber $ast The AstNumber object
		 * @return void
		 */
		protected function handleNumber(AstNumber $ast): void {
			$this->result[] = $ast->getValue();
		}
		
		/**
		 * Processes an AstString object
		 * @param AstString $ast The AstString object
		 * @return void
		 */
		protected function handleString(AstString $ast): void {
			$this->result[] = "\"" . addslashes($ast->getValue()) . "\"";
		}
		
		/**
		 * Processes an AstIdentifier object
		 * @param AstIdentifier $ast The AstIdentifier object
		 * @return void
		 */
		protected function handleIdentifier(AstIdentifier $ast): void {
			// Add the identifier and all properties to the 'visited nodes' list
			$this->addToVisitedNodes($ast);
			
			// Omit the information from the query if the range is not a database range
			if ($ast->getRange() instanceof AstRangeJsonSource) {
				return;
			}
			
			// Get information from the identifier
			$range = $ast->getRange();
			$rangeName = $range->getName();
			$entityName = $ast->getEntityName();
			$propertyName = $ast->getNext()->getName();
			$columnMap = $this->entityStore->getColumnMap($entityName);
			
			// If this is not the 'SORT BY' section, add the normalized property
			if ($this->partOfQuery !== "SORT") {
				$this->result[] = $rangeName . "." . $columnMap[$propertyName];
				return;
			}
			
			// If this is a 'SORT BY', then we may need to convert a NULL value to COALESCE.
			// Without COALESCE, sorting will not be correct.
			$annotations = $this->entityStore->getAnnotations($entityName);
			$annotationsOfProperty = array_values(array_filter($annotations[$propertyName], function($e) { return $e instanceof Column; }));
			
			if (!$annotationsOfProperty[0]->isNullable()) {
				$this->result[] = $rangeName . "." . $columnMap[$propertyName];
			} elseif ($annotationsOfProperty[0]->getType() === "integer") {
				$this->result[] = "COALESCE({$rangeName}.{$columnMap[$propertyName]}, 0)";
			} else {
				$this->result[] = "COALESCE({$rangeName}.{$columnMap[$propertyName]}, '')";
			}
		}
		
		/**
		 * Processes an AstParameter object
		 * @param AstParameter $ast The AstParameter object
		 * @return void
		 */
		protected function handleParameter(AstParameter $ast): void {
			$this->result[] = ":" . $ast->getName();
		}
		
		/**
		 * Processes an entity by generating SQL column selections with proper aliasing
		 * @param AstInterface $ast The AST node to process
		 * @return void
		 */
		protected function handleEntity(AstInterface $ast): void {
			// Early return if the AST node is not an identifier
			// Only AstIdentifier nodes represent entities that can be processed
			if (!$ast instanceof AstIdentifier) {
				return;
			}
			
			// Initialize an array to store the generated column selections
			$result = [];
			
			// Get the range object from the AST identifier
			// The range typically represents a table or entity reference
			$range = $ast->getRange();
			
			// Extract the name from the range (e.g., table alias or table name)
			$rangeName = $range->getName();
			
			// Retrieve the column mapping for this entity from the entity store
			// This maps logical column names to actual database column names
			$columnMap = $this->entityStore->getColumnMap($ast->getEntityName());
			
			// Iterate through each column in the entity's column map
			foreach($columnMap as $item => $value) {
				// Generate an SQL column selection with alias
				// Format: "table_name.actual_column as `table_name.logical_column`"
				// This creates properly aliased columns for the SELECT statement
				$result[] = "{$rangeName}.{$value} as `{$rangeName}.{$item}`";
			}
			
			// Join all column selections with commas and add to the main result array
			// This builds the column list portion of the SQL SELECT statement
			$this->result[] = implode(",", $result);
		}
		
		/**
		 * Processes the 'IN' condition of a SQL query.
		 * The 'IN' condition is used to check if a value
		 * matches a value in a list of values.
		 * @param AstIn $ast An object that represents the 'IN' clause.
		 * @return void
		 */
		protected function handleIn(AstIn $ast): void {
			// flag the identifier node as processed
			$this->visitNode($ast->getIdentifier());
			
			// Add the start of the 'IN' condition to the result.
			$this->result[] = " IN(";
			
			// A flag to check if we are processing the first item.
			$first = true;
			
			// Loop through each item that needs to be checked within the 'IN' condition.
			foreach($ast->getParameters() as $item) {
				// If it's not the first item, add a comma for separation.
				if (!$first) {
					$this->result[] = ",";
				}
				
				// Process the item and add it to the result.
				$this->visitNode($item);
				
				// Set the flag to 'false' because we are no longer at the first item.
				$first = false;
			}
			
			// Add the closing bracket to the 'IN' condition.
			$this->result[] = ")";
		}
		
		/**
		 * This function processes the 'count' command within an abstract syntax tree (AST).
		 * @param AstCount $count
		 * @return void
		 */
		protected function handleCount(AstCount $count): void {
			$this->universalHandleAggregates($count, false, 'COUNT');
		}
		
		/**
		 * This function processes the 'count' command within an abstract syntax tree (AST).
		 * @param AstCountU $count
		 * @return void
		 */
		protected function handleUCount(AstCountU $count): void {
			$this->universalHandleAggregates($count, true, 'COUNT');
		}
		
		/**
		 * This function processes the 'average' command within an abstract syntax tree (AST).
		 * @param AstAvg $avg
		 * @return void
		 */
		protected function handleAvg(AstAvg $avg): void {
			$this->universalHandleAggregates($avg, false, 'AVG');
		}
		
		/**
		 * This function processes the 'average unique' command within an abstract syntax tree (AST).
		 * @param AstAvgU $avg
		 * @return void
		 */
		protected function handleAvgU(AstAvgU $avg): void {
			$this->universalHandleAggregates($avg, true, 'AVG');
		}
		
		/**
		 * Processes the MAX aggregate function within an abstract syntax tree (AST).
		 * @param AstMax $max The MAX AST node to process
		 * @return void
		 */
		protected function handleMax(AstMax $max): void {
			$this->universalHandleAggregates($max, false, 'MAX');
		}
		
		/**
		 * Processes the MIN aggregate function within an abstract syntax tree (AST).
		 * @param AstMin $min The MIN AST node to process
		 * @return void
		 */
		protected function handleMin(AstMin $min): void {
			$this->universalHandleAggregates($min, false, 'MIN');
		}
		
		/**
		 * Processes the SUM aggregate function within an abstract syntax tree (AST).
		 * @param AstSum $sum The SUM AST node to process
		 * @return void
		 */
		protected function handleSum(AstSum $sum): void {
			$this->universalHandleAggregates($sum, false, 'SUM');
		}
		
		/**
		 * Handles 'IS NULL'. The SQL equivalent is exactly the same.
		 * @param AstCheckNull $ast
		 * @return void
		 */
        protected function handleCheckNull(AstCheckNull $ast): void {
            $this->visitNode($ast->getExpression());
            $this->result[] = " IS NULL ";
        }
        
        /**
         * Handles 'IS NOT NULL'. The SQL equivalent is exactly the same.
         * @param AstCheckNotNull $ast
         * @return void
         */
        protected function handleCheckNotNull(AstCheckNotNull $ast): void {
            $this->visitNode($ast->getExpression());
            $this->result[] = " IS NOT NULL ";
        }
		
		/**
		 * Handle is_empty function
		 * @param AstIsEmpty $ast
		 * @return void
		 */
		protected function handleIsEmpty(AstIsEmpty $ast): void {
			$this->visitNode($ast);
			
			// Fetch the node value
			$valueNode = $ast->getValue();

			// Special case for null
			if ($valueNode instanceof AstNull) {
				$this->addToVisitedNodes($valueNode);
				$this->result[] = "1";
				return;
			}
			
			// Special case for numbers
			if ($valueNode instanceof AstNumber) {
				$this->addToVisitedNodes($valueNode);
				$value = (int)$valueNode->getValue();
				$this->result[] = $value == 0 ? "1" : "0";
				return;
			}
			
			// Special case for bool
			if ($valueNode instanceof AstBool) {
				$this->addToVisitedNodes($valueNode);
				$this->result[] = !$valueNode->getValue();
				return;
			}

			// Special case for strings
			if ($valueNode instanceof AstString) {
				$this->addToVisitedNodes($valueNode);
				$this->result[] = $valueNode->getValue() === "" ? "1" : "0";
				return;
			}
			
			// Identifiers
			$inferredType = $this->inferReturnType($valueNode);
			$string = $this->visitNodeAndReturnSQL($valueNode);
			
			if (($inferredType === "integer") || ($inferredType === "float")) {
				$this->result[] = "({$string} IS NULL OR {$string} = 0)";
			} else {
				$this->result[] = "({$string} IS NULL OR {$string} = '')";
			}
		}
		
		/**
		 * Handle is_numeric function
		 * @param AstIsNumeric $ast
		 * @return void
		 */
		protected function handleIsNumeric(AstIsNumeric $ast): void {
			$this->handleTypeCheckWithPattern($ast, 'NUMERIC');
		}
		
		/**
		 * Handles the is_integer type checking operation for different AST node types.
		 * Determines whether a given AST node represents an integer value.
		 *
		 * The function handles these cases:
		 * - String literals: checks if the string matches an integer pattern
		 * - Numbers: checks if the number contains no decimal point
		 * - Booleans: always returns false (0) as booleans are never integers
		 * - Identifiers: checks based on inferred type or pattern matching
		 * @param AstIsInteger $ast The AST node representing the is_integer check
		 * @return void
		 */
		protected function handleIsInteger(AstIsInteger $ast): void {
			$this->handleTypeCheckWithPattern($ast, 'INTEGER');
		}
		
		/**
		 * Handles the is_float type checking operation for different AST node types.
		 * Determines whether a given AST node represents a floating point value.
		 *
		 * The function handles these cases:
		 * - String literals: checks if the string matches a float pattern
		 * - Numbers: checks if the number is not equal to its floor value
		 * - Booleans: always returns false (0) as booleans are never floats
		 * - Identifiers: checks based on inferred type or pattern matching
		 * @param AstIsFloat $ast The AST node representing the is_float check
		 * @return void
		 */
		protected function handleIsFloat(AstIsFloat $ast): void {
			$this->handleTypeCheckWithPattern($ast, 'FLOAT');
		}
		
		/**
		 * Universal handler for aggregate functions (COUNT, AVG, SUM, etc.)
		 * @param AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum $ast The aggregate AST node
		 * @param bool $distinct Whether to use DISTINCT
		 * @param string $aggregateFunction The SQL aggregate function name (COUNT, AVG, SUM, etc.)
		 * @return void
		 */
		private function universalHandleAggregates(
			AstCount|AstCountU|AstAvg|AstAvgU|AstMax|AstMin|AstSum $ast,
			bool $distinct,
			string $aggregateFunction
		): void {
			// Get the identifier (entity or property) that needs to be aggregated.
			$identifier = $ast->getIdentifier();
			
			// Early return if the expression is not an identifier
			// This handles cases where the expression might be a subquery or other complex expression
			if (!$identifier instanceof AstIdentifier) {
				return;
			}
			
			// If the identifier is an entity, we aggregate based on the primary identifier column
			if ($this->identifierIsEntity($identifier)) {
				// Add the entity to the list of visited nodes.
				$this->addToVisitedNodes($identifier);
				
				// Get the range and name of the entity.
				$range = $identifier->getRange()->getName();
				$entityName = $identifier->getEntityName();
				
				// Get the column names that determine the identification of the entity.
				$identifierColumns = $this->entityStore->getIdentifierColumnNames($entityName);
				
				// Add the aggregate operation to the result
				if ($distinct) {
					$this->result[] = "{$aggregateFunction}(DISTINCT {$range}.{$identifierColumns[0]})";
				} else {
					$this->result[] = "{$aggregateFunction}({$range}.{$identifierColumns[0]})";
				}
				
				return;
			}
			
			// If the identifier is a specific property within an entity, we aggregate that property
			// Add the property and the associated entity to the list of visited nodes.
			$this->addToVisitedNodes($identifier);
			
			// Get the range of the entity where the property is part of.
			$range = $identifier->getRange()->getName();
			
			// Get the property name and the corresponding column name in the database.
			$property = $identifier->getNext()->getName();
			$columnMap = $this->entityStore->getColumnMap($identifier->getEntityName());
			
			// Add the aggregate operation to the result
			if ($distinct) {
				$this->result[] = "{$aggregateFunction}(DISTINCT {$range}.{$columnMap[$property]})";
			} else {
				$this->result[] = "{$aggregateFunction}({$range}.{$columnMap[$property]})";
			}
		}
		
		/**
		 * Extract the name of a class from the object
		 * @param object $node
		 * @return string
		 */
		private function extractClassName(object $node): string {
			return ltrim(strrchr(get_class($node), '\\'), '\\');
		}
		
		/**
		 * Builds search conditions for multiple identifiers based on parsed search terms
		 * @param AstSearch $search The search AST node containing identifiers to search
		 * @param array $parsed Parsed search data containing or_terms, and_terms, and not_terms
		 * @param string $searchKey Unique key for parameter naming to avoid conflicts
		 * @return array Array of SQL condition strings
		 */
		private function buildSearchConditions(AstSearch $search, array $parsed, string $searchKey): array {
			$conditions = [];
			
			foreach ($search->getIdentifiers() as $identifier) {
				// Mark nodes as visited to prevent duplicate processing
				$this->addToVisitedNodes($identifier);
				
				// Resolve the column name for this identifier
				$columnName = $this->resolveSearchColumnName($identifier);
				
				// Build conditions for this specific identifier/column
				$fieldConditions = $this->buildFieldConditions($columnName, $parsed, $searchKey);
				
				if (!empty($fieldConditions)) {
					$conditions[] = '(' . implode(' AND ', $fieldConditions) . ')';
				}
			}
			
			return $conditions;
		}
		
		/**
		 * Resolves the full SQL column name for a search identifier
		 * @param AstIdentifier $identifier The identifier to resolve
		 * @return string The resolved column name (e.g., "alias.column_name")
		 */
		private function resolveSearchColumnName(AstIdentifier $identifier): string {
			$entityName = $identifier->getEntityName();
			$rangeName = $identifier->getRange()->getName();
			$propertyName = $identifier->getNext()->getName();
			$columnMap = $this->entityStore->getColumnMap($entityName);
			
			return "{$rangeName}.{$columnMap[$propertyName]}";
		}
		
		/**
		 * Builds field-specific conditions for different term types (OR, AND, NOT)
		 * @param string $columnName The SQL column name to search in
		 * @param array $parsed Parsed search terms organized by type
		 * @param string $searchKey Unique key for parameter naming
		 * @return array Array of condition strings for this field
		 */
		private function buildFieldConditions(string $columnName, array $parsed, string $searchKey): array {
			$fieldConditions = [];
			
			// Define term types and their SQL operators
			$termTypes = [
				'or_terms'  => ['operator' => 'OR', 'comparison' => 'LIKE'],
				'and_terms' => ['operator' => 'AND', 'comparison' => 'LIKE'],
				'not_terms' => ['operator' => 'AND', 'comparison' => 'NOT LIKE']
			];
			
			foreach ($termTypes as $termType => $config) {
				$termConditions = $this->buildTermConditions(
					$columnName,
					$parsed[$termType],
					$config,
					$termType,
					$searchKey
				);
				
				if (!empty($termConditions)) {
					$fieldConditions[] = '(' . implode(" {$config['operator']} ", $termConditions) . ')';
				}
			}
			
			return $fieldConditions;
		}
		
		/**
		 * Builds conditions for a specific term type (or_terms, and_terms, not_terms)
		 * @param string $columnName The SQL column name
		 * @param array $terms Array of search terms for this type
		 * @param array $config Configuration containing operator and comparison type
		 * @param string $termType The type of terms being processed
		 * @param string $searchKey Unique key for parameter naming
		 * @return array Array of individual term conditions
		 */
		private function buildTermConditions(
			string $columnName,
			array $terms,
			array $config,
			string $termType,
			string $searchKey
		): array {
			$termConditions = [];
			
			foreach ($terms as $index => $term) {
				$paramName = "{$termType}{$searchKey}{$index}";
				$termConditions[] = "{$columnName} {$config['comparison']} :{$paramName}";
				$this->parameters[$paramName] = "%{$term}%";
			}
			
			return $termConditions;
		}
		
		/**
		 * Handles wildcard string patterns by converting them to SQL LIKE syntax.
		 * Converts * (match any sequence) to % and ? (match single character) to _.
		 * @param AstString $rightAst The right-hand side AST node to check
		 * @param AstBinaryOperator|AstExpression|AstFactor|AstTerm $ast The full AST node
		 * @param string $operator The comparison operator
		 * @return bool True if wildcard string was handled, false otherwise
		 */
		private function handleWildcardString(AstString $rightAst, AstExpression|AstBinaryOperator|AstFactor|AstTerm $ast, string $operator): bool {
			// Get the value
			$stringValue = $rightAst->getValue();
			
			// Check if the string contains wildcard characters
			if (!str_contains($stringValue, "*") && !str_contains($stringValue, "?")) {
				return false;
			}
			
			// Mark this node as visited to prevent duplicate processing
			$this->addToVisitedNodes($rightAst);
			
			// Process the left-hand side of the comparison
			$ast->getLeft()->accept($this);
			
			// Convert wildcards using the constant mapping
			$stringValue = str_replace(
				array_keys(self::WILDCARD_MAPPINGS),
				array_values(self::WILDCARD_MAPPINGS),
				$stringValue
			);
			
			// Choose the appropriate LIKE operator based on the original operator
			$likeOperator = $operator === "=" ? " LIKE " : " NOT LIKE ";
			
			// Add the SQL LIKE clause to the result
			$this->result[] = "{$likeOperator}\"" . addslashes($stringValue) . "\"";

			return true;
		}
		
		/**
		 * Handle type checking operations using regex patterns from constants
		 * @param AstIsNumeric|AstIsInteger|AstIsFloat $ast The type check AST node
		 * @param string $patternKey The key for the regex pattern in REGEX_PATTERNS
		 * @return void
		 */
		private function handleTypeCheckWithPattern(AstIsNumeric|AstIsInteger|AstIsFloat $ast, string $patternKey): void {
			$this->visitNode($ast);
			$valueNode = $ast->getValue();
			
			// Handle string literals - check if the string matches the pattern
			if ($valueNode instanceof AstString) {
				$this->addToVisitedNodes($valueNode);
				$string = "'" . addslashes($valueNode->getValue()) . "'";
				$pattern = self::REGEX_PATTERNS[$patternKey];
				$this->result[] = "{$string} REGEXP '{$pattern}'";
				return;
			}
			
			// Handle numeric values
			if ($valueNode instanceof AstNumber) {
				$this->addToVisitedNodes($valueNode);
				
				$this->result[] = match($patternKey) {
					'NUMERIC' => "1",  // Numbers are always numeric
					'INTEGER' => !str_contains($valueNode->getValue(), ".") ? "1" : "0",
					'FLOAT' => str_contains($valueNode->getValue(), ".") ? "1" : "0",
					default => "0"
				};
				
				return;
			}
			
			// Handle boolean and null values - they are never numeric/integer/float
			if ($valueNode instanceof AstBool || $valueNode instanceof AstNull) {
				$this->addToVisitedNodes($valueNode);
				$this->result[] = "0";
				return;
			}
			
			// Handle identifiers based on inferred type
			$inferredType = $this->inferReturnType($valueNode);
			$string = $this->visitNodeAndReturnSQL($valueNode);
			
			$this->result[] = match([$patternKey, $inferredType]) {
				['NUMERIC', 'integer'], ['NUMERIC', 'float'] => "1",
				['INTEGER', 'integer'] => "1",
				['INTEGER', 'float'] => "0",
				['FLOAT', 'float'] => "1",
				['FLOAT', 'integer'] => "0",
				default => "{$string} REGEXP '" . self::REGEX_PATTERNS[$patternKey] . "'"
			};
		}
		
		/**
		 * Handles regular expression patterns by converting them to SQL REGEXP syntax.
		 * @param AstRegExp $rightAst The right-hand side AST node to check
		 * @param AstBinaryOperator|AstExpression|AstFactor|AstTerm $ast The full AST node
		 * @param string $operator The comparison operator
		 * @return bool True if regular expression was handled, false otherwise
		 */
		private function handleRegularExpression(AstRegExp $rightAst, AstExpression|AstBinaryOperator|AstFactor|AstTerm $ast, string $operator): bool {
			// Extract the regex pattern from the AST node
			$stringValue = $rightAst->getValue();
			
			// Mark this node as visited to prevent duplicate processing
			$this->addToVisitedNodes($rightAst);
			
			// Process the left-hand side of the comparison
			$ast->getLeft()->accept($this);
			
			// Choose appropriate REGEXP operator based on the original operator
			$regexpOperator = $operator === "=" ? " REGEXP " : " NOT REGEXP ";
			
			// Add the SQL REGEXP clause to the result
			$this->result[] = "{$regexpOperator}\"{$stringValue}\"";
			
			// Indicate that special case was handled
			return true;
		}
	}