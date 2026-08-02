<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;
	
	class FilterExpression extends LogicalExpression {
		
		/**
		 * Parse an expression, which can either be a simple term, a ternary
		 * conditional expression, a relational expression, or a filter expression.
		 * @return AstInterface The resulting AST node representing the parsed expression.
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		public function parse(): AstInterface {
			// Parse the first term in the expression. NOT is handled per
			// term, in PredicateExpression::parse() — see that method's
			// own docblock — not here.
			$expression = parent::parse();
			
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
			/** @noinspection PhpSwitchCanBeReplacedWithMatchExpressionInspection */
			switch($this->lexer->lookahead()) {
				case Token::In:
					return $this->parseIn($expression);
				
				default:
					throw new ParserException("Expected a logical operator");
			}
		}
		
	}