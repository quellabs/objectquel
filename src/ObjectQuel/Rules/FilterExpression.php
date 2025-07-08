<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNot;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCheckNotNull;
	
	class FilterExpression extends LogicalExpression {
		
		/**
		 * Parses the IN() expression
		 * @param AstInterface $expression
		 * @return AstIn
		 * @throws LexerException
		 * @throws ParserException
		 */
		protected function parseIn(AstInterface $expression): AstIn {
			$this->lexer->match(Token::In);
			$this->lexer->match(Token::ParenthesesOpen);
			
			$parameterList = [];
			
			do {
				$parameter = parent::parse();
				
				if (!($parameter instanceof AstNumber) && !($parameter instanceof AstString)) {
					throw new ParserException("Invalid datatype detected in IN() statement. Only numbers and strings are allowed.");
				}
				
				$parameterList[] = $parameter;
			} while ($this->lexer->optionalMatch(Token::Comma));
			
			$this->lexer->match(Token::ParenthesesClose);
			return new AstIn($expression, $parameterList);
		}
		
		/**
		 * @param AstInterface $expression
		 * @return AstInterface
		 * @throws LexerException|ParserException
		 */
		protected function parseFilterExpression(AstInterface $expression): AstInterface {
			switch($this->lexer->lookahead()) {
				case Token::In:
					return $this->parseIn($expression);
				
				default:
					throw new ParserException("Expected a logical operator");
			}
		}
		
		/**
		 * Analyzes 'is' expressions to determine if an expression is null or not-null.
		 * @param AstInterface $expression The expression to analyze.
		 * @return AstInterface An AstNotNull or AstNull object, depending on the presence of the 'not' token.
		 * @throws LexerException
		 */
		protected function parseIs(AstInterface $expression): AstInterface {
			// Check if there's a 'not' token, indicating a 'not-null' expression
			if ($this->lexer->optionalMatch(Token::Not)) {
				// Match the following 'null' token that is now required after 'not'
				$this->lexer->match(Token::Null);
				
				// Return an AstNotNull object, indicating the expression is not null
				return new AstCheckNotNull($expression);
			}
			
			// Match the 'null' token for a regular 'null' expression
			$this->lexer->match(Token::Null);
			
			// Return an AstNull object, indicating the expression is null
			return new AstCheckNull($expression);
		}
		
		/**
		 * Parse an expression, which can either be a simple term, a ternary
		 * conditional expression, a relational expression, or a filter expression.
		 * @return AstInterface The resulting AST node representing the parsed expression.
		 * @throws LexerException|ParserException
		 */
		public function parse(): AstInterface {
			// Handle NOT expressions specifically for FilterExpression operations
			if ($this->lexer->lookahead() == Token::Not) {
				$this->lexer->match(Token::Not);
				
				// If we have "NOT IN", we need to peek ahead to see if IN follows
				if ($this->lexer->lookahead() == Token::In) {
					// Get the left expression from a parent parse - this would be the value
					// we're checking in the NOT IN statement
					$arithmeticExpression = new ArithmeticExpression($this->lexer);
					$leftExpression = $arithmeticExpression->parse();
					
					// Now parse the IN statement directly and wrap it in NOT
					$inExpression = $this->parseIn($leftExpression);
					return new AstNot($inExpression);
				}
				
				// For other NOT expressions, fall back to the parent behavior
				return new AstNot(parent::parse());
			}
			
			// Parse the first term in the expression
			$expression = parent::parse();
			
			// After matching IS, look for 'null' or 'not null'
			if ($this->lexer->lookahead() == Token::Is) {
				$this->lexer->match(Token::Is);
				return $this->parseIs($expression);
			}
			
			// Try to parse a filter expression (like IN)
			try {
				// Check if this is a plain expression followed by IN
				if ($this->lexer->lookahead() == Token::In) {
					return $this->parseFilterExpression($expression);
				}
				
				// If not, return the expression
				return $expression;
			} catch (ParserException $e) {
				if ($e->getMessage() !== "Expected a logical operator") {
					throw $e;
				}
				
				return $expression;
			}
		}
	}