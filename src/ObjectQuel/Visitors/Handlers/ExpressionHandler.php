<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors\Handlers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNotNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstConcat;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIfnull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsEmpty;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsFloat;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsInteger;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsNumeric;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNot;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRegExp;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearch;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	
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
	class ExpressionHandler {
		
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
			'INTEGER' => '^-?[0-9]+$',              // Only digits, no decimal point allowed
			'FLOAT'   => '^-?[0-9]+\\.[0-9]+$'        // Requires decimal point with digits on both sides
		];
		
		/** @var SqlBuilderHelper Helper for constructing SQL query components */
		private SqlBuilderHelper $sqlBuilder;
		
		/** @var TypeInferenceHelper Helper for determining data types from AST nodes */
		private TypeInferenceHelper $typeInference;
		
		/** @var array Reference to the parameter array for prepared statements */
		private array $parameters;
		
		/** @var mixed Reference to the main visitor to avoid circular dependencies */
		private $mainVisitor; // Reference to main visitor instead of interface
		
		/** @var string Temporary storage for the last processed result */
		private string $lastResult = '';
		
		/**
		 * Constructor - Initialize the expression handler with required dependencies
		 * @param SqlBuilderHelper $sqlBuilder Helper for SQL construction operations
		 * @param TypeInferenceHelper $typeInference Helper for type analysis
		 * @param array &$parameters Reference to parameters array for prepared statements
		 * @param mixed $mainVisitor Reference to the main AST visitor (avoids circular dependency)
		 */
		public function __construct(
			SqlBuilderHelper    $sqlBuilder,
			TypeInferenceHelper $typeInference,
			array               &$parameters,
			mixed               $mainVisitor
		) {
			$this->sqlBuilder = $sqlBuilder;
			$this->typeInference = $typeInference;
			$this->parameters = &$parameters; // Pass by reference to allow parameter modification
			$this->mainVisitor = $mainVisitor;
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
		 * @param AstInterface $ast The AST node representing the binary expression
		 * @param string $operator The SQL operator to use (=, <>, +, -, etc.)
		 * @return string The resulting SQL expression
		 */
		public function handleGenericExpression(AstInterface $ast, string $operator): string {
			// Type safety check - ensure we're working with expression-type nodes
			if (
				!$ast instanceof AstTerm &&
				!$ast instanceof AstBinaryOperator &&
				!$ast instanceof AstExpression &&
				!$ast instanceof AstFactor
			) {
				return ''; // Return empty string for unsupported node types
			}
			
			// Special processing for equality/inequality operators
			if (in_array($operator, ['=', '<>'], true)) {
				$rightAst = $ast->getRight();
				
				// Check if right side is a wildcard string pattern
				if ($rightAst instanceof AstString && $this->handleWildcardString($rightAst, $ast, $operator)) {
					return $this->getLastResult(); // Return the LIKE expression result
				}
				
				// Check if right side is a regular expression pattern
				if ($rightAst instanceof AstRegExp && $this->handleRegularExpression($rightAst, $ast, $operator)) {
					return $this->getLastResult(); // Return the REGEXP expression result
				}
			}
			
			// Standard binary operation processing
			// Visit both sides of the expression and combine with the operator
			$leftResult = $this->visitNodeAndReturnSQL($ast->getLeft());
			$rightResult = $this->visitNodeAndReturnSQL($ast->getRight());
			
			return "{$leftResult} {$operator} {$rightResult}";
		}
		
		/**
		 * Process concatenation operations and convert to SQL CONCAT function
		 * Takes an AstConcat node containing multiple parameters and generates
		 * a SQL CONCAT function call with all parameters properly processed.
		 * @param AstConcat $concat The concatenation AST node
		 * @return string SQL CONCAT function call
		 */
		public function handleConcat(AstConcat $concat): string {
			$result = ["CONCAT("]; // Start building the SQL function
			$counter = 0;
			
			// Process each parameter in the concatenation
			foreach ($concat->getParameters() as $parameter) {
				// Add comma separator for all parameters except the first
				if ($counter > 0) {
					$result[] = ",";
				}
				
				// Process the parameter and add to result
				$result[] = $this->visitNodeAndReturnSQL($parameter);
				++$counter;
			}
			
			$result[] = ")";
			
			// Combine all parts into final SQL
			return implode("", $result);
		}
		
		/**
		 * Process logical NOT operator
		 * Converts an AstNot node to the SQL NOT keyword with proper spacing.
		 * @param AstNot $ast The NOT AST node
		 * @return string SQL NOT operator with spacing
		 */
		public function handleNot(AstNot $ast): string {
			return " NOT ";
		}
		
		/**
		 * Process NULL literal values
		 * Converts an AstNull node to the SQL null keyword.
		 * @param AstNull $ast The NULL AST node
		 * @return string SQL null literal
		 */
		public function handleNull(AstNull $ast): string {
			return 'null';
		}
		
		/**
		 * Process boolean literal values
		 * Converts an AstBool node to SQL boolean representation.
		 * Uses "true"/"false" strings for SQL compatibility.
		 * @param AstBool $ast The boolean AST node
		 * @return string SQL boolean literal ("true" or "false")
		 */
		public function handleBool(AstBool $ast): string {
			return $ast->getValue() ? "true" : "false";
		}
		
		/**
		 * Process numeric literal values
		 * Converts an AstNumber node to its SQL numeric representation.
		 * Numbers are returned as-is since SQL can handle them directly.
		 * @param AstNumber $ast The number AST node
		 * @return string SQL numeric literal
		 */
		public function handleNumber(AstNumber $ast): string {
			return $ast->getValue();
		}
		
		/**
		 * Process string literal values
		 * Converts an AstString node to a properly escaped SQL string literal.
		 * Uses double quotes and addslashes() for SQL injection protection.
		 * @param AstString $ast The string AST node
		 * @return string SQL string literal with proper escaping
		 */
		public function handleString(AstString $ast): string {
			return "\"" . addslashes($ast->getValue()) . "\"";
		}
		
		/**
		 * Process parameter placeholders for prepared statements
		 * Converts an AstParameter node to a SQL parameter placeholder.
		 * Uses colon notation for named parameters (e.g., :paramName).
		 * @param AstParameter $ast The parameter AST node
		 * @return string SQL parameter placeholder
		 */
		public function handleParameter(AstParameter $ast): string {
			return ":" . $ast->getName();
		}
		
		/**
		 * Process SQL IN clauses
		 * Converts an AstIn node to a SQL IN clause with proper formatting.
		 * Handles multiple values within parentheses, comma-separated.
		 * @param AstIn $ast The IN clause AST node
		 * @return string Complete SQL IN clause
		 */
		public function handleIn(AstIn $ast): string {
			$result = [];
			
			// Process the identifier (field/column being checked)
			$result[] = $this->visitNodeAndReturnSQL($ast->getIdentifier());
			$result[] = " IN(";
			
			// Process all values in the IN list
			$first = true;
			
			foreach ($ast->getParameters() as $item) {
				// Add comma separator for all values except the first
				if (!$first) {
					$result[] = ",";
				}
				
				$result[] = $this->visitNodeAndReturnSQL($item);
				$first = false;
			}
			
			$result[] = ")";
			
			// Combine all parts into final SQL
			return implode("", $result);
		}
		
		/**
		 * Handle NULL checking operations
		 * Converts an AstCheckNull node to a SQL "IS NULL" condition.
		 * @param AstCheckNull $ast The null check AST node
		 * @return string SQL IS NULL condition
		 */
		public function handleCheckNull(AstCheckNull $ast): string {
			return $this->visitNodeAndReturnSQL($ast->getExpression()) . " IS NULL ";
		}
		
		/**
		 * Handle NOT NULL checking operations
		 * Converts an AstCheckNotNull node to a SQL "IS NOT NULL" condition.
		 * @param AstCheckNotNull $ast The not null check AST node
		 * @return string SQL IS NOT NULL condition
		 */
		public function handleCheckNotNull(AstCheckNotNull $ast): string {
			return $this->visitNodeAndReturnSQL($ast->getExpression()) . " IS NOT NULL ";
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
		 */
		public function handleIsEmpty(AstIsEmpty $ast): string {
			$valueNode = $ast->getValue();
			
			// Handle literal null values - always empty
			if ($valueNode instanceof AstNull) {
				return "1";
			}
			
			// Handle numeric literals - empty if value is 0
			if ($valueNode instanceof AstNumber) {
				$value = (int)$valueNode->getValue();
				return $value == 0 ? "1" : "0";
			}
			
			// Handle boolean literals - empty if false
			if ($valueNode instanceof AstBool) {
				return !$valueNode->getValue() ? "1" : "0";
			}
			
			// Handle string literals - empty if empty string
			if ($valueNode instanceof AstString) {
				return $valueNode->getValue() === "" ? "1" : "0";
			}
			
			// Handle identifiers (variables/fields) - build dynamic check
			$inferredType = $this->typeInference->inferReturnType($valueNode);
			$string = $this->visitNodeAndReturnSQL($valueNode);
			
			// Different empty checks based on inferred type
			if (($inferredType === "integer") || ($inferredType === "float")) {
				// Numeric types: empty if NULL or 0
				return "({$string} IS NULL OR {$string} = 0)";
			} else {
				// String types: empty if NULL or empty string
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
		 * Convert search operations to SQL conditions
		 * Processes search functionality by parsing search data and building
		 * appropriate SQL conditions. Uses OR logic to combine multiple search conditions.
		 * @param AstSearch $search The search AST node
		 * @return string SQL search conditions wrapped in parentheses
		 */
		public function handleSearch(AstSearch $search): string {
			// Generate unique key for this search operation
			$searchKey = uniqid();
			
			// Parse the search data using current parameters
			$parsed = $search->parseSearchData($this->parameters);
			
			// Build SQL conditions using the SQL builder helper
			$conditions = $this->sqlBuilder->buildSearchConditions($search, $parsed, $searchKey);
			
			// Combine all conditions with OR logic
			return '(' . implode(" OR ", $conditions) . ')';
		}
		
		/**
		 * IFNULL() serves as a simple COALESCE. If the expression returns NULL, use the alt value
		 * @param AstIfnull $ast
		 * @return string
		 */
		public function handleIfnull(AstIfNull $ast): string {
			return sprintf(
				"COALESCE(%s, %s)",
				$this->visitNodeAndReturnSQL($ast->getExpression()),
				$this->visitNodeAndReturnSQL($ast->getAltValue())
			);
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
			
			// Handle string literals - use SQL REGEXP with the pattern
			if ($valueNode instanceof AstString) {
				$string = "'" . addslashes($valueNode->getValue()) . "'";
				$pattern = self::REGEX_PATTERNS[$patternKey];
				return "{$string} REGEXP '{$pattern}'";
			}
			
			// Handle numeric literals - direct evaluation based on pattern type
			if ($valueNode instanceof AstNumber) {
				return match ($patternKey) {
					'NUMERIC' => "1", // All numbers are numeric
					'INTEGER' => !str_contains($valueNode->getValue(), ".") ? "1" : "0", // Check for decimal point
					'FLOAT' => str_contains($valueNode->getValue(), ".") ? "1" : "0", // Requires decimal point
					default => "0" // Unknown pattern
				};
			}
			
			// Handle boolean and null literals - never match numeric patterns
			if ($valueNode instanceof AstBool || $valueNode instanceof AstNull) {
				return "0";
			}
			
			// Handle identifiers - use type inference for optimization
			$inferredType = $this->typeInference->inferReturnType($valueNode);
			$string = $this->visitNodeAndReturnSQL($valueNode);
			
			// Match against pattern type and inferred type for optimization
			return match ([$patternKey, $inferredType]) {
				['NUMERIC', 'integer'], ['NUMERIC', 'float'] => "1", // Known numeric types
				['INTEGER', 'integer'] => "1", // Known integer type
				['INTEGER', 'float'] => "0", // Float is not integer
				['FLOAT', 'float'] => "1", // Known float type
				['FLOAT', 'integer'] => "0", // Integer is not float
				default => "{$string} REGEXP '" . self::REGEX_PATTERNS[$patternKey] . "'" // Runtime check
			};
		}
		
		/**
		 * Handle wildcard string patterns for SQL LIKE conversion
		 * Converts user-friendly wildcard patterns (* and ?) to SQL LIKE syntax.
		 * Only processes strings that actually contain wildcard characters.
		 * @param AstString $rightAst The string containing potential wildcards
		 * @param AstExpression|AstBinaryOperator|AstFactor|AstTerm $ast The full expression
		 * @param string $operator The comparison operator (= or <>)
		 * @return bool True if wildcard processing was performed
		 */
		private function handleWildcardString(AstString $rightAst, AstExpression|AstBinaryOperator|AstFactor|AstTerm $ast, string $operator): bool {
			$stringValue = $rightAst->getValue();
			
			// Check if string contains wildcard characters
			if (!str_contains($stringValue, "*") && !str_contains($stringValue, "?")) {
				return false; // No wildcards found, let standard processing handle it
			}
			
			// Process the left side of the expression
			$leftResult = $this->visitNodeAndReturnSQL($ast->getLeft());
			
			// Convert wildcards to SQL LIKE patterns
			$stringValue = str_replace(
				array_keys(self::WILDCARD_MAPPINGS),
				array_values(self::WILDCARD_MAPPINGS),
				$stringValue
			);
			
			// Choose LIKE or NOT LIKE based on original operator
			$likeOperator = $operator === "=" ? " LIKE " : " NOT LIKE ";
			
			// Store result for later retrieval
			$this->lastResult = "{$leftResult}{$likeOperator}\"" . addslashes($stringValue) . "\"";
			
			return true; // Indicate that wildcard processing was performed
		}
		
		/**
		 * Handle regular expression patterns for SQL REGEXP conversion
		 * Converts regex patterns to SQL REGEXP syntax for pattern matching.
		 * @param AstRegExp $rightAst The regex pattern AST node
		 * @param AstExpression|AstBinaryOperator|AstFactor|AstTerm $ast The full expression
		 * @param string $operator The comparison operator (= or <>)
		 * @return bool True to indicate regex processing was performed
		 */
		private function handleRegularExpression(AstRegExp $rightAst, AstExpression|AstBinaryOperator|AstFactor|AstTerm $ast, string $operator): bool {
			$stringValue = $rightAst->getValue();
			
			// Process the left side of the expression
			$leftResult = $this->visitNodeAndReturnSQL($ast->getLeft());
			
			// Choose REGEXP or NOT REGEXP based on original operator
			$regexpOperator = $operator === "=" ? " REGEXP " : " NOT REGEXP ";
			
			// Store result for later retrieval
			$this->lastResult = "{$leftResult}{$regexpOperator}\"{$stringValue}\"";
			
			return true; // Always return true as regex processing always occurs
		}
		
		/**
		 * Visit an AST node and return its SQL representation
		 * Delegates to the main visitor's method to process AST nodes.
		 * This maintains proper isolation and avoids circular dependencies.
		 * @param AstInterface $node The AST node to process
		 * @return string The SQL representation of the node
		 */
		private function visitNodeAndReturnSQL(AstInterface $node): string {
			// Use the main visitor's visitNodeAndReturnSQL method instead of creating new instances
			return $this->mainVisitor->visitNodeAndReturnSQL($node);
		}
		
		/**
		 * Retrieve the last stored result
		 * Used by wildcard and regex handling methods to return their processed results.
		 * @return string The last stored processing result
		 */
		private function getLastResult(): string {
			return $this->lastResult;
		}
	}