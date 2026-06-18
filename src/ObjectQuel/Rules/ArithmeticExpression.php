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
		
		// Tokens that unambiguously mark "(Identifier)" as a cast attempt --
		// no other valid parse exists, e.g. for (blob)p.id. Unknown type names
		// are still accepted here and rejected later by semantic analysis.
		private const array EXPRESSION_START_TOKENS = [
			Token::Number, Token::String, Token::False, Token::True, Token::Null,
			Token::Parameter, Token::Slash, Token::Identifier, Token::ParenthesesOpen,
		];
		
		// Known cast types. Only consulted for the Plus/Minus ambiguity: both
		// (int)-x (cast) and (x) - 1 (subtraction) share the same token shape.
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
					
					// Check if it is a command. If so, parse the command.
					if ($this->lexer->lookahead() === Token::ParenthesesOpen) {
						$queryFunctionRule = new QueryFunction($this);
						return $queryFunctionRule->parse($node->getCompleteName());
					}
					
					// Otherwise, return the property chain.
					return $node;
				
				case Token::ParenthesesOpen:
					// Handle casts
					if ($this->looksLikeCast()) {
						return $this->parseCastExpression();
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
		 * Determines whether the upcoming "(Identifier)" is a cast, e.g.
		 * (int)p.id, rather than a parenthesized expression, e.g. (x) or
		 * (p.id + 1). Restores lexer position before returning either way.
		 *
		 * @return bool True if the upcoming tokens should be parsed as a cast.
		 */
		private function looksLikeCast(): bool {
			// Must start "( Identifier"; e.g. (p.id + 1) fails the ")" check below.
			if ($this->lexer->peekNext() !== Token::Identifier) {
				return false;
			}
			
			// Speculative lookahead -- restoreState() in finally undoes this.
			$state = $this->lexer->saveState();
			$this->lexer->match(Token::ParenthesesOpen);
			$typeNameToken = $this->lexer->match(Token::Identifier);
			
			try {
				// More than a single identifier inside the parens, e.g. (p.id)
				// or (p.id + 1) -- not a cast, a grouped expression instead.
				if ($this->lexer->lookahead() !== Token::ParenthesesClose) {
					return false;
				}
				
				$this->lexer->match(Token::ParenthesesClose);
				$tokenAfterParens = $this->lexer->lookahead();
				
				// A fresh expression-starting token, e.g. (blob)p.id, has no
				// grouped-expression reading -- it can only be a cast attempt.
				// Unknown type names are accepted here; semantic analysis rejects them.
				if (in_array($tokenAfterParens, self::EXPRESSION_START_TOKENS, true)) {
					return true;
				}
				
				// +/- is genuinely ambiguous: (int)-x is a cast, (x) - 1 is
				// subtraction. Only here does the identifier's text matter.
				if ($tokenAfterParens === Token::Plus || $tokenAfterParens === Token::Minus) {
					return in_array(strtolower($typeNameToken->getStringValue()), self::CAST_TYPES, true);
				}
				
				// Anything else means the parens were a complete value on their own.
				return false;
			} finally {
				$this->lexer->restoreState($state);
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
		 * Called after looksLikeCast() confirms the upcoming tokens are a cast.
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
			
			// Return the cast node
			return new AstCast($castType, $operand);
		}
	}