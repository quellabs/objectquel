<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstEventBinding;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\BindingEvent;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\TargetRangeResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parser for `after (append to|replace|delete) <range> call <routine> [as <alias>]` — see
	 * objectquel-equel-triggers-design.md. The routine is named only; its own entity-row
	 * parameters receive the event's rows by declaration order (BindingEvent::rowRoles()),
	 * so there is no argument list to parse here. `destroy trigger <range> <alias> [if exists]`
	 * is the symmetric removal form, parsed entirely by Rules\Destroy — it names the binding
	 * directly by alias, so it has no need for this class's event parsing.
	 */
	class EventBinding {

		private Lexer $lexer;

		/**
		 * @param Lexer $lexer The lexer instance to use for tokenization
		 */
		public function __construct(Lexer $lexer) {
			$this->lexer = $lexer;
		}

		/**
		 * Parses a complete `after ... call ... [as <alias>]` statement.
		 * @param AstRange[] $ranges Ranges already parsed ahead of this statement
		 * @return AstEventBinding
		 * @throws LexerException|ParserException
		 */
		public function parse(array $ranges): AstEventBinding {
			$this->lexer->matchKeyword('after');

			$event = $this->parseEvent();
			$range = $this->parseTargetRange($ranges);

			$this->lexer->matchKeyword('call');
			$routineName = $this->lexer->match(Token::Identifier)->getStringValue();
			$alias = $this->parseOptionalAlias();

			$this->consumeOptionalSemicolon();

			return new AstEventBinding($event, $range, $routineName, $alias);
		}

		/**
		 * Parses an optional trailing `as <alias>` naming the binding, for later reference in
		 * `destroy trigger <range> <alias>`. Omitted, the binding gets a generated alias at
		 * compile time (see EventBindingNaming::randomAlias()).
		 * @return string|null
		 * @throws LexerException
		 */
		private function parseOptionalAlias(): ?string {
			if (!$this->lexer->optionalMatchKeyword('as')) {
				return null;
			}

			return $this->lexer->match(Token::Identifier)->getStringValue();
		}

		/**
		 * Parses the event keyword: `append to`, `replace` or `delete`.
		 * @return BindingEvent
		 * @throws LexerException|ParserException
		 */
		public function parseEvent(): BindingEvent {
			if ($this->lexer->optionalMatchKeyword('append')) {
				$this->lexer->matchKeyword('to');
				return BindingEvent::Append;
			}

			if ($this->lexer->optionalMatchKeyword('replace')) {
				return BindingEvent::Replace;
			}

			if ($this->lexer->optionalMatchKeyword('delete')) {
				return BindingEvent::Delete;
			}

			throw new ParserException("Expected 'append to', 'replace' or 'delete' after 'after' on line {$this->lexer->getLineNumber()}");
		}

		/**
		 * Parses and resolves the target range name against the declared ranges.
		 * @param AstRange[] $ranges Ranges already parsed ahead of this statement
		 * @return AstRangeDatabase
		 * @throws LexerException|ParserException
		 */
		public function parseTargetRange(array $ranges): AstRangeDatabase {
			$targetName = $this->lexer->match(Token::Identifier)->getStringValue();
			return TargetRangeResolver::resolve($targetName, $ranges, 'after');
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
