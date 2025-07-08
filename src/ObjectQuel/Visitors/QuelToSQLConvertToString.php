<?php
	
	// Namespace declaration voor gestructureerde code
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors;
	
	// Importeer de vereiste klassen en interfaces
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
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsEmpty;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsFloat;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsInteger;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsNumeric;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNot;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeJsonSource;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRegExp;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearch;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstUCount;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;
	
	/**
	 * Class QuelToSQLConvertToString
	 * Implements AstVisitor to collect entities from an AST.
	 */
	class QuelToSQLConvertToString implements AstVisitorInterface {
		
		// The entity store for entity to table conversions
		private EntityStore $entityStore;
		
		// Array to store collected entities
		private array $result;
		private array $visitedNodes;
		private array $parameters;
		private string $partOfQuery;
		private TypeMapper $typeMapper;
		
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
			$this->typeMapper = new TypeMapper();
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
					return $this->typeMapper->phinxTypeToPhpType($annotation->getType());
				}
			}
			
			return null;
		}
		
		/**
		 * Recursively infers the return type of an AST node and its children
		 * @param AstInterface $ast Abstract syntax tree node
		 * @return string|null Inferred return type or null if none found
		 */
		public function inferReturnType(AstInterface $ast): ?string {
			// Boolean operations
			if ($ast instanceof AstBinaryOperator || $ast instanceof AstExpression) {
				return 'boolean';
			}
			
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
			if ($ast instanceof AstIdentifier) {
				if ($ast->hasNext()) {
					$this->addToVisitedNodes($ast->getNext());
				}
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
			$conditions = [];
			
			foreach ($search->getIdentifiers() as $identifier) {
				// Mark nodes as visited
				$this->addToVisitedNodes($identifier);
				
				// Get column name
				$entityName = $identifier->getEntityName();
				$rangeName = $identifier->getRange()->getName();
				$propertyName = $identifier->getNext()->getName();
				$columnMap = $this->entityStore->getColumnMap($entityName);
				$columnName = "{$rangeName}.{$columnMap[$propertyName]}";
				
				// Build conditions for this identifier
				$fieldConditions = [];
				$termTypes = [
					'or_terms'  => ['operator' => 'OR', 'comparison' => 'LIKE'],
					'and_terms' => ['operator' => 'AND', 'comparison' => 'LIKE'],
					'not_terms' => ['operator' => 'AND', 'comparison' => 'NOT LIKE']
				];
				
				foreach ($termTypes as $termType => $config) {
					$termConditions = [];
					
					foreach ($parsed[$termType] as $i => $term) {
						$paramName = "{$termType}{$searchKey}{$i}";
						$termConditions[] = "{$columnName} {$config['comparison']} :{$paramName}";
						$this->parameters[$paramName] = "%{$term}%";
					}
					
					if (!empty($termConditions)) {
						$fieldConditions[] = '(' . implode(" {$config['operator']} ", $termConditions) . ')';
					}
				}
				
				if (!empty($fieldConditions)) {
					$conditions[] = '(' . implode(' AND ', $fieldConditions) . ')';
				}
			}
			
			// Combine all field conditions with OR
			$this->result[] = '(' . implode(" OR ", $conditions) . ')';
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
		 * Processes a generic expression in an Abstract Syntax Tree (AST).
		 * This function checks for special cases like '= string', which might
		 * be converted to a LIKE expression in SQL, and regular expressions,
		 * before processing the standard expression.
		 * @param AstInterface $ast The AST node that represents the expression.
		 * @param string $operator The operator in the expression (for example '=', '<', etc.).
		 * @return void
		 */
		protected function genericHandleExpression(AstInterface $ast, string $operator): void {
			// Check if the operator is equal to "=" or "<>", the only supported special operators.
			if (in_array($operator, ["=", "<>"], true)) {
				// Handle the case where the right side of the expression is a string.
				if ($ast->getRight() instanceof AstString) {
					$stringAst = $ast->getRight();
					$stringValue = $stringAst->getValue();
					
					// Check if the string value contains wildcard characters.
					if (str_contains($stringValue, "*") || str_contains($stringValue, "?")) {
						// Add the string to visited nodes for possible further processing.
						$this->addToVisitedNodes($stringAst);
						
						// Process the left side of the expression.
						$ast->getLeft()->accept($this);
						
						// Replace wildcard characters with SQL LIKE syntax equivalents.
						$stringValue = str_replace(["%", "_", "*", "?"], ["\\%", "\\_", "%", "_"], $stringValue);
						
						// Determine the LIKE operator based on the original operator.
						$regexpOperator = $operator == "=" ? " LIKE " : " NOT LIKE ";
						
						// Add the result with the adjusted operator and value.
						$this->result[] = "{$regexpOperator}\"{$stringValue}\"";
						return;
					}
				}
				
				// Handle the case where the right side of the expression is a regular expression.
				if ($ast->getRight() instanceof AstRegExp) {
					$regexpAst = $ast->getRight();
					$stringValue = $regexpAst->getValue();
					
					// Add the regular expression to visited nodes.
					$this->addToVisitedNodes($regexpAst);
					
					// Process the left side of the expression.
					$ast->getLeft()->accept($this);
					
					// Determine the REGEXP operator based on the original operator.
					$regexpOperator = $operator == "=" ? " REGEXP " : " NOT REGEXP ";
					
					// Add the result with the adjusted operator and value of the regular expression.
					$this->result[] = "{$regexpOperator}\"{$stringValue}\"";
					return;
				}
			}
			
			// If none of the special cases apply, process the expression in the standard way.
			// This includes accepting the left side of the expression, adding the operator,
			// and then accepting the right side of the expression.
			$ast->getLeft()->accept($this);
			$this->result[] = " {$operator} ";
			$ast->getRight()->accept($this);
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
			// Process an entity separately
			$expression = $ast->getExpression();
			
			if ($this->identifierIsEntity($expression)) {
				$this->addToVisitedNodes($expression);
				$this->handleEntity($expression);
				return;
			}
			
			// Process the expression that comes before the alias.
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
			$this->result[] = "\"{$ast->getValue()}\"";
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
		 * Processes an entity
		 * @param AstInterface $ast
		 * @return void
		 */
		protected function handleEntity(AstInterface $ast): void {
			$result = [];
			$range = $ast->getRange();
			$rangeName = $range->getName();
			$columnMap = $this->entityStore->getColumnMap($ast->getEntityName());
			
			foreach($columnMap as $item => $value) {
				$result[] = "{$rangeName}.{$value} as `{$rangeName}.{$item}`";
			}
			
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
		 * Parse count function
		 * @param AstInterface $ast
		 * @param bool $distinct
		 * @return void
		 */
		protected function universalHandleCount(AstInterface $ast, bool $distinct): void {
			// Get the identifier (entity or property) that needs to be counted.
			$identifier = $ast->getIdentifier();
			
			// If the identifier is an entity, we count the number of unique instances of this entity.
			if ($this->identifierIsEntity($identifier)) {
				// Add the entity to the list of visited nodes.
				$this->addToVisitedNodes($identifier);
				
				// Get the range and name of the entity.
				$range = $identifier->getRange()->getName();
				$entityName = $identifier->getName();
				
				// Get the column names that determine the identification of the entity.
				$identifierColumns = $this->entityStore->getIdentifierColumnNames($entityName);
				
				// Add the COUNT DISTINCT operation to the result, to count unique entities.
				if ($distinct) {
					$this->result[] = "COUNT(DISTINCT {$range}.{$identifierColumns[0]})";
				} else {
					$this->result[] = "COUNT({$range}.{$identifierColumns[0]})";
				}
			}
			
			// If the identifier is a specific property within an entity, we count how often this property occurs.
			if ($identifier instanceof AstIdentifier) {
				// Add the property and the associated entity to the list of visited nodes.
				$this->addToVisitedNodes($identifier);
				
				// Get the range of the entity where the property is part of.
				$range = $identifier->getRange()->getName();
				
				// Get the property name and the corresponding column name in the database.
				$property = $identifier->getNext()->getName();
				$columnMap = $this->entityStore->getColumnMap($identifier->getEntityName());
				
				// Add the COUNT operation to the result, to count the frequency of a specific property.
				if ($distinct) {
					$this->result[] = "COUNT(DISTINCT {$range}.{$columnMap[$property]})";
				} else {
					$this->result[] = "COUNT({$range}.{$columnMap[$property]})";
				}
			}
		}
		
		/**
		 * This function processes the 'count' command within an abstract syntax tree (AST).
		 * @param AstCount $count
		 * @return void
		 */
		protected function handleCount(AstCount $count): void {
			$this->universalHandleCount($count, false);
		}
		
		/**
		 * This function processes the 'count' command within an abstract syntax tree (AST).
		 * @param AstUCount $count
		 * @return void
		 */
		protected function handleUCount(AstUCount $count): void {
			$this->universalHandleCount($count, true);
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
			$this->visitNode($ast);
			
			// Fetch the node value
			$valueNode = $ast->getValue();
			
			// Special case for number. This will always be true
			if ($valueNode instanceof AstNumber) {
				$this->addToVisitedNodes($valueNode);
				$this->result[] = "1";
				return;
			}
			
			// Handle boolean and null values - they are never numeric
			if ($valueNode instanceof AstBool || $valueNode instanceof AstNull) {
				$this->addToVisitedNodes($valueNode);
				$this->result[] = "0";
				return;
			}
			
			// Handle string literals - check if the string matches a valid numeric pattern
			if ($valueNode instanceof AstString) {
				$this->addToVisitedNodes($valueNode);
				$string = "'" . addslashes($valueNode->getValue()) . "'";
				$this->result[] = "{$string} REGEXP '^-?[0-9]+(\\.[0-9]+)?$'";
				return;
			}

			// Handle identifiers (variables, function calls, etc.)
			$inferredType = $this->inferReturnType($valueNode);
			$string = $this->visitNodeAndReturnSQL($valueNode);
			
			// Return the appropriate result based on the inferred type
			if (($inferredType === 'float') || ($inferredType == "integer")) {
				$this->result[] = "1";
			} else {
				$this->result[] = "{$string} REGEXP '^-?[0-9]+(\\.[0-9]+)?$'"; // For unknown types, check if the value matches the float pattern
			}
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
			// Add AstIsInteger handles nodes list
			$this->visitNode($ast);
			
			// Fetch the node value
			$valueNode = $ast->getValue();
			
			// Handle string literals - check if the string matches a valid integer pattern
			if ($valueNode instanceof AstString) {
				$this->addToVisitedNodes($valueNode);
				$string = "'" . addslashes($valueNode->getValue()) . "'";
				$this->result[] = "{$string} REGEXP '^-?[0-9]+$'";
				return;
			}
			
			// Handle numeric values - check if the number has no decimal point
			if ($valueNode instanceof AstNumber) {
				$this->addToVisitedNodes($valueNode);
				$this->result[] = !str_contains($valueNode->getValue(), ".") ? "1" : "0";
				return;
			}
			
			// Handle boolean and null values - they are never integers
			if ($valueNode instanceof AstBool || $valueNode instanceof AstNull) {
				$this->addToVisitedNodes($valueNode);
				$this->result[] = "0";
				return;
			}
			
			// Handle identifiers (variables, function calls, etc.)
			$inferredType = $this->inferReturnType($valueNode);
			$string = $this->visitNodeAndReturnSQL($valueNode);
			
			// Return appropriate result based on the inferred type
			if ($inferredType == "integer") {
				$this->result[] = "1";    // Known integer types are always integers
			} elseif ($inferredType == "float") {
				$this->result[] = "0";    // Float types are never integers
			} else {
				// For unknown types, check if the value matches an integer pattern
				$this->result[] = "{$string} REGEXP '^-?[0-9]+$'";
			}
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
			$this->visitNode($ast);
			
			// Fetch the node value
			$valueNode = $ast->getValue();
			
			// Handle string literals - check if the string matches a valid float pattern
			if ($valueNode instanceof AstString) {
				$this->addToVisitedNodes($ast->getValue());
				$string = "'" . addslashes($valueNode->getValue()) . "'";
				$this->result[] = "{$string} REGEXP '^-?[0-9]+\\.[0-9]+$'";
				return;
			}
			
			// Handle numeric values - a number is a float if it's not equal to its floor value
			if ($valueNode instanceof AstNumber) {
				$this->addToVisitedNodes($ast->getValue());
				$this->result[] = str_contains($valueNode->getValue(), ".") ? "1" : "0";
				return;
			}
			
			// Handle boolean and null values - they are never floats
			if ($valueNode instanceof AstBool || $valueNode instanceof AstNull) {
				$this->addToVisitedNodes($ast->getValue());
				$this->result[] = "0";
				return;
			}
			
			// Handle identifiers (variables, function calls, etc.)
			$inferredType = $this->inferReturnType($valueNode);
			$string = $this->visitNodeAndReturnSQL($ast->getValue());
			
			// Return the appropriate result based on the inferred type
			if ($inferredType == "integer") {
				$this->result[] = "0"; // Known integer types are never floats
			} elseif ($inferredType == "float") {
				$this->result[] = "1"; // Known float types are always floats
			} else {
				$this->result[] = "{$string} REGEXP '^-?[0-9]+\\.[0-9]+$'"; // For unknown types, check if the value matches the float pattern
			}
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
			$className = ltrim(strrchr(get_class($node), '\\'), '\\');
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
	}