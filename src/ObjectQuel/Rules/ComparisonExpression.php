<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNot;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRegExp;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;
	
	class ComparisonExpression {
		
		/**
		 * Lexical Analysis class
		 * @var Lexer $lexer
		 */
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

			// "expr NOT IN (...)" — standard SQL postfix form. NOT here
			// only ever means this; if it isn't immediately followed by
			// IN, it doesn't belong to this expression at all, so leave
			// it unconsumed for whatever parses next.
			if ($this->lexer->lookahead() === Token::Not && $this->lexer->peekNext() === Token::In) {
				$this->lexer->match(Token::Not);
				return new AstNot($this->parseIn($expression));
			}

			// Check for ternary operator
			/** @noinspection PhpSwitchCanBeReplacedWithMatchExpressionInspection */
			switch($this->lexer->lookahead()) {
				case Token::Equals:
				case Token::Unequal:
				case Token::LargerThan:
				case Token::LargerThanOrEqualTo:
				case Token::SmallerThan:
				case Token::SmallerThanOrEqualTo:
					return $this->parseRelationalOperator($this->lexer->lookahead(), $expression);
					
				case Token::In:
					return $this->parseIn($expression);
					
				default :
					return $expression;
			}
		}
		
		/**
		 * Parse a relational operator
		 * @param int $lookahead
		 * @param AstInterface $term
		 * @return AstExpression
		 * @throws LexerException
		 * @throws ParserException
		 */
		protected function parseRelationalOperator(int $lookahead, AstInterface $term): AstExpression {
			// Consume the operator token and store its value
			$operatorToken = $this->lexer->match($lookahead);
			
			// Parse right side of expression
			$rightSide = $this->parse();
			
			// If the right side is a regular expression, only allow Token::Equals, Token::Unequal
			if (($rightSide instanceof AstRegExp) && !in_array($lookahead, [Token::Equals, Token::Unequal])) {
				throw new ParserException("Unsupported operator used with regular expression. Only '=' and '<>' operators are allowed for regular expression comparisons.");
			}
			
			// Create and return a new AstExpression node
			return new AstExpression($term, $rightSide, $operatorToken->getStringValue());
		}
		
		/**
		 * Parses "$expression IN (value, value, ...)". Only number and string
		 * literals are accepted in the value list. Recognized here, per
		 * term, rather than once for the entire WHERE clause — see this
		 * class's own parse() docblock for why that breaks composability
		 * with AND/OR.
		 * @param AstInterface $expression The left-hand side being tested for membership
		 * @return AstIn
		 * @throws LexerException
		 * @throws ParserException
		 */
		protected function parseIn(AstInterface $expression): AstIn {
			$this->lexer->match(Token::In);
			$this->lexer->match(Token::ParenthesesOpen);
			
			$parameterList = [];
			
			do {
				$arithmeticExpression = new ArithmeticExpression($this->lexer);
				$parameter = $arithmeticExpression->parse();
				
				if (!($parameter instanceof AstNumber) && !($parameter instanceof AstString)) {
					throw new ParserException("Invalid datatype detected in IN() statement. Only numbers and strings are allowed.");
				}
				
				$parameterList[] = $parameter;
			} while ($this->lexer->optionalMatch(Token::Comma));
			
			$this->lexer->match(Token::ParenthesesClose);
			return new AstIn($expression, $parameterList);
		}
		
	}