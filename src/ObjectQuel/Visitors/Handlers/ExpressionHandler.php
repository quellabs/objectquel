<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Visitors\Handlers;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNotNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstConcat;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIfNull;
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
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearchScore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\Capabilities\NullPlatformCapabilities;
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
			'INTEGER' => '^-?[0-9]+$',               // Only digits, no decimal point allowed
			'FLOAT'   => '^-?[0-9]+\\.[0-9]+$'       // Requires decimal point with digits on both sides
		];
		
		/** @var SqlBuilderHelper Helper for constructing SQL query components */
		private SqlBuilderHelper $sqlBuilder;
		
		/** @var TypeInferenceHelper Helper for determining data types from AST nodes */
		private TypeInferenceHelper $typeInference;
		
		/** @var array<string, mixed> Reference to the parameter array for prepared statements */
		private array $parameters;
		
		/** @var mixed Reference to the main visitor to avoid circular dependencies */
		private mixed $mainVisitor;

		/** @var PlatformCapabilitiesInterface Describes what the connected database engine supports */
		private PlatformCapabilitiesInterface $platform;
		
		/**
		 * Constructor - Initialize the expression handler with required dependencies
		 * @param SqlBuilderHelper $sqlBuilder Helper for SQL construction operations
		 * @param TypeInferenceHelper $typeInference Helper for type analysis
		 * @param array<string, mixed> $parameters Reference to parameters array for prepared statements
		 * @param mixed $mainVisitor Reference to the main AST visitor (avoids circular dependency)
		 * @param PlatformCapabilitiesInterface $platform Database engine capability descriptor
		 */
		public function __construct(
			SqlBuilderHelper       $sqlBuilder,
			TypeInferenceHelper    $typeInference,
			array                  &$parameters,
			mixed                  $mainVisitor,
			PlatformCapabilitiesInterface $platform = new NullPlatformCapabilities()
		) {
			$this->sqlBuilder = $sqlBuilder;
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
		 * @param AstTerm|AstBinaryOperator|AstExpression|AstFactor $ast The AST node representing the binary expression
		 * @param string $operator The SQL operator to use (=, <>, +, -, etc.)
		 * @return string The resulting SQL expression
		 */
		public function handleGenericExpression(AstTerm|AstBinaryOperator|AstExpression|AstFactor $ast, string $operator): string {
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
			
			if ($valueNode instanceof AstNull) {
				return '1';
			}
			
			if ($valueNode instanceof AstNumber) {
				// Cast to float to correctly handle values like 0.5 (would be truthy, not empty)
				return (float)$valueNode->getValue() == 0 ? '1' : '0';
			}
			
			if ($valueNode instanceof AstBool) {
				return !$valueNode->getValue() ? '1' : '0';
			}
			
			if ($valueNode instanceof AstString) {
				return $valueNode->getValue() === '' ? '1' : '0';
			}
			
			// Identifier: build dynamic check based on inferred type
			$inferredType = $this->typeInference->inferReturnType($valueNode);
			$string = $this->visitNodeAndReturnSQL($valueNode);
			
			if ($inferredType === 'integer' || $inferredType === 'float') {
				return "({$string} IS NULL OR {$string} = 0)";
			}
			
			return "({$string} IS NULL OR {$string} = '')";
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
			$searchKey = uniqid();
			$parsed = $search->parseSearchData($this->parameters);
			$conditions = $this->sqlBuilder->buildSearchConditions($search, $parsed, $searchKey);
			
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
		 */
		public function handleSearchScore(AstSearchScore $searchScore): string {
			return $this->sqlBuilder->buildSearchScoreExpression($searchScore);
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
		 * Escape a string value for safe inclusion in a SQL literal.
		 *
		 * NOTE: This centralises escaping so it can be swapped for a PDO/mysqli
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
					'FLOAT'   => str_contains($valueNode->getValue(), '.') ? '1' : '0',
					default   => '0',
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
				['INTEGER', 'integer']                       => '1',
				['INTEGER', 'float']                         => '0',
				['FLOAT',   'float']                         => '1',
				['FLOAT',   'integer']                       => '0',
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
		 * @param AstExpression|AstBinaryOperator|AstFactor|AstTerm $ast The full expression
		 * @param string $operator The comparison operator (= or <>)
		 * @return string|null The LIKE expression, or null if no wildcards found
		 */
		private function handleWildcardString(AstString $rightAst, AstExpression|AstBinaryOperator|AstFactor|AstTerm $ast, string $operator): ?string {
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
		 * @param AstExpression|AstBinaryOperator|AstFactor|AstTerm $ast The full expression
		 * @param string $operator The comparison operator (= or <>)
		 * @return string The REGEXP or REGEXP_LIKE expression
		 */
		private function handleRegularExpression(AstRegExp $rightAst, AstExpression|AstBinaryOperator|AstFactor|AstTerm $ast, string $operator): string {
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
		 * Visit an AST node and return its SQL representation.
		 * Delegates to the main visitor to maintain proper isolation.
		 * @param AstInterface $node The AST node to process
		 * @return string The SQL representation of the node
		 */
		private function visitNodeAndReturnSQL(AstInterface $node): string {
			return $this->mainVisitor->visitNodeAndReturnSQL($node);
		}
	}