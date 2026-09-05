<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroy;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parser for `destroy` statements in the ObjectQuel language. Both forms
	 * share the `destroy Name` prefix parsed here; what follows decides the
	 * shape, not a keyword (see objectquel-destroy-index-plan.md):
	 *
	 *   destroy [temporary] Name [if exists]        -> AstDestroy (table)
	 *   destroy Name on Table [if exists]            -> AstDestroyIndex
	 *
	 * `temporary` only makes sense for the table form, so seeing it commits
	 * to that form immediately; otherwise the token right after the name
	 * (`on`, or not) decides. The index form's own trailing clause is
	 * parsed by Rules\DestroyIndex.
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
		 * @return AstDestroy|AstDestroyIndex
		 * @throws LexerException
		 */
		public function parse(): AstDestroy|AstDestroyIndex {
			$this->lexer->match(Token::Destroy);

			$temporary = $this->lexer->optionalMatch(Token::Temporary) !== null;
			$name = $this->lexer->match(Token::Identifier)->getStringValue();

			if (!$temporary && $this->lexer->lookahead() === Token::On) {
				$destroyIndexRule = new DestroyIndex($this->lexer);
				return $destroyIndexRule->parse($name);
			}

			$ifExists = $this->parseOptionalIfExists();

			$this->consumeOptionalSemicolon();

			return new AstDestroy($name, $temporary, $ifExists);
		}

		/**
		 * Parse an optional trailing `if exists` qualifier.
		 * @return bool
		 * @throws LexerException
		 */
		private function parseOptionalIfExists(): bool {
			if (!$this->lexer->optionalMatch(Token::If)) {
				return false;
			}

			$this->lexer->match(Token::Exists);
			return true;
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
