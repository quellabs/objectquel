<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstBool;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstFactor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNull;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRegExp;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstTerm;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstUnaryOperation;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;
	
	class ArithmeticExpression {
		
		protected Lexer $lexer;
		
		/**
		 * Expression constructor
		 * @param Lexer $lexer
		 */
		public function __construct(Lexer $lexer) {
			$this->lexer = $lexer;
		}
		
		/**
		 * Parse a factor in an arithmetic expression. A factor can either be a
		 * parenthesized expression, a constant, or a variable. Additionally, it
		 * can have multiplication (*) or division (/) operations.
		 * @return AstInterface The resulting AST node representing the parsed factor.
		 * @throws LexerException
		 * @throws ParserException
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
		 * Returns the lexer instance
		 * @return Lexer
		 */
		public function getLexer(): Lexer {
			return $this->lexer;
		}
		
		/**
		 * Parse a chain of properties with optional namespace in the first element
		 * @return AstIdentifier The root identifier with linked chain
		 */
		public function parsePropertyChain(): AstIdentifier {
			// Parse the first identifier in the chain, which may include namespace
			$token = $this->lexer->match(Token::Identifier);
			$tokenValue = $token->getValue();
			
			// Handle any namespace segments in the first identifier
			while ($this->lexer->optionalMatch(Token::Backslash)) {
				$namespaceToken = $this->lexer->match(Token::Identifier);
				$tokenValue .= "\\" . $namespaceToken->getValue();
			}
			
			// Create the root identifier with the full (potentially namespaced) value
			$rootIdentifier = new AstIdentifier($tokenValue);
			$currentIdentifier = $rootIdentifier;
			
			// Continue parsing the property chain with dot notation
			while ($this->lexer->optionalMatch(Token::Dot)) {
				// Parse the next property name
				$token = $this->lexer->match(Token::Identifier);
				$nextIdentifier = new AstIdentifier($token->getValue());
				
				// Link it to the current identifier
				$currentIdentifier->setNext($nextIdentifier);
				
				// And also link back
				$nextIdentifier->setParent($currentIdentifier);
				
				// Move to the new current identifier for potential next iteration
				$currentIdentifier = $nextIdentifier;
			}
			
			return $rootIdentifier;
		}
		
		/**
		 * Parses a constant
		 * @return AstInterface
		 * @throws LexerException
		 * @throws ParserException
		 */
		public function parsePrimaryExpression(): AstInterface {
			$token = $this->lexer->peek();
			$tokenType = $token->getType();
			$tokenValue = $token->getValue();
			$tokenExtraData = $token->getExtraData();
			
			switch ($tokenType) {
				case Token::Number :
					$this->lexer->match($tokenType);
					return new AstNumber($tokenValue);
				
				case Token::String :
					$this->lexer->match($tokenType);
					return new AstString($tokenValue, $tokenExtraData['char'] ?? '"');
				
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
					return new AstParameter($tokenValue);
				
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
					// Handle parenthesized expressions
					$this->lexer->match(Token::ParenthesesOpen);
					$logicalExpression = new LogicalExpression($this->lexer);
					$expression = $logicalExpression->parse();
					$this->lexer->match(Token::ParenthesesClose);
					return $expression;
					
				default :
					throw new ParserException("Unexpected token '{$tokenValue}' on line {$this->lexer->getLineNumber()}");
			}
		}
		
		/**
		 * Parse unary expressions (-, +, *, &, etc.)
		 * @return AstInterface
		 * @throws LexerException
		 * @throws ParserException
		 */
		protected function parseUnaryExpression(): AstInterface {
			$token = $this->lexer->peek();
			$tokenType = $token->getType();
			$tokenValue = $token->getValue();
			
			switch ($tokenType) {
				case Token::Plus:
				case Token::Minus:
					$this->lexer->match($tokenType);
					
					// Handle +/- followed by a number as a literal with sign
					if ($this->lexer->optionalMatch(Token::Number, $resultToken)) {
						$number = ($tokenValue == "-") ? 0 - $resultToken->getValue() : $resultToken->getValue();
						return new AstNumber($number);
					}
					
					// Otherwise, it's a unary operator applied to an expression
					$operand = $this->parseUnaryExpression();
					return new AstUnaryOperation($operand, $tokenValue);
					
				default:
					// If not a unary operator, parse a primary expression
					return $this->parsePrimaryExpression();
			}
		}
		
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
		
	}