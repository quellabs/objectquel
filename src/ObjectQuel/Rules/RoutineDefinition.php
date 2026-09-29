<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parses `define function name (type name, ...) returnType { ... }`.
	 * Type names are plain identifiers here; they are validated by value later.
	 */
	class RoutineDefinition {

		private Lexer $lexer;
		private EntityStore $entityStore;

		/**
		 * @param Lexer $lexer Lexer over the routine source
		 * @param EntityStore $entityStore Resolves `range of x is Entity` declarations in the body
		 */
		public function __construct(Lexer $lexer, EntityStore $entityStore) {
			$this->lexer = $lexer;
			$this->entityStore = $entityStore;
		}

		/**
		 * Parses the signature and body, starting at `define`.
		 * @return AstRoutineDefinition
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		public function parse(): AstRoutineDefinition {
			$this->lexer->matchKeyword('define');

			if (!$this->lexer->peekKeyword('function')) {
				throw new ParserException("Expected 'function' after 'define' on line {$this->lexer->getLineNumber()}");
			}

			$this->lexer->matchKeyword('function');

			$name = $this->lexer->match(Token::Identifier)->getStringValue();
			$parameters = $this->parseParameters();

			if ($this->lexer->lookahead() !== Token::Identifier) {
				throw new ParserException("Expected a return type after the parameter list of '{$name}' on line {$this->lexer->getLineNumber()}");
			}

			$returnType = $this->lexer->match(Token::Identifier)->getStringValue();
			$body = (new RoutineBlock($this->lexer, $this->entityStore))->parseBlock();

			return new AstRoutineDefinition($name, $parameters, $returnType, $body);
		}

		/**
		 * Parses the parenthesized `type name, ...` list, which may be empty.
		 * @return AstRoutineParameter[] Parameters in declaration order
		 * @throws LexerException
		 */
		private function parseParameters(): array {
			$this->lexer->match(Token::ParenthesesOpen);

			$parameters = [];

			if ($this->lexer->lookahead() !== Token::ParenthesesClose) {
				do {
					$type = $this->lexer->match(Token::Identifier)->getStringValue();
					$name = $this->lexer->match(Token::Identifier)->getStringValue();
					$parameters[] = new AstRoutineParameter($name, $type);
				} while ($this->lexer->optionalMatch(Token::Comma));
			}

			$this->lexer->match(Token::ParenthesesClose);

			return $parameters;
		}
	}
