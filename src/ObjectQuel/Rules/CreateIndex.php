<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parser for `index [unique|fulltext] on Table is index_name (column {,
	 * column})` statements in the ObjectQuel language — follows the actual
	 * Ingres QUEL grammar, not SQL's `CREATE INDEX name ON table (cols)`
	 * spelling (see objectquel-create-index-plan.md).
	 *
	 * $tableName is used as a literal table name, exactly like
	 * `create`/`destroy` — no Entity resolution, no restriction against
	 * targeting an Entity-mapped table.
	 *
	 * `unique` and `fulltext` occupy the same grammar slot right after
	 * `index` — at most one of them can ever be matched, so the invalid
	 * "unique fulltext index" combination (no target dialect has such a
	 * concept — see QuelToSQLCreateIndex) is unrepresentable by construction,
	 * not parsed-then-rejected.
	 */
	class CreateIndex {

		private Lexer $lexer;

		public function __construct(Lexer $lexer) {
			$this->lexer = $lexer;
		}

		public function parse(): AstCreateIndex {
			$this->lexer->match(Token::Index);

			$unique = false;
			$type = null;

			if ($this->lexer->optionalMatch(Token::Unique) !== null) {
				$unique = true;
			} elseif ($this->lexer->optionalMatch(Token::Fulltext) !== null) {
				$type = 'fulltext';
			}

			$this->lexer->match(Token::On);
			$tableName = $this->lexer->match(Token::Identifier)->getStringValue();

			$this->lexer->match(Token::Is);
			$indexName = $this->lexer->match(Token::Identifier)->getStringValue();

			$columns = $this->parseColumnList();

			$this->consumeOptionalSemicolon();

			return new AstCreateIndex($tableName, $indexName, $columns, $unique, $type);
		}

		/**
		 * @return string[]
		 */
		private function parseColumnList(): array {
			$this->lexer->match(Token::ParenthesesOpen);
			$columns = [];
			$seenColumns = [];
			do {
				$column = $this->lexer->match(Token::Identifier)->getStringValue();
				if (isset($seenColumns[$column])) {
					throw new ParserException("Duplicate column '{$column}' in index column list, on line {$this->lexer->getLineNumber()}");
				}
				$seenColumns[$column] = true;
				$columns[] = $column;
			} while ($this->lexer->optionalMatch(Token::Comma));
			$this->lexer->match(Token::ParenthesesClose);
			return $columns;
		}

		private function consumeOptionalSemicolon(): void {
			if ($this->lexer->lookahead() === Token::Semicolon) {
				$this->lexer->match(Token::Semicolon);
			}
		}
	}
