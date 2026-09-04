<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroy;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parser for `destroy Name {, Name}` statements in the ObjectQuel language.
	 *
	 * Authentic QUEL's `destroy` also drops indexes (an index is "also a
	 * table" per the QUEL reference, same statement, no separate grammar) —
	 * not implemented here yet; see objectquel-destroy-plan.md for why that's
	 * deferred rather than solved with an invented registry.
	 */
	class Destroy {

		/**
		 * The lexer instance used for tokenizing and processing the input
		 */
		private Lexer $lexer;

		/**
		 * Destroy parser constructor
		 * @param Lexer $lexer The lexer instance to use for tokenization
		 */
		public function __construct(Lexer $lexer) {
			$this->lexer = $lexer;
		}

		/**
		 * Parse a complete `destroy` statement.
		 * @return AstDestroy
		 * @throws LexerException|ParserException
		 */
		public function parse(): AstDestroy {
			$this->lexer->match(Token::Destroy);

			$names = [];
			$seenNames = [];

			do {
				$name = $this->lexer->match(Token::Identifier)->getStringValue();

				if (isset($seenNames[$name])) {
					throw new ParserException("Duplicate name '{$name}' in destroy statement");
				}

				$seenNames[$name] = true;
				$names[] = $name;
			} while ($this->lexer->optionalMatch(Token::Comma));

			$this->consumeOptionalSemicolon();

			return new AstDestroy($names);
		}

		/**
		 * Consume an optional trailing semicolon from the statement.
		 * @throws LexerException
		 */
		private function consumeOptionalSemicolon(): void {
			if ($this->lexer->lookahead() === Token::Semicolon) {
				$this->lexer->match(Token::Semicolon);
			}
		}
	}
