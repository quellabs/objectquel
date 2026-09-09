<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Grammar fragments shared by every place an index gets parsed —
	 * standalone `index [unique|fulltext] on Table is name (...)`
	 * (Rules\CreateIndex), `create`'s embedded `[unique|fulltext] index name
	 * (...)` entries (Rules\CreateTable), and `alter`'s `add [unique|fulltext]
	 * index name (...)` sub-operation (Rules\AlterTable) — rather than
	 * duplicating the same two token-consuming routines in all three (mirrors
	 * Rules\PrimaryKeyClause/Rules\ForeignKeyClause; see
	 * objectquel-index-clause-design.md, "Sugar, not reimplementation").
	 *
	 * The `unique`/`fulltext` modifier and the `(col {, col})` list sit in a
	 * different position relative to the `index` keyword across those three
	 * grammars (standalone `create index` matches them after `index`;
	 * `create`'s embedded entries and `alter`'s `add index` match them
	 * before), so there's no single shared `parse()` covering the whole
	 * clause — only these two position-independent pieces are common.
	 */
	class IndexClause {

		/**
		 * Consumes an optional `unique`/`fulltext` modifier at the lexer's
		 * current position — at most one of them can ever match, since both
		 * occupy the same grammar slot. Neither present means a plain index.
		 * @param Lexer $lexer
		 * @return array{unique: bool, type: ?string}
		 * @throws LexerException
		 */
		public static function parseModifiers(Lexer $lexer): array {
			if ($lexer->optionalMatch(Token::Unique) !== null) {
				return ['unique' => true, 'type' => null];
			}

			if ($lexer->optionalMatchKeyword('fulltext') !== null) {
				return ['unique' => false, 'type' => 'fulltext'];
			}

			return ['unique' => false, 'type' => null];
		}

		/**
		 * A parenthesized, comma-separated column name list, rejecting a
		 * column repeated within it.
		 * @param Lexer $lexer
		 * @return string[]
		 * @throws LexerException|ParserException
		 */
		public static function parseColumnList(Lexer $lexer): array {
			$lexer->match(Token::ParenthesesOpen);

			$columns = [];
			$seenColumns = [];

			do {
				$column = $lexer->match(Token::Identifier)->getStringValue();

				if (isset($seenColumns[$column])) {
					throw new ParserException("Duplicate column '{$column}' in index column list, on line {$lexer->getLineNumber()}");
				}

				$seenColumns[$column] = true;
				$columns[] = $column;
			} while ($lexer->optionalMatch(Token::Comma));

			$lexer->match(Token::ParenthesesClose);

			return $columns;
		}
	}
