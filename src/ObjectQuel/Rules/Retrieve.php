<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRegExp;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;
	
	/**
	 * The Retrieve class is responsible for parsing 'retrieve' statements in the ObjectQuel language.
	 * It handles the parsing of retrieval operations including field selection, aliases, filtering,
	 * sorting, and pagination operations.
	 */
	class Retrieve {
		
		/**
		 * The lexer instance that provides tokens for parsing
		 */
		private Lexer $lexer;
		
		/**
		 * Rule for parsing arithmetic expressions in the retrieval statement
		 */
		private ArithmeticExpression $expressionRule;
		
		/**
		 * Rule for parsing filter expressions in the 'where' clause
		 */
		private FilterExpression $filterExpressionRule;
		
		/**
		 * Constructor for the Retrieve parser
		 * Initializes the lexer and creates instances of required expression rules
		 * @param Lexer $lexer The lexer that tokenizes the input
		 */
		public function __construct(Lexer $lexer) {
			$this->lexer = $lexer;
			$this->expressionRule = new ArithmeticExpression($this->lexer);
			$this->filterExpressionRule = new FilterExpression($this->lexer);
		}
		
		/**
		 * Parses the values (fields/expressions) to be retrieved within a retrieval statement.
		 * This method handles field expressions and their potential aliases.
		 * @param AstRetrieve $retrieve The AST retrieval node to store parsed values
		 * @return array An array of AstAlias objects representing parsed field expressions
		 * @throws LexerException If there's an error during lexical analysis
		 * @throws ParserException If there's a parsing error, such as duplicate aliases or invalid expressions
		 */
		protected function parseValues(AstRetrieve $retrieve): array {
			$values = [];
			
			do {
				// Save the starting position of the lexer to calculate source slice later
				$startPos = $this->lexer->getPos();
				
				// Check if the current field has an explicit alias (e.g., "alias = expression")
				// by looking ahead to see if the next token is an equals sign
				$aliasToken = $this->lexer->peekNext() == Token::Equals ? $this->lexer->match(Token::Identifier) : null;
				
				// If there's an alias token, consume the equals sign
				if ($aliasToken) {
					$this->lexer->match(Token::Equals);
				}
				
				// Parse the expression that represents the field or calculated value
				$expression = $this->expressionRule->parse();
				
				// Regular expressions are not allowed in the field list - enforce this constraint
				if ($expression instanceof AstRegExp) {
					throw new ParserException("Regular expressions are not allowed in the value list. Please remove the regular expression.");
				}
				
				// Get the original source code for this expression (useful when no explicit alias is provided)
				$sourceSlice = $this->lexer->getSourceSlice($startPos, $this->lexer->getPos() - $startPos);
				
				// Process the alias for this field expression
				if ($aliasToken === null || !$retrieve->macroExists($aliasToken->getValue())) {
					// If there's an alias, add it as a macro in the retrieve node
					if ($aliasToken !== null) {
						$retrieve->addMacro($aliasToken->getValue(), $expression);
					}
					
					// Determine the alias name - either explicit or derived from source
					$aliasName = $aliasToken ? $aliasToken->getValue() : $sourceSlice;
					$values[] = new AstAlias(trim($aliasName), $expression);
				} else {
					// Prevent duplicate alias names to avoid ambiguity
					throw new ParserException("Duplicate variable name detected: '{$aliasToken->getValue()}'. Please use unique names.");
				}
				
				// Continue parsing if there are more fields separated by commas
			} while ($this->lexer->optionalMatch(Token::Comma));
			
			return $values;
		}
		
		/**
		 * Parse a complete 'retrieve' statement in the ObjectQuel language.
		 * This method handles the entire retrieval operation including field selection,
		 * filtering (where clause), sorting, and pagination (window clause).
		 * @param array $directives Query directives that modify the retrieval behavior
		 * @param AstRangeDatabase[] $ranges Database ranges to retrieve from
		 * @return AstRetrieve The complete AST node representing the retrieve operation
		 * @throws LexerException If there's an error during lexical analysis
		 * @throws ParserException If there's a parsing error in any part of the statement
		 */
		public function parse(array $directives, array $ranges): AstRetrieve {
			// Match and consume the 'retrieve' keyword token
			$this->lexer->match(Token::Retrieve);
			
			// Create a new AST node for the 'retrieve' operation
			// The 'unique' flag is set if the 'unique' keyword is present
			$retrieve = new AstRetrieve($directives, $ranges, $this->lexer->optionalMatch(Token::Unique));
			
			// Match and consume the opening parenthesis before field list
			$this->lexer->match(Token::ParenthesesOpen);
			
			// Parse all field expressions inside the parentheses and add them to the AstRetrieve node
			foreach ($this->parseValues($retrieve) as $value) {
				$retrieve->addValue($value);
			}
			
			// Match and consume the closing parenthesis after field list
			$this->lexer->match(Token::ParenthesesClose);
			
			// Parse the optional 'where' clause if present
			// This clause filters the retrieved records based on specified conditions
			if ($this->lexer->optionalMatch(Token::Where)) {
				$retrieve->setConditions($this->filterExpressionRule->parse());
			}
			
			// Parse the optional 'sort by' clause if present
			// This clause specifies the ordering of retrieved records
			if ($this->lexer->optionalMatch(Token::Sort)) {
				$this->lexer->match(Token::By);
				
				$sortArray = [];
				
				do {
					// Parse each sort expression
					$sortResult = $this->expressionRule->parse();
					
					// Determine sort order (asc, desc, or default if not specified)
					if ($this->lexer->optionalMatch(Token::Asc)) {
						$order = 'asc';
					} elseif ($this->lexer->optionalMatch(Token::Desc)) {
						$order = 'desc';
					} else {
						$order = ''; // Default sort order when not explicitly specified
					}
					
					// Add the sort expression and order to the sort array
					$sortArray[] = ['ast' => $sortResult, 'order' => $order];
				} while ($this->lexer->optionalMatch(Token::Comma));
				
				// Set the sort specifications in the retrieve node
				$retrieve->setSort($sortArray);
			}
			
			// Parse the optional 'window' clause if present
			// This clause implements pagination functionality
			if ($this->lexer->optionalMatch(Token::Window)) {
				// Parse the window (page) number
				$window = $this->lexer->match(Token::Number);
				$this->lexer->match(Token::Using);
				$this->lexer->match(Token::WindowSize);
				
				// Parse the window size (records per page)
				$windowSize = $this->lexer->match(Token::Number);
				
				// Set window and window size in the retrieve node
				$retrieve->setWindow($window->getValue());
				$retrieve->setWindowSize($windowSize->getValue());
			}
			
			// Handle optional semicolon at the end of the statement
			if ($this->lexer->lookahead() == Token::Semicolon) {
				$this->lexer->match(Token::Semicolon);
			}
			
			// Return the complete retrieve node representing the parsed statement
			return $retrieve;
		}
	}