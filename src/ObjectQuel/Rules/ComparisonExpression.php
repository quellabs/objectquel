<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRegExp;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;
	
	class ComparisonExpression {
		
		protected Lexer $lexer;
		
		/**
		 * Expression constructor
		 * @param Lexer $lexer
		 */
		public function __construct(Lexer $lexer) {
			$this->lexer = $lexer;
		}
		
		/**
		 * Returns the lexer instance
		 * @return Lexer
		 */
		public function getLexer(): Lexer {
			return $this->lexer;
		}
		
		/**
		 * Parse a relational operator
		 * @param $lookahead
		 * @param AstInterface $term
		 * @return AstExpression
		 * @throws LexerException
		 * @throws ParserException
		 */
		protected function parseRelationalOperator($lookahead, AstInterface $term): AstExpression {
			// Consume the operator token and store its value
			$operatorToken = $this->lexer->match($lookahead);
			
			// Parse right side of expression
			$rightSide = $this->parse();
			
			// If the right side is a regular expression, only allow Token::Equals, Token::Unequal
			if (($rightSide instanceof AstRegExp) && !in_array($lookahead, [Token::Equals, Token::Unequal])) {
				throw new ParserException("Unsupported operator used with regular expression. Only '=' and '<>' operators are allowed for regular expression comparisons.");
			}
			
			// Create and return a new AstExpression node
			return new AstExpression($term, $rightSide, $operatorToken->getValue());
		}
		
		/**
		 * Parse an expression, which can either be a simple term, a ternary
		 * conditional expression, or a relational expression.
		 * @return AstInterface The resulting AST node representing the parsed expression.
		 * @throws LexerException|ParserException
		 */
		public function parse(): AstInterface {
			// Load parser for arithmetic expressions
			$arithmeticExpression = new ArithmeticExpression($this->lexer);
			
			// Parse the first term in the expression
			$expression = $arithmeticExpression->parse();

			// Check for ternary operator
			switch($this->lexer->lookahead()) {
				case Token::Equals:
				case Token::Unequal:
				case Token::LargerThan:
				case Token::LargerThanOrEqualTo:
				case Token::SmallerThan:
				case Token::SmallerThanOrEqualTo:
					return $this->parseRelationalOperator($this->lexer->lookahead(), $expression);
					
				default :
					return $expression;
			}
		}
		
	}