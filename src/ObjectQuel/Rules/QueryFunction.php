<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAny;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvg;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAvgU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIfnull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMax;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstMin;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstConcat;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExists;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsEmpty;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsFloat;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsInteger;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIsNumeric;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearch;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCountU;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSum;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSumU;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;
	
	/**
	 * QueryFunction class handles parsing of function calls in ObjectQuel queries.
	 */
	class QueryFunction {
		
		/** @var Lexer The lexer instance used for tokenizing input */
		private Lexer $lexer;
		
		/** @var ArithmeticExpression The expression rule parser for handling nested expressions */
		private ArithmeticExpression $expressionRule;
		
		/**
		 * QueryFunction constructor
		 * @param ArithmeticExpression $expression The expression parser instance
		 */
		public function __construct(ArithmeticExpression $expression) {
			$this->expressionRule = $expression;
			$this->lexer = $expression->getLexer();
		}
		
		/**
		 * Main parsing method - dispatches to appropriate function parser
		 *
		 * This method acts as a router, determining which specific function parser
		 * to call based on the function name. It uses PHP 8's match expression
		 * for clean, efficient dispatching.
		 *
		 * Supported functions:
		 * - Aggregate: count, countu, avg, avgu
		 * - String: concat, search
		 * - Type checking: is_empty, is_numeric, is_integer, is_float
		 * - Utility: exists
		 *
		 * @param string $command The function name to parse (case-insensitive)
		 * @return AstInterface The appropriate AST node for the parsed function
		 * @throws LexerException When token matching fails
		 * @throws ParserException When function name is not recognized or parsing fails
		 */
		public function parse(string $command): AstInterface {
			return match (strtolower($command)) {
				'count' => $this->parseCount(),
				'countu' => $this->parseCountU(),
				'avg' => $this->parseAvg(),
				'avgu' => $this->parseAvgU(),
				'max' => $this->parseMax(),
				'min' => $this->parseMin(),
				'sum' => $this->parseSum(),
				'sumu' => $this->parseSumU(),
				'any' => $this->parseAny(),
				'concat' => $this->parseConcat(),
				'search' => $this->parseSearch(),
				'is_empty' => $this->parseIsEmpty(),
				'is_numeric' => $this->parseIsNumeric(),
				'is_integer' => $this->parseIsInteger(),
				'is_float' => $this->parseIsFloat(),
				'ifnull' => $this->parseIfNull(),
				'exists' => $this->parseExists(),
				default => throw new ParserException("Command {$command} is not valid."),
			};
		}
		
		/**
		 * Generic parser for simple single-parameter functions
		 * @template T of AstInterface
		 * @param class-string<T> $astClass The fully qualified AST class name to instantiate
		 * @return T The instantiated AST node
		 * @throws LexerException When token matching fails
		 * @throws ParserException When parsing fails
		 */
		private function parseSingleParameter(string $astClass,): AstInterface {
			// Match opening parenthesis
			$this->lexer->match(Token::ParenthesesOpen);
			
			// Parse the parameter - either as property chain (entity.field) or general expression
			$parameter = $this->expressionRule->parse();
			
			// Match closing parenthesis
			$this->lexer->match(Token::ParenthesesClose);
			
			// Create and return the appropriate AST node
			return new $astClass($parameter);
		}
		
		/**
		 * Parse COUNT() function - counts rows/elements in a collection
		 * Returns the number of elements in the specified collection or entity.
		 * @return AstCount The COUNT AST node
		 * @throws LexerException When token matching fails
		 * @throws ParserException When parsing fails
		 */
		protected function parseCount(): AstCount {
			return $this->parseSingleParameter(AstCount::class);
		}
		
		/**
		 * Parse COUNTU() function - counts unique elements in a collection
		 * Returns the number of unique elements in the specified collection.
		 * @return AstCountU The COUNTU AST node
		 * @throws LexerException When token matching fails
		 * @throws ParserException When parsing fails
		 */
		protected function parseCountU(): AstCountU {
			return $this->parseSingleParameter(AstCountU::class);
		}
		
		/**
		 * Parse AVG() function - calculates average of numeric values
		 * Returns the arithmetic mean of all non-null numeric values in the collection.
		 * @return AstAvg The AVG AST node
		 * @throws LexerException When token matching fails
		 * @throws ParserException When parsing fails
		 */
		protected function parseAvg(): AstAvg {
			return $this->parseSingleParameter(AstAvg::class);
		}
		
		/**
		 * Parse AVGU() function - calculates average of unique numeric values
		 * Returns the arithmetic mean of unique non-null numeric values.
		 * @return AstAvgU The AVGU AST node
		 * @throws LexerException When token matching fails
		 * @throws ParserException When parsing fails
		 */
		protected function parseAvgU(): AstAvgU {
			return $this->parseSingleParameter(AstAvgU::class);
		}
		
		/**
		 * Parse MAX() function - finds the maximum value among numeric values
		 * Returns the largest non-null numeric value from the input.
		 * @return AstMax The AstMax AST node
		 * @throws LexerException When token matching fails
		 * @throws ParserException When parsing fails
		 */
		protected function parseMax(): AstMax {
			return $this->parseSingleParameter(AstMax::class);
		}
		
		/**
		 * Parse MIN() function - finds the minimum value among numeric values
		 * Returns the smallest non-null numeric value from the input.
		 * @return AstMin The AstMin AST node
		 * @throws LexerException When token matching fails
		 * @throws ParserException When parsing fails
		 */
		protected function parseMin(): AstMin {
			return $this->parseSingleParameter(AstMin::class);
		}
		
		/**
		 * Parse SUM() function - calculates the sum of numeric values
		 * Returns the total of all non-null numeric values from the input.
		 * @return AstSum The AstSum AST node
		 * @throws LexerException When token matching fails
		 * @throws ParserException When parsing fails
		 */
		protected function parseSum(): AstSum {
			return $this->parseSingleParameter(AstSum::class);
		}
		
		/**
		 * Parse SUMU() function - calculates the sum of unique numeric values
		 * Returns the total of all non-null numeric values from the input.
		 * @return AstSumU The AstSum AST node
		 * @throws LexerException When token matching fails
		 * @throws ParserException When parsing fails
		 */
		protected function parseSumU(): AstSumU {
			return $this->parseSingleParameter(AstSumU::class);
		}
		
		/**
		 * Parses the ANY aggregate function from the ObjectQuel query.
		 * ANY is a specialized aggregate function that returns 1 if any matching records exist,
		 * or 0 if no records exist. Unlike COUNT, ANY is optimized to stop execution as soon
		 * as the first matching record is found, making it more efficient for existence checks.
		 * @return AstAny The parsed ANY function AST node containing the field reference
		 * @throws LexerException When the lexer encounters invalid tokens during parsing
		 * @throws ParserException When the parser encounters invalid syntax or missing parameters
		 */
		protected function parseAny(): AstAny {
			return $this->parseSingleParameter(AstAny::class);
		}
		
		/**
		 * Parse is_empty() function - checks if value is falsy
		 * Returns true if the value is considered empty (null, empty string, or 0).
		 * This is useful for filtering out records with missing or empty data.
		 * @return AstIsEmpty The is_empty AST node
		 * @throws LexerException When token matching fails
		 * @throws ParserException When parsing fails
		 */
		protected function parseIsEmpty(): AstIsEmpty {
			return $this->parseSingleParameter(AstIsEmpty::class);
		}
		
		/**
		 * Parse is_numeric() function - checks if value is numeric
		 * Returns true if the value is numeric (integer, float, or numeric string).
		 * Useful for data validation and type checking in queries.
		 * @return AstIsNumeric The is_numeric AST node
		 * @throws LexerException When token matching fails
		 * @throws ParserException When parsing logic fails
		 */
		protected function parseIsNumeric(): AstIsNumeric {
			return $this->parseSingleParameter(AstIsNumeric::class);
		}
		
		/**
		 * Parse is_integer() function - checks if value is an integer
		 * Returns true if the value is specifically an integer type.
		 * More restrictive than is_numeric() as it excludes floats and numeric strings.
		 * @return AstIsInteger The is_integer AST node
		 * @throws LexerException When token matching fails
		 * @throws ParserException When parsing fails
		 */
		protected function parseIsInteger(): AstIsInteger {
			return $this->parseSingleParameter(AstIsInteger::class);
		}
		
		/**
		 * Parse is_float() function - checks if value is a floating-point number
		 * Returns true if the value is specifically a float/double type.
		 * Complements is_integer() for precise numeric type checking.
		 * @return AstIsFloat The is_float AST node
		 * @throws LexerException When token matching fails
		 * @throws ParserException When parsing fails
		 */
		protected function parseIsFloat(): AstIsFloat {
			return $this->parseSingleParameter(AstIsFloat::class);
		}
		
		/**
		 * Parse ifnull() function. This functions as a simple COALESCE in SQL
		 * @return AstIfnull
		 * @throws LexerException
		 * @throws ParserException
		 */
		protected function parseIfNull(): AstIfNull {
			$this->lexer->match(Token::ParenthesesOpen);
			
			$expression = $this->expressionRule->parse();
			
			$this->lexer->match(Token::Comma);
			
			$altValue = $this->expressionRule->parseSimpleValue();
			
			$this->lexer->match(Token::ParenthesesClose);

			return new AstIfNull($expression, $altValue);
		}
		
		/**
		 * Parse exists() function - checks entity existence and affects joins
		 * @return AstExists The exists AST node
		 * @throws LexerException When token matching fails
		 * @throws ParserException When entity has property chains or parsing fails
		 */
		protected function parseExists(): AstExists {
			// Parse entity reference
			$this->lexer->match(Token::ParenthesesOpen);
			$entity = $this->expressionRule->parsePropertyChain();
			$this->lexer->match(Token::ParenthesesClose);
			
			// Validate that entity is a simple reference without property chains
			if ($entity->hasNext()) {
				throw new ParserException("exists operator takes an entity as parameter.");
			}
			
			return new AstExists($entity);
		}
		
		/**
		 * Parse CONCAT() function - concatenates multiple expressions into a string
		 * Accepts variable number of parameters and concatenates them into a single string.
		 * Each parameter can be a string literal, field reference, or complex expression.
		 * @return AstConcat The CONCAT AST node containing all parameters
		 * @throws LexerException When token matching fails
		 * @throws ParserException When parsing fails
		 */
		protected function parseConcat(): AstConcat {
			$this->lexer->match(Token::ParenthesesOpen);
			
			// Parse variable number of parameters separated by commas
			$parameters = [];
			
			do {
				$parameters[] = $this->expressionRule->parse();
			} while ($this->lexer->optionalMatch(Token::Comma));
			
			$this->lexer->match(Token::ParenthesesClose);
			
			return new AstConcat($parameters);
		}
		
		/**
		 * Parse SEARCH() function - performs text search across multiple fields
		 * Searches for the given search string across the specified identifier fields.
		 * The last parameter must be a string literal or parameter containing the search term.
		 * @return AstSearch The SEARCH AST node
		 * @throws LexerException When token matching fails
		 * @throws ParserException When identifier list is empty or search string is invalid
		 */
		protected function parseSearch(): AstSearch {
			$this->lexer->match(Token::ParenthesesOpen);
			
			// Parse list of field identifiers to search in
			$identifiers = $this->parseIdentifierList();
			
			if (empty($identifiers)) {
				throw new ParserException("Missing identifier list for SEARCH operator.");
			}
			
			// Parse the search string/parameter
			$searchString = $this->expressionRule->parse();
			
			// Validate that search string is either a string literal or parameter
			if ((!$searchString instanceof AstString) && (!$searchString instanceof AstParameter)) {
				throw new ParserException("Missing search string for SEARCH operator.");
			}
			
			$this->lexer->match(Token::ParenthesesClose);
			
			return new AstSearch($identifiers, $searchString);
		}
		
		/**
		 * Helper method that parses a sequence of identifiers separated by commas.
		 * Stops parsing when encountering a non-identifier token.
		 * @return AstIdentifier[] Array of parsed identifier AST nodes
		 * @throws LexerException|ParserException When token matching fails
		 */
		private function parseIdentifierList(): array {
			$identifiers = [];
			
			do {
				if ($this->lexer->lookahead() !== Token::Identifier) {
					break;
				}
				
				/** @var AstIdentifier $identifier */
				$identifier = $this->expressionRule->parse();
				$identifiers[] = $identifier;
			} while ($this->lexer->optionalMatch(Token::Comma));
			
			return $identifiers;
		}
	}