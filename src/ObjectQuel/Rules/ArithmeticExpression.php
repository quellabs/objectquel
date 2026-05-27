<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstExpression;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRegExp;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstUnaryOperation;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCast;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;
	
	class ArithmeticExpression extends ExpressionRuleBase {
		
		/**
		 * Parse an expression, which can either be a simple term, a ternary
		 * conditional expression, or a relational expression.
		 * @return AstInterface The resulting AST node representing the parsed expression.
		 * @throws LexerException
		 * @throws ParserException
		 */
		public function parse(): AstInterface {
			return $this->parseTerm();
		}
		
		/**
		 * Parse a simple value (number, string, true, false)
		 * @return AstInterface
		 * @throws LexerException
		 * @throws ParserException
		 */
		public function parseSimpleValue(): AstInterface {
			$token = $this->lexer->peek();
			$tokenType = $token->getType();
			$tokenExtraData = $token->getExtraData();
			
			switch ($tokenType) {
				case Token::Number :
					$this->lexer->match($tokenType);
					return new AstNumber((string)$token->getNumericValue());
				
				case Token::String :
					$this->lexer->match($tokenType);
					
					if (isset($tokenExtraData['char']) && is_string($tokenExtraData['char'])) {
						$enclosingChar = $tokenExtraData['char'];
					} else {
						$enclosingChar = '"';
					}
					
					return new AstString($token->getStringValue(), $enclosingChar);
				
				case Token::False :
					$this->lexer->match($tokenType);
					return new AstBool(false);
				
				case Token::True :
					$this->lexer->match($tokenType);
					return new AstBool(true);
				
				default :
					$tokenTypeName = Token::toString($tokenType);
					throw new ParserException("Unexpected token '{$tokenTypeName}' on line {$this->lexer->getLineNumber()}");
			}
		}
		
		/**
		 * Parses a constant
		 * @return AstInterface
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		public function parsePrimaryExpression(): AstInterface {
			$token = $this->lexer->peek();
			$tokenType = $token->getType();
			$tokenExtraData = $token->getExtraData();
			
			switch ($tokenType) {
				case Token::Number :
					$this->lexer->match($tokenType);
					return new AstNumber((string)$token->getNumericValue());
				
				case Token::String :
					$this->lexer->match($tokenType);
					
					if (isset($tokenExtraData['char']) && is_string($tokenExtraData['char'])) {
						$enclosingChar = $tokenExtraData['char'];
					} else {
						$enclosingChar = '"';
					}
					
					return new AstString($token->getStringValue(), $enclosingChar);
					
				case Token::False :
					$this->lexer->match($tokenType);
					return new AstBool(false);
				
				case Token::True :
					$this->lexer->match($tokenType);
					return new AstBool(true);
				
				case Token::Null :
					$this->lexer->match($tokenType);
					return new AstNull();
				
				case Token::Parameter :
					$this->lexer->match($tokenType);
					return new AstParameter($token->getStringValue());
				
				case Token::Slash :
					// In a primary expression context, a slash is always the start of a regex
					// This is because division is handled at a higher level in parseFactor
					return $this->parseRegExp();
				
				case Token::Identifier :
					$node = $this->parsePropertyChain();
					
					// Kijk of het een commando is. Zo ja, parse dan het commando.
					if ($this->lexer->lookahead() === Token::ParenthesesOpen) {
						$queryFunctionRule = new QueryFunction($this);
						return $queryFunctionRule->parse($node->getCompleteName());
					}
					
					// Anders, retourneer de property keten
					return $node;
				
				case Token::ParenthesesOpen:
					// A parenthesised identifier immediately followed by a closing paren
					// is a C-style cast: (int)x.id, (float)x.price, etc.
					// The lexer holds a two-token lookahead: next_token (current, already
					// known to be '(' here) and the field $lookahead (one ahead of that).
					// peekNext() exposes the field lookahead, which is the token right
					// after '('. To see the token after that (expected ')') we must
					// speculatively consume one token via saveState/restoreState.
					if ($this->lexer->peekNext() === Token::Identifier) {
						$state = $this->lexer->saveState();
						$this->lexer->match(Token::ParenthesesOpen); // consume '('
						$this->lexer->match(Token::Identifier);      // consume type name
						$isCast = $this->lexer->lookahead() === Token::ParenthesesClose;
						$this->lexer->restoreState($state);

						if ($isCast) {
							return $this->parseCastExpression();
						}
					}

					// Handle parenthesized expressions
					$this->lexer->match(Token::ParenthesesOpen);
					$logicalExpression = new LogicalExpression($this->lexer);
					$expression = $logicalExpression->parse();
					$this->lexer->match(Token::ParenthesesClose);
					return $expression;
				
				default :
					$tokenTypeName = Token::toString($tokenType);
					throw new ParserException("Unexpected token '{$tokenTypeName}' on line {$this->lexer->getLineNumber()}");
			}
		}
		
		/**
		 * Parse a term in an arithmetic expression. A term can either be a single
		 * factor or an addition (+) or subtraction (-) operation between factors.
		 * @return AstInterface The resulting AST node representing the parsed term.
		 * @throws LexerException|ParserException
		 */
		protected function parseTerm(): AstInterface {
			// Parse the first factor in the term
			$factor = $this->parseFactor();
			
			// Check if the next token is either '+' or '-'
			switch($this->lexer->lookahead()) {
				case Token::Plus :
					$this->lexer->match($this->lexer->lookahead());
					return new AstTerm($factor, $this->parseTerm(), "+");
				
				case Token::Minus :
					$this->lexer->match($this->lexer->lookahead());
					return new AstTerm($factor, $this->parseTerm(), "-");
				
				default:
					return $factor;
			}
		}
		
		/**
		 * Parse a factor in an arithmetic expression. A factor can either be a
		 * parenthesized expression, a constant, or a variable. Additionally, it
		 * can have multiplication (*) or division (/) operations.
		 * @return AstInterface The resulting AST node representing the parsed factor.
		 * @throws LexerException
		 * @throws ParserException|\ReflectionException
		 */
		protected function parseFactor(): AstInterface {
			// Parse a constant or an identifier (like a variable)
			$unaryExpression = $this->parseUnaryExpression();
			
			// Check if the next token is either '*' or '/'
			switch($this->lexer->lookahead()) {
				case Token::Star :
					$this->lexer->match($this->lexer->lookahead());
					return new AstFactor($unaryExpression, $this->parseFactor(), "*");
				
				case Token::Slash :
					$this->lexer->match($this->lexer->lookahead());
					return new AstFactor($unaryExpression, $this->parseFactor(), "/");
				
				default :
					return $unaryExpression;
				
			}
		}
		
		/**
		 * Parse unary expressions (-, +, *, &, etc.)
		 * @return AstInterface
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseUnaryExpression(): AstInterface {
			$token = $this->lexer->peek();
			$tokenType = $token->getType();
			
			switch ($tokenType) {
				case Token::Plus:
				case Token::Minus:
					$this->lexer->match($tokenType);
					
					if (($resultToken = $this->lexer->optionalMatch(Token::Number)) === null) {
						$operand = $this->parseUnaryExpression();
						return new AstUnaryOperation($operand, $token->getStringValue());
					} elseif ($token->getStringValue() === "-") {
						return new AstNumber((string)(0 - $resultToken->getNumericValue()));
					} else {
						return new AstNumber($resultToken->getStringValue());
					}

				default:
					// If not a unary operator, parse a primary expression
					return $this->parsePrimaryExpression();
			}
		}
		
		/**
		 * Parses a regular expression
		 * @return AstRegExp
		 * @throws LexerException|ParserException
		 */
		protected function parseRegExp(): AstRegExp {
			// Match the slash to ensure proper lexer advancement
			$this->lexer->match(Token::Slash);
			
			// Get the current position in the source string
			$startPos = $this->lexer->getPos();
			
			// Now we need to read the regex pattern directly from the source string
			// First, we get the source string
			$source = $this->lexer->getSource();
			
			// We'll read from the current position until we find the closing slash
			$currentPos = $startPos; // Skip the opening slash
			$pattern = "";
			$flags = "";
			
			// Read until we find the closing slash
			while ($currentPos < strlen($source) && $source[$currentPos] !== '/') {
				// Handle escape sequences
				if ($source[$currentPos] === '\\') {
					if ($currentPos + 1 >= strlen($source)) {
						throw new ParserException("Unterminated regular expression escape");
					}
					
					$pattern .= $source[$currentPos] . $source[$currentPos + 1];
					$currentPos += 2;
					continue;
				}
				
				// Add the current character to the pattern
				$pattern .= $source[$currentPos];
				$currentPos++;
			}
			
			// Make sure we found the closing slash
			if ($currentPos >= strlen($source)) {
				throw new ParserException("Unterminated regular expression");
			}
			
			// Skip the closing slash
			++$currentPos;
			
			// Read any flags that follow
			while ($currentPos < strlen($source) && ctype_alpha($source[$currentPos])) {
				$flags .= $source[$currentPos];
				++$currentPos;
			}
			
			// Now we need to update the lexer's position to match where we've read to
			// We might need to add a method to the Lexer class for this
			$this->lexer->setPos($currentPos);
			
			// And reset the token stream
			$this->lexer->resetTokenStream();
			
			// Return the regular expression
			return new AstRegExp($pattern, $flags);
		}

		/**
		 * Parses a C-style cast expression: (type)expression
		 *
		 * Called when parsePrimaryExpression() detects the pattern
		 * ( Identifier ) which is unambiguously a cast because a valid
		 * parenthesised sub-expression always contains an operator, a keyword,
		 * or more than one token between the parens.
		 *
		 * The cast type keyword is validated against the platform supported cast
		 * types at semantic-analysis time, not here, so the parser accepts any
		 * identifier as the type name and leaves rejection to a later pass.
		 *
		 * @return AstCast
		 * @throws LexerException|ParserException
		 */
		protected function parseCastExpression(): AstCast {
			// Consume (
			$this->lexer->match(Token::ParenthesesOpen);

			// Consume the type keyword (e.g. int, float, string)
			$typeToken = $this->lexer->match(Token::Identifier);
			$castType = strtolower($typeToken->getStringValue());

			// Consume )
			$this->lexer->match(Token::ParenthesesClose);

			// Parse the operand: only a property chain is valid after a cast.
			// The semantic analyser enforces this; the parser accepts any primary
			// expression here so that error messages come from the validator.
			$operand = $this->parsePrimaryExpression();

			return new AstCast($castType, $operand);
		}
	}