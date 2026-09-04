<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parser for `delete <range> where ...` statements in the ObjectQuel
	 * language — QUEL's delete verb (see objectquel-delete-plan.md).
	 *
	 * Not a variant spelling of `destroy` (table/index dropping — a
	 * completely separate keyword and statement, see
	 * objectquel-destroy-plan.md): `delete` always means this DML verb, no
	 * lookahead/dispatch needed in Parser::parse().
	 *
	 * Like `replace`, the target must already be a declared range (no bare
	 * entity name form) — the mandatory `where` clause needs a concrete
	 * range to resolve `range.property` identifiers against.
	 */
	class Delete {

		/**
		 * The lexer instance used for tokenizing and processing the input
		 */
		private Lexer $lexer;

		/**
		 * Delete parser constructor
		 * @param Lexer $lexer The lexer instance to use for tokenization
		 */
		public function __construct(Lexer $lexer) {
			$this->lexer = $lexer;
		}

		/**
		 * Parse a complete `delete` statement.
		 * @param AstRange[] $ranges Ranges already parsed ahead of this statement
		 * @return AstDelete
		 * @throws LexerException|ParserException
		 */
		public function parse(array $ranges): AstDelete {
			$this->lexer->match(Token::Delete);

			$targetName = $this->lexer->match(Token::Identifier)->getStringValue();
			$range = TargetRange::resolve($targetName, $ranges, 'delete');

			$whereClauseRule = new WhereClause($this->lexer);
			$conditions = $whereClauseRule->parseRequired('delete');

			$this->consumeOptionalSemicolon();

			return new AstDelete($range, $conditions);
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
