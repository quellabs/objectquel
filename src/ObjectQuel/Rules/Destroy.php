<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroy;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyRoutine;
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
	 *   destroy function Name [if exists]            -> AstDestroyRoutine
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
		 * @return AstDestroy|AstDestroyIndex|AstDestroyRoutine
		 * @throws LexerException
		 */
		public function parse(): AstDestroy|AstDestroyIndex|AstDestroyRoutine {
			$this->lexer->matchKeyword('destroy');

			if ($this->matchRoutineKeyword()) {
				$routineName = $this->lexer->match(Token::Identifier)->getStringValue();
				$ifExists = $this->parseOptionalIfExists();
				$this->consumeOptionalSemicolon();
				return new AstDestroyRoutine($routineName, $ifExists);
			}

			$temporary = $this->lexer->optionalMatchKeyword('temporary') !== null;
			$name = $this->lexer->match(Token::Identifier)->getStringValue();

			if (!$temporary && $this->lexer->peekKeyword('on')) {
				$destroyIndexRule = new DestroyIndex($this->lexer);
				return $destroyIndexRule->parse($name);
			}

			$ifExists = $this->parseOptionalIfExists();

			$this->consumeOptionalSemicolon();

			return new AstDestroy($name, $temporary, $ifExists);
		}

		/**
		 * Consumes `function` when it starts the routine form; a table or index named `function` keeps its meaning.
		 * @return bool True when `function` was consumed
		 * @throws LexerException
		 */
		private function matchRoutineKeyword(): bool {
			if (!$this->lexer->peekKeyword('function') || $this->lexer->peekNext() !== Token::Identifier) {
				return false;
			}

			$state = $this->lexer->saveState();
			$this->lexer->matchKeyword('function');

			if ($this->lexer->peekKeyword('if') || $this->lexer->peekKeyword('on')) {
				$this->lexer->restoreState($state);
				return false;
			}

			return true;
		}

		/**
		 * Parse an optional trailing `if exists` qualifier.
		 * @return bool
		 * @throws LexerException
		 */
		private function parseOptionalIfExists(): bool {
			if (!$this->lexer->optionalMatchKeyword('if')) {
				return false;
			}

			$this->lexer->matchKeyword('exists');
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
