<?php
	
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;
	
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
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstUCount;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;
	
	class QueryFunction {
		
		private Lexer $lexer;
		private ArithmeticExpression $expressionRule;
		
		/**
		 * QueryFunction constructor
		 * @param ArithmeticExpression $expression
		 */
		public function __construct(ArithmeticExpression $expression) {
			$this->expressionRule = $expression;
			$this->lexer = $expression->getLexer();
		}
		
		/**
		 * Count operator
		 * @return AstCount
		 * @throws LexerException
		 */
		protected function parseCount(): AstCount {
			$this->lexer->match(Token::ParenthesesOpen);
			$countIdentifier = $this->expressionRule->parsePropertyChain();
			$this->lexer->match(Token::ParenthesesClose);
			return new AstCount($countIdentifier);
		}
		
		/**
		 * Count operator
		 * @return AstUCount
		 * @throws LexerException
		 */
		protected function parseUCount(): AstUCount {
			$this->lexer->match(Token::ParenthesesOpen);
			$countIdentifier = $this->expressionRule->parsePropertyChain();
			$this->lexer->match(Token::ParenthesesClose);
			return new AstUCount($countIdentifier);
		}
		
		/**
		 * Parses a CONCAT function in the query.
		 * @return AstConcat An AstConcat object containing parsed parameters.
		 * @throws LexerException|ParserException
		 */
		protected function parseConcat(): AstConcat {
			// Match the opening parenthesis.
			$this->lexer->match(Token::ParenthesesOpen);
			
			// Loop to parse each identifier inside the parentheses.
			$parameters = [];
			
			do {
				$parameters[] = $this->expressionRule->parse();
			} while ($this->lexer->optionalMatch(Token::Comma));
			
			// Match the closing parenthesis.
			$this->lexer->match(Token::ParenthesesClose);
			
			// Create and return a new AstConcat object with the parsed parameters.
			return new AstConcat($parameters);
		}
		
		/**
		 * Parse search operator
		 * @throws LexerException|ParserException
		 */
		protected function parseSearch(): AstSearch {
			// Match the opening parenthesis.
			$this->lexer->match(Token::ParenthesesOpen);
			
			// Parse the identifier list.
			$identifiers = $this->parseIdentifierList();
			
			if (empty($identifiers)) {
				throw new ParserException("Missing identifier list for SEARCH operator.");
			}
			
			// Parse the search string
			$searchString = $this->expressionRule->parse();
			
			if ((!$searchString instanceof AstString) && (!$searchString instanceof AstParameter)) {
				throw new ParserException("Missing search string for SEARCH operator.");
			}
			
			// Match the closing parenthesis.
			$this->lexer->match(Token::ParenthesesClose);
			
			// Return the AstSearch object
			return new AstSearch($identifiers, $searchString);
		}
		
		/**
		 * Parse 'is_empty'. This returns true if the value is falsey: null, empty string or 0
		 * @return AstIsEmpty
		 * @throws LexerException
		 * @throws ParserException
		 */
		protected function parseIsEmpty(): AstIsEmpty {
			// Match the opening parenthesis.
			$this->lexer->match(Token::ParenthesesOpen);
			
			// Fetch identifier or string to check
			$countIdentifier = $this->expressionRule->parse();
			
			// Closing parenthesis
			$this->lexer->match(Token::ParenthesesClose);
			
			// Return the AST
			return new AstIsEmpty($countIdentifier);
		}
		
		/**
		 * is_numeric function. Usage: where is_numeric(x.productsId)
		 * @return AstIsNumeric
		 * @throws LexerException
		 * @throws ParserException
		 */
		protected function parseIsNumeric(): AstIsNumeric {
			// Match the opening parenthesis.
			$this->lexer->match(Token::ParenthesesOpen);

			// Fetch identifier or string to check
			$countIdentifier = $this->expressionRule->parse();

			// Closing parenthesis
			$this->lexer->match(Token::ParenthesesClose);
			
			// Return the AST
			return new AstIsNumeric($countIdentifier);
		}
		
		/**
		 * is_integer function. Usage: where is_integer(x.productsId)
		 * @return AstIsInteger
		 * @throws LexerException
		 * @throws ParserException
		 */
		protected function parseIsInteger(): AstIsInteger {
			// Match the opening parenthesis.
			$this->lexer->match(Token::ParenthesesOpen);

			// Fetch identifier or string to check
			$countIdentifier = $this->expressionRule->parsePropertyChain();

			// Closing parenthesis
			$this->lexer->match(Token::ParenthesesClose);
			
			// Return the AST
			return new AstIsInteger($countIdentifier);
		}
		
		/**
		 * is_float function. Usage: where is_float(x.productsId)
		 * @return AstIsFloat
		 * @throws LexerException
		 * @throws ParserException
		 */
		protected function parseIsFloat(): AstIsFloat {
			// Match the opening parenthesis.
			$this->lexer->match(Token::ParenthesesOpen);

			// Fetch identifier or string to check
			$countIdentifier = $this->expressionRule->parse();

			// Closing parenthesis
			$this->lexer->match(Token::ParenthesesClose);
			
			// Return the AST
			return new AstIsFloat($countIdentifier);
		}
		
		/**
		 * Parse 'exists'. This will change a relation to INNER when present.
		 * @return AstExists
		 * @throws LexerException
		 * @throws ParserException
		 */
		protected function parseExists(): AstExists {
			// Match the opening parenthesis.
			$this->lexer->match(Token::ParenthesesOpen);
			
			// Fetch identifier or string to check
			$entity = $this->expressionRule->parsePropertyChain();
			
			// Closing parenthesis
			$this->lexer->match(Token::ParenthesesClose);
			
			if ($entity->hasNext()) {
				throw new ParserException("exists operator takes an entity as parameter.");
			}
			
			// Return the AST
			return new AstExists($entity);
		}
		
		/**
		 * Parse the identifier list
		 * @return AstIdentifier[]
		 * @throws LexerException
		 */
		private function parseIdentifierList(): array {
			$identifiers = [];
			
			do {
				if ($this->lexer->lookahead() !== Token::Identifier) {
					break;
				}
				
				$identifiers[] = $this->expressionRule->parse();
			} while ($this->lexer->optionalMatch(Token::Comma));
			
			return $identifiers;
		}
		
		/**
		 * @param string $command
		 * @return AstInterface
		 * @throws LexerException
		 * @throws ParserException
		 */
		public function parse(string $command): AstInterface {
			return match (strtolower($command)) {
				'count' => $this->parseCount(),
				'ucount' => $this->parseUCount(),
				'concat' => $this->parseConcat(),
				'search' => $this->parseSearch(),
				'is_empty' => $this->parseIsEmpty(),
				'is_numeric' => $this->parseIsNumeric(),
				'is_integer' => $this->parseIsInteger(),
				'is_float' => $this->parseIsFloat(),
				'exists' => $this->parseExists(),
				default => throw new ParserException("Command {$command} is not valid."),
			};
		}
	}