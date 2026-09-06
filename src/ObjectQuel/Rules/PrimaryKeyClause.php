<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parser for the `primary key (col {, col})` clause — shared grammar
	 * between `create`'s column list (Rules\CreateTable) and the future
	 * `alter` primary-key sub-operation (Rules\AlterTable), rather than
	 * duplicating the parse routine in both places (see
	 * objectquel-primary-key-design.md).
	 *
	 * Column order is preserved as the physical PK column order — the one
	 * place this feature cares about order, unlike QUEL's general
	 * attributes-are-an-unordered-set stance elsewhere in `create`.
	 *
	 * Only duplicate names *within* the clause are checked here.
	 * Cross-referencing the named columns against the table's declared
	 * columns (and the identity/PK co-occurrence rule) needs the full column
	 * list, so that validation is the caller's job.
	 */
	class PrimaryKeyClause {

		/**
		 * Parses a `primary key (col {, col})` clause, assuming `primary` is
		 * the current lookahead token.
		 * @param Lexer $lexer
		 * @return string[] Ordered column names
		 * @throws LexerException|ParserException
		 */
		public static function parse(Lexer $lexer): array {
			$lexer->match(Token::Primary);
			$lexer->match(Token::Key);
			$lexer->match(Token::ParenthesesOpen);

			$columns = [];
			$seenColumns = [];

			do {
				$column = $lexer->match(Token::Identifier)->getStringValue();

				if (isset($seenColumns[$column])) {
					throw new ParserException("Duplicate column '{$column}' in primary key clause, on line {$lexer->getLineNumber()}");
				}

				$seenColumns[$column] = true;
				$columns[] = $column;
			} while ($lexer->optionalMatch(Token::Comma));

			$lexer->match(Token::ParenthesesClose);

			return $columns;
		}
	}
