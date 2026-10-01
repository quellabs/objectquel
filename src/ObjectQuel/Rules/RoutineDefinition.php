<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineParameter;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parses `define function name (type name, ...) returnType { ... }`.
	 * Type names are plain identifiers here; they are validated by value later.
	 * Compiler directives and ranges ahead of `define` are parsed by the
	 * caller (see Parser) and threaded through unmodified.
	 */
	class RoutineDefinition {

		private Lexer $lexer;

		/**
		 * @param Lexer $lexer Lexer over the routine source
		 */
		public function __construct(Lexer $lexer) {
			$this->lexer = $lexer;
		}

		/**
		 * Parses the signature and body, starting at `define`.
		 * @param array<string, mixed> $directives Compiler directives parsed ahead of `define`, e.g. @ignoreSoftDelete
		 * @param AstRange[] $ranges Ranges parsed ahead of `define`
		 * @return AstRoutineDefinition
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		public function parse(array $directives = [], array $ranges = []): AstRoutineDefinition {
			// Functions begin with 'define'
			$this->lexer->matchKeyword('define');
			$this->lexer->matchKeyword('function');

			$name = $this->lexer->match(Token::Identifier)->getStringValue();
			$parameters = $this->parseParameters();

			if ($this->lexer->lookahead() !== Token::Identifier) {
				throw new ParserException("Expected a return type after the parameter list of '{$name}' on line {$this->lexer->getLineNumber()}");
			}

			$returnType = $this->lexer->match(Token::Identifier)->getStringValue();

			// $ranges is handed to the block unmodified; RoutineBlock does not
			// parse ranges itself, it only resolves names against this set.
			$body = (new RoutineBlock($this->lexer, $ranges))->parseBlock();

			return new AstRoutineDefinition($directives, $ranges, $name, $parameters, $returnType, $body);
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
