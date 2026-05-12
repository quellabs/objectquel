<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;
	
	/**
	 * AbstractExpressionRule is the base class for expression rule classes.
	 *
	 * It owns the Lexer instance and token-level parsing primitives that are
	 * shared across multiple levels of the expression hierarchy. Rule classes
	 * that do not use these primitives hold their own $lexer directly and do
	 * not extend this class.
	 */
	abstract class ExpressionRuleBase {
		
		protected Lexer $lexer;
		
		/**
		 * @param Lexer $lexer
		 */
		public function __construct(Lexer $lexer) {
			$this->lexer = $lexer;
		}
		
		/**
		 * Returns the lexer instance.
		 * @return Lexer
		 */
		public function getLexer(): Lexer {
			return $this->lexer;
		}
		
		/**
		 * Parse a chain of properties with optional namespace in the first element.
		 * Examples: p.name  /  App\Entity\Product  /  p.category.name
		 * @return AstIdentifier The root identifier with linked chain
		 * @throws LexerException
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
				$token = $this->lexer->match(Token::Identifier);
				$nextIdentifier = new AstIdentifier($token->getValue());
				$nextIdentifier->setParent($currentIdentifier);
				$currentIdentifier->setNext($nextIdentifier);
				$currentIdentifier = $nextIdentifier;
			}
			
			return $rootIdentifier;
		}
		
		/**
		 * Parse a comma-separated list of field identifiers, stopping before
		 * the final argument (typically a search string or parameter).
		 *
		 * The list ends when the next token after a comma is not an identifier.
		 * A trailing comma followed by ')' is a parse error.
		 *
		 * @return AstIdentifier[]
		 * @throws LexerException
		 * @throws ParserException
		 */
		protected function parseIdentifierList(): array {
			$identifiers = [];
			
			do {
				$next = $this->lexer->lookahead();
				
				if ($next !== Token::Identifier) {
					if (!empty($identifiers) && $next === Token::ParenthesesClose) {
						throw new ParserException("Unexpected token after comma in identifier list. Expected a field identifier.");
					}
					
					break;
				}
				
				$identifiers[] = $this->parsePropertyChain();
			} while ($this->lexer->optionalMatch(Token::Comma));
			
			return $identifiers;
		}
	}