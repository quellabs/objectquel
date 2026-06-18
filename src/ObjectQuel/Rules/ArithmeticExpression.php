<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRegExp;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstUnaryOperation;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCast;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;
	
	class ArithmeticExpression extends ExpressionRuleBase {
		
		// Tokens that unambiguously mark "(Identifier)" as a cast attempt,
		// since no other valid parse exists for e.g. (blob)p.id -- two
		// adjacent expressions with nothing connecting them. The type name
		// itself is never validated here; an unknown type like (blob)p.id
		// still reaches parseCastExpression() and is rejected later by
		// semantic analysis.
		private const array EXPRESSION_START_TOKENS = [
			Token::Number, Token::String, Token::False, Token::True, Token::Null,
			Token::Parameter, Token::Slash, Token::Identifier, Token::ParenthesesOpen,
		];
		
		// Known cast type keywords. Plus/Minus are genuinely ambiguous and
		// excluded from EXPRESSION_START_TOKENS above: (int)-x is a cast of a
		// negated operand, but (x) + 1 is a grouped expression in an addition
		// -- the same token shape, "(Identifier)" then +/-, means either one.
		// This list resolves that one junction by checking the identifier
		// against known types; it intentionally does NOT gate the unambiguous
		// case above, since (blob)p.id must still be recognised as a cast
		// attempt and rejected by semantic analysis, not silently misparsed.
		private const array CAST_TYPES = ['int', 'float', 'string', 'decimal', 'bool', 'datetime'];
		
		/**
		 * Parse an expression, which can either be a simple term, a ternary
		 * conditional expression, or a relational expression.
		 * @return AstInterface The resulting AST node representing the parsed expression.
		 * @throws LexerException
		 * @throws ParserException
		 * @throws \ReflectionException
		 */
		public function parse(): AstInterface {
			// Parse the first factor in the term
			$left = $this->parseFactor();
			
			// Fold left: right-recursion here would make +/- right-associative.
			while (true) {
				$lookahead = $this->lexer->lookahead();
				
				if ($lookahead !== Token::Plus && $lookahead !== Token::Minus) {
					return $left;
				}
				
				$this->lexer->match($lookahead);
				$right = $this->parseFactor();
				$left = new AstTerm($left, $right, $lookahead === Token::Plus ? "+" : "-");
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
					return new AstNumber(var_export($token->getNumericValue(), true));
				
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
					// (Identifier) is a cast only if an operand follows the closing
					// paren, e.g. (int)p.id; otherwise it's a grouped expression like
					// (x). A following Plus/Minus is ambiguous -- (int)-x is a cast,
					// but (x) + 1 is addition -- so that case falls back to checking
					// the identifier against CAST_TYPES. Checking any of this requires
					// peeking past ')', so we speculatively consume via saveState/restoreState.
					if ($this->lexer->peekNext() === Token::Identifier) {
						$state = $this->lexer->saveState();
						$this->lexer->match(Token::ParenthesesOpen); // consume '('
						$typeNameToken = $this->lexer->match(Token::Identifier); // consume type name
						$isCast = false;
						
						if ($this->lexer->lookahead() === Token::ParenthesesClose) {
							$this->lexer->match(Token::ParenthesesClose); // consume ')'
							$next = $this->lexer->lookahead();
							
							if ($next === Token::Plus || $next === Token::Minus) {
								$isCast = in_array(strtolower($typeNameToken->getStringValue()), self::CAST_TYPES, true);
							} else {
								$isCast = in_array($next, self::EXPRESSION_START_TOKENS, true);
							}
						}
						
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
		 * Parse a factor in an arithmetic expression. A factor can either be a
		 * parenthesized expression, a constant, or a variable. Additionally, it
		 * can have multiplication (*) or division (/) operations.
		 * @return AstInterface The resulting AST node representing the parsed factor.
		 * @throws LexerException
		 * @throws ParserException|\ReflectionException
		 */
		protected function parseFactor(): AstInterface {
			// Parse a constant or an identifier (like a variable)
			$left = $this->parseUnaryExpression();
			
			// Fold left: right-recursion here would make */÷ right-associative.
			while (true) {
				$lookahead = $this->lexer->lookahead();
				
				if ($lookahead !== Token::Star && $lookahead !== Token::Slash) {
					return $left;
				}
				
				$this->lexer->match($lookahead);
				$right = $this->parseUnaryExpression();
				$left = new AstFactor($left, $right, $lookahead === Token::Star ? "*" : "/");
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
			
			// Tracks [...] so an unescaped '/' inside a class isn't a terminator.
			$inCharClass = false;
			
			// Read until we find the closing slash
			while ($currentPos < strlen($source) && ($inCharClass || $source[$currentPos] !== '/')) {
				// Handle escape sequences
				if ($source[$currentPos] === '\\') {
					if ($currentPos + 1 >= strlen($source)) {
						throw new ParserException("Unterminated regular expression escape");
					}
					
					$pattern .= $source[$currentPos] . $source[$currentPos + 1];
					$currentPos += 2;
					continue;
				}
				
				// First '[' opens the class; first ']' after that closes it.
				if ($source[$currentPos] === '[' && !$inCharClass) {
					$inCharClass = true;
				} elseif ($source[$currentPos] === ']' && $inCharClass) {
					$inCharClass = false;
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
		 * Called when parsePrimaryExpression() detects ( Identifier ) followed
		 * by an operand, disambiguating it from a standalone parenthesized
		 * identifier expression like (x). The type name itself is not
		 * validated here except when the operand starts with +/-, where it's
		 * checked against CAST_TYPES to resolve that one ambiguous case;
		 * otherwise an unknown type (e.g. (blob)x) is still parsed as a cast
		 * and rejected later by semantic analysis.
		 *
		 * @return AstCast
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		protected function parseCastExpression(): AstCast {
			// Consume (
			$this->lexer->match(Token::ParenthesesOpen);
			
			// Consume the type keyword (e.g. int, float, string)
			$typeToken = $this->lexer->match(Token::Identifier);
			$castType = strtolower($typeToken->getStringValue());
			
			// Consume )
			$this->lexer->match(Token::ParenthesesClose);
			
			// Parse the cast operand. parseUnaryExpression (not parsePrimaryExpression)
			// so a leading sign, e.g. (int)-x, is consumed correctly.
			$operand = $this->parseUnaryExpression();
			
			return new AstCast($castType, $operand);
		}
	}