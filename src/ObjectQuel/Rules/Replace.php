<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parser for `replace <range> (attr = value, ...) where ...` statements
	 * in the ObjectQuel language — QUEL's update verb (see
	 * objectquel-replace-plan.md).
	 *
	 * Unlike `append`, the target must already be a declared range (no bare
	 * entity name form) — the mandatory `where` clause needs a concrete
	 * range to resolve `range.property` identifiers against.
	 *
	 * parseAssignmentsAndConditions() is reused directly by Rules\Append for
	 * upsert's `append ... or replace (...) where ...` extension (see
	 * objectquel-upsert-plan.md), where the leading `replace <range>` target
	 * is omitted (implied to be the append's own target) — everything after
	 * that is identical grammar, so it isn't reimplemented there. Upsert's
	 * `or replace` additionally makes the assignment list itself optional
	 * (`$assignmentsOptional`), which a standalone `replace` never allows.
	 */
	class Replace {

		/**
		 * The lexer instance used for tokenizing and processing the input
		 */
		private Lexer $lexer;

		/**
		 * Replace parser constructor
		 * @param Lexer $lexer The lexer instance to use for tokenization
		 */
		public function __construct(Lexer $lexer) {
			$this->lexer = $lexer;
		}

		/**
		 * Parse a complete `replace` statement.
		 * @param AstRange[] $ranges Ranges already parsed ahead of this statement
		 * @return AstReplace
		 * @throws LexerException|ParserException
		 */
		public function parse(array $ranges): AstReplace {
			$this->lexer->match(Token::Replace);

			$targetName = $this->lexer->match(Token::Identifier)->getStringValue();
			$range = TargetRange::resolve($targetName, $ranges, 'replace');
			$replace = $this->parseAssignmentsAndConditions($range);

			$this->consumeOptionalSemicolon();

			return $replace;
		}

		/**
		 * Parses the parenthesized assignment list and mandatory WHERE clause,
		 * given an already-resolved target range — the part shared with
		 * upsert's `append ... or replace (...) where ...` on-conflict clause
		 * (see objectquel-upsert-plan.md), where the leading `replace <range>`
		 * target is omitted (implied to be the append's own target).
		 * @param AstRangeDatabase $range
		 * @param bool $assignmentsOptional When true and no parenthesized list
		 *        follows, the assignment list is left empty rather than being a
		 *        parse error — upsert's `or replace where <cond>` with no list
		 *        at all, meaning "on conflict, overwrite with the row that
		 *        would have been inserted" (see QuelToSQLUpsert). A standalone
		 *        `replace` always passes false: its list is never optional.
		 * @return AstReplace
		 * @throws LexerException|ParserException
		 */
		public function parseAssignmentsAndConditions(AstRangeDatabase $range, bool $assignmentsOptional = false): AstReplace {
			$assignments = $assignmentsOptional && $this->lexer->lookahead() !== Token::ParenthesesOpen
				? []
				: $this->parseAssignments();

			$whereClauseRule = new WhereClause($this->lexer);
			$conditions = $whereClauseRule->parseRequired('replace');

			return new AstReplace($range, $assignments, $conditions);
		}

		/**
		 * Parses the parenthesized, comma-separated assignment list.
		 * @return AstAssignment[]
		 * @throws LexerException|ParserException
		 */
		private function parseAssignments(): array {
			$this->lexer->match(Token::ParenthesesOpen);

			$expressionRule = new ArithmeticExpression($this->lexer);
			$assignments = [];
			$seenProperties = [];

			do {
				$property = $this->lexer->match(Token::Identifier)->getStringValue();

				if (isset($seenProperties[$property])) {
					throw new ParserException("Duplicate assignment to property '{$property}' in replace, on line {$this->lexer->getLineNumber()}");
				}

				$seenProperties[$property] = true;

				$this->lexer->match(Token::Equals);
				$assignments[] = new AstAssignment($property, $expressionRule->parse());
			} while ($this->lexer->optionalMatch(Token::Comma));

			$this->lexer->match(Token::ParenthesesClose);

			return $assignments;
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
