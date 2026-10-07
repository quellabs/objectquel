<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstEventAttachment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AttachmentEvent;
	use Quellabs\ObjectQuel\ObjectQuel\Helpers\TargetRangeResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parser for `after (append to|replace|delete) <range> call <routine>(args)` — see
	 * objectquel-equel-triggers-design.md. The symmetric `destroy event ...` removal form
	 * shares this class's parseEvent() and is otherwise parsed by Rules\Destroy.
	 */
	class EventAttachment {

		private Lexer $lexer;

		/**
		 * @param Lexer $lexer The lexer instance to use for tokenization
		 */
		public function __construct(Lexer $lexer) {
			$this->lexer = $lexer;
		}

		/**
		 * Parses a complete `after ... call ...(...)` statement.
		 * @param AstRange[] $ranges Ranges already parsed ahead of this statement
		 * @return AstEventAttachment
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		public function parse(array $ranges): AstEventAttachment {
			$this->lexer->matchKeyword('after');

			$event = $this->parseEvent();
			$range = $this->parseTargetRange($ranges);

			$this->lexer->matchKeyword('call');
			$routineName = $this->lexer->match(Token::Identifier)->getStringValue();
			$call = (new QueryFunction(new ArithmeticExpression($this->lexer)))->parseRoutineCall($routineName);

			$this->consumeOptionalSemicolon();

			return new AstEventAttachment($event, $range, $call);
		}

		/**
		 * Parses the event keyword: `append to`, `replace` or `delete`.
		 * @return AttachmentEvent
		 * @throws LexerException|ParserException
		 */
		public function parseEvent(): AttachmentEvent {
			if ($this->lexer->optionalMatchKeyword('append')) {
				$this->lexer->matchKeyword('to');
				return AttachmentEvent::Append;
			}

			if ($this->lexer->optionalMatchKeyword('replace')) {
				return AttachmentEvent::Replace;
			}

			if ($this->lexer->optionalMatchKeyword('delete')) {
				return AttachmentEvent::Delete;
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
