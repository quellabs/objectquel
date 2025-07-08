<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBinaryOperator;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNot;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;
	
	class LogicalExpression	{
		
		protected Lexer $lexer;
		
		/**
		 * LogicalExpression constructor
		 * @param Lexer $lexer
		 */
		public function __construct(Lexer $lexer) {
			$this->lexer = $lexer;
		}
		
		/**
		 * Parse an AND expression. This function handles chains of AND operations.
		 * @return AstInterface The resulting AST node representing the parsed AND expression.
		 * @throws LexerException
		 * @throws ParserException
		 */
		protected function parseAndExpression(): AstInterface {
			// Load parser for comparisons
			$comparisonExpression = new ComparisonExpression($this->lexer);

			// Parse the left-hand side of the AND expression
			$left = $comparisonExpression->parse();
			
			// Keep parsing as long as we encounter 'AND' tokens
			while ($this->lexer->lookahead() == Token::And) {
				// Consume the 'AND' token
				$this->lexer->match($this->lexer->lookahead());
				
				// Parse the right-hand side of the AND expression and combine it
				// with the left-hand side to form a new AND expression
				$left = new AstBinaryOperator($left, $comparisonExpression->parse(), 'AND');
			}
			
			// Return the final AND expression
			return $left;
		}
		
		/**
		 * Parse a logical expression. This function handles OR operations,
		 * and delegates to `parseAndExpression` to handle AND expressions.
		 * @return AstInterface The resulting AST node representing the parsed logical expression.
		 * @throws LexerException|ParserException
		 */
		public function parse(): AstInterface {
			// Handle NOT expression
			if ($this->lexer->lookahead() == Token::Not) {
				$this->lexer->match(Token::Not);
				return new AstNot($this->parse());
			}
			
			// Parse the left-hand side of the OR expression; this could be an AND expression
			$left = $this->parseAndExpression();
			
			// Continue parsing as long as we encounter 'OR' tokens
			while ($this->lexer->lookahead() == Token::Or) {
				// Consume the 'OR' token
				$this->lexer->match($this->lexer->lookahead());
				
				// Parse the right-hand side of the OR expression and combine it
				// with the left-hand side to form a new OR expression
				$left = new AstBinaryOperator($left, $this->parseAndExpression(), 'OR');
			}
			
			// Return the final OR expression
			return $left;
		}
	}