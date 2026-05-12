<?php
	
	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;
	
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstSearch;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;
	
	/**
	 * PredicateExpression sits between LogicalExpression and ComparisonExpression
	 * in the precedence hierarchy.
	 *
	 * Its role is to intercept boolean-valued function calls — predicates that
	 * stand alone as conditions rather than producing a scalar value. These
	 * cannot appear as operands to comparison operators (=, <>, <, >, etc.)
	 * or as arguments to other functions, because they are parsed at a level
	 * above ComparisonExpression and ArithmeticExpression.
	 *
	 * Extends AbstractExpressionRule to gain $lexer, parsePropertyChain(), and
	 * parseIdentifierList(). Delegation downward to ComparisonExpression is done
	 * explicitly rather than via inheritance, since ComparisonExpression does not
	 * need those shared primitives and correctly stands alone.
	 *
	 * To add a new predicate function: add a match arm in parse() and implement
	 * the corresponding parseX() method here.
	 */
	class PredicateExpression extends ExpressionRuleBase {
		
		/**
		 * Parse a predicate expression.
		 *
		 * If the current token is an identifier immediately followed by '(',
		 * attempt to dispatch it as a known predicate function. If the name is
		 * not recognized, fall through to ComparisonExpression without consuming
		 * any tokens. Each predicate branch is responsible for consuming its
		 * own identifier token.
		 *
		 * @return AstInterface
		 * @throws LexerException
		 * @throws ParserException
		 */
		public function parse(): AstInterface {
			// Only attempt predicate dispatch when we see an identifier
			// immediately followed by '(' — anything else cannot be a predicate call
			if (
				$this->lexer->lookahead() === Token::Identifier &&
				$this->lexer->peekNext() === Token::ParenthesesOpen
			) {
				// Peek at the name without consuming — only consume once we know
				// it's a recognized predicate, so unknown names fall through cleanly
				$name = strtolower($this->lexer->peek()->getValue());
				
				// If the function matches something we know, parse it
				$node = match ($name) {
					'search' => $this->parseSearch(),
					default => null,
				};
				
				// If unknown, it could still be a function only not a predicate.
				// Other functions are defined in QueryFunction. Naturally go there.
				if ($node !== null) {
					return $node;
				}
			}
			
			// Not a predicate — delegate to ComparisonExpression
			$comparisonExpression = new ComparisonExpression($this->lexer);
			return $comparisonExpression->parse();
		}
		
		/**
		 * Parse a SEARCH() predicate — full-text search across multiple fields,
		 * falling back to LIKE when no full-text index is available.
		 *
		 * Syntax: search(field1, field2, ..., "term" | :parameter)
		 *
		 * The last argument must be a string literal or named parameter. All
		 * preceding arguments must be field identifiers. An empty identifier
		 * list or a non-string final argument is a parse error.
		 *
		 * @return AstSearch
		 * @throws LexerException
		 * @throws ParserException
		 */
		private function parseSearch(): AstSearch {
			// Consume the 'search' identifier and the opening parenthesis
			$this->lexer->match(Token::Identifier);
			$this->lexer->match(Token::ParenthesesOpen);
			
			// Parse the list of field identifiers to search across
			$identifiers = $this->parseIdentifierList();
			
			// Throw on missing identifier list
			if (empty($identifiers)) {
				throw new ParserException("Missing identifier list for SEARCH operator.");
			}
			
			// Parse the search term — must be a string literal or :parameter.
			// A bare identifier here means the user forgot to quote the search
			// term or forgot to prefix a parameter with ':'
			$searchString = (new ComparisonExpression($this->lexer))->parse();
			
			if (!$searchString instanceof AstString && !$searchString instanceof AstParameter) {
				throw new ParserException(
					"SEARCH() requires a string literal or :parameter as its last argument, got " . get_class($searchString) . ". " .
					"Did you mean to quote the search term or use a :parameter?"
				);
			}
			
			// Match parenthese close
			$this->lexer->match(Token::ParenthesesClose);
			
			// Return Ast
			return new AstSearch($identifiers, $searchString);
		}
	}