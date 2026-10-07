<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroy;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyEventAttachment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyRoutine;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\TargetRangeResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parser for `destroy` statements in the ObjectQuel language. Every form
	 * shares the `destroy` keyword parsed here; what follows decides the
	 * shape, not a dedicated keyword in most cases (see
	 * objectquel-destroy-index-plan.md):
	 *
	 *   destroy [temporary] Name [if exists]              -> AstDestroy (table)
	 *   destroy Name on Table [if exists]                  -> AstDestroyIndex
	 *   destroy function Name [if exists]                  -> AstDestroyRoutine
	 *   destroy trigger Range Alias [if exists]             -> AstDestroyEventAttachment
	 *
	 * `temporary` only makes sense for the table form, so seeing it commits
	 * to that form immediately; otherwise the token right after the name
	 * (`on`, or not) decides. The index form's own trailing clause is
	 * parsed by Rules\DestroyIndex; the trigger form names the attachment
	 * directly by (range, alias) — see "Attachment identity and removal" in
	 * objectquel-equel-triggers-design.md — so it shares nothing with
	 * Rules\EventAttachment beyond the alias concept.
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
		 * @param AstRange[] $ranges Ranges already parsed ahead of this statement — only the event form needs them
		 * @return AstDestroy|AstDestroyIndex|AstDestroyRoutine|AstDestroyEventAttachment
		 * @throws LexerException|ParserException
		 */
		public function parse(array $ranges = []): AstDestroy|AstDestroyIndex|AstDestroyRoutine|AstDestroyEventAttachment {
			$this->lexer->matchKeyword('destroy');

			if ($this->matchTriggerKeyword()) {
				return $this->parseTriggerDestroy($ranges);
			}

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
		 * Consumes `trigger` only when it starts the attachment-removal form (`trigger`
		 * immediately followed by its range name, never by `if` or `on`); a table or index
		 * named `trigger` keeps its meaning, same disambiguation style as matchRoutineKeyword().
		 * @return bool True when `trigger` was consumed
		 * @throws LexerException
		 */
		private function matchTriggerKeyword(): bool {
			if (!$this->lexer->peekKeyword('trigger') || $this->lexer->peekNext() !== Token::Identifier) {
				return false;
			}

			$state = $this->lexer->saveState();
			$this->lexer->matchKeyword('trigger');

			if ($this->lexer->peekKeyword('if') || $this->lexer->peekKeyword('on')) {
				$this->lexer->restoreState($state);
				return false;
			}

			return true;
		}

		/**
		 * Parses the remainder of `destroy trigger Range Alias [if exists]`, once `destroy
		 * trigger` has already been consumed. Unlike the attach statement, this names the
		 * attachment directly by (range, alias) — no event, call or routine to parse.
		 * @param AstRange[] $ranges Ranges already parsed ahead of this statement
		 * @return AstDestroyEventAttachment
		 * @throws LexerException|ParserException
		 */
		private function parseTriggerDestroy(array $ranges): AstDestroyEventAttachment {
			$targetName = $this->lexer->match(Token::Identifier)->getStringValue();
			$range = TargetRangeResolver::resolve($targetName, $ranges, 'destroy trigger');
			$alias = $this->lexer->match(Token::Identifier)->getStringValue();
			$ifExists = $this->parseOptionalIfExists();
			$this->consumeOptionalSemicolon();

			return new AstDestroyEventAttachment($range, $alias, $ifExists);
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
