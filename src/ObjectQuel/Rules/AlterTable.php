<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterAddColumn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterAddIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterDropColumn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterDropIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterDropPrimaryKey;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterOperation;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterRenameColumn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterRetypeColumn;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterSetPrimaryKey;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterTable;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parser for `alter Name (op {, op})` statements in the ObjectQuel
	 * language — genuinely new territory, not borrowed from historical QUEL
	 * (which has no `ALTER TABLE` equivalent at all); see
	 * objectquel-alter-table-design.md.
	 *
	 * One table target per statement, matching `create`/`destroy`'s
	 * one-object invariant. Each parenthesized, comma-separated entry is a
	 * single-purpose sub-operation:
	 *
	 *   add attr = type constraints          -> AstAlterAddColumn
	 *   drop attr                            -> AstAlterDropColumn
	 *   rename oldAttr to newAttr            -> AstAlterRenameColumn
	 *   retype attr = type constraints       -> AstAlterRetypeColumn
	 *   primary key (col {, col})            -> AstAlterSetPrimaryKey
	 *   drop primary key                     -> AstAlterDropPrimaryKey
	 *   add [unique|fulltext] index name (…) -> AstAlterAddIndex
	 *   drop index name                      -> AstAlterDropIndex
	 *
	 * matching QUEL's preference for explicit single-purpose verbs
	 * (`append`/`replace`/`delete`) over SQL's do-everything `MODIFY`/
	 * `CHANGE COLUMN` clause, which conflates rename + retype + constraint
	 * change into one ambiguous operation.
	 *
	 * `add`/`drop`/`retype`/`primary key`/`add index`/`drop index` all
	 * begin with a keyword also used elsewhere in the grammar (`add` is
	 * unique to `alter`; `drop`/`index`/`primary`/`key` are contextual
	 * keywords the lexer only ever emits as plain identifiers — see
	 * Lexer::peekKeyword()) so each branch is chosen by lookahead text, the
	 * same dispatch style as Rules\Destroy.
	 */
	class AlterTable {

		private Lexer $lexer;

		public function __construct(Lexer $lexer) {
			$this->lexer = $lexer;
		}

		/**
		 * Parse a complete `alter` statement.
		 * @return AstAlterTable
		 * @throws LexerException|ParserException
		 */
		public function parse(): AstAlterTable {
			$this->lexer->matchKeyword('alter');

			$tableName = $this->lexer->match(Token::Identifier)->getStringValue();
			$operations = $this->parseOperationList($tableName);

			$this->consumeOptionalSemicolon();

			return new AstAlterTable($tableName, $operations);
		}

		/**
		 * Parse the parenthesized, comma-separated sub-operation list.
		 * @param string $tableName Used only to produce readable error messages
		 * @return AstAlterOperation[]
		 * @throws LexerException|ParserException
		 */
		private function parseOperationList(string $tableName): array {
			$this->lexer->match(Token::ParenthesesOpen);

			$operations = [];

			do {
				$operations[] = $this->parseOperation($tableName);
			} while ($this->lexer->optionalMatch(Token::Comma));

			$this->lexer->match(Token::ParenthesesClose);

			return $operations;
		}

		/**
		 * @param string $tableName Used only to produce readable error messages
		 * @throws LexerException|ParserException
		 */
		private function parseOperation(string $tableName): AstAlterOperation {
			if ($this->lexer->optionalMatchKeyword('add')) {
				return $this->parseAdd();
			}

			if ($this->lexer->optionalMatchKeyword('drop')) {
				return $this->parseDrop();
			}

			if ($this->lexer->optionalMatchKeyword('rename')) {
				return $this->parseRename();
			}

			if ($this->lexer->optionalMatchKeyword('retype')) {
				return new AstAlterRetypeColumn(ColumnDefinitionClause::parse($this->lexer));
			}

			if ($this->lexer->peekKeyword('primary')) {
				return new AstAlterSetPrimaryKey(PrimaryKeyClause::parse($this->lexer));
			}

			throw new ParserException("Unexpected token in alter statement for '{$tableName}', on line {$this->lexer->getLineNumber()}");
		}

		/**
		 * `add` is followed by either a plain column definition or an index
		 * clause (`[unique|fulltext] index name (...)`).
		 * @throws LexerException|ParserException
		 */
		private function parseAdd(): AstAlterOperation {
			if ($this->lexer->peekKeyword('index') || $this->lexer->peek()->getType() === Token::Unique || $this->lexer->peekKeyword('fulltext')) {
				return $this->parseAddIndex();
			}

			return new AstAlterAddColumn(ColumnDefinitionClause::parse($this->lexer));
		}

		/**
		 * `add [unique|fulltext] index index_name (col {, col})` — same
		 * name+column-list grammar as standalone `index ... on Table is
		 * name (...)`, minus the `on Table is` phrasing (redundant once
		 * already scoped to one table by the enclosing `alter` statement).
		 * @throws LexerException|ParserException
		 */
		private function parseAddIndex(): AstAlterAddIndex {
			$unique = false;
			$type = null;

			if ($this->lexer->optionalMatch(Token::Unique) !== null) {
				$unique = true;
			} elseif ($this->lexer->optionalMatchKeyword('fulltext') !== null) {
				$type = 'fulltext';
			}

			$this->lexer->matchKeyword('index');
			$indexName = $this->lexer->match(Token::Identifier)->getStringValue();
			$columns = $this->parseColumnNameList();

			return new AstAlterAddIndex($indexName, $columns, $unique, $type);
		}

		/**
		 * `drop` is followed by either a bare column name, `primary key`, or
		 * `index name`.
		 * @throws LexerException|ParserException
		 */
		private function parseDrop(): AstAlterOperation {
			if ($this->lexer->peekKeyword('primary')) {
				$this->lexer->matchKeyword('primary');
				$this->lexer->matchKeyword('key');
				return new AstAlterDropPrimaryKey();
			}

			if ($this->lexer->optionalMatchKeyword('index') !== null) {
				$indexName = $this->lexer->match(Token::Identifier)->getStringValue();
				return new AstAlterDropIndex($indexName);
			}

			$columnName = $this->lexer->match(Token::Identifier)->getStringValue();
			return new AstAlterDropColumn($columnName);
		}

		/**
		 * `rename oldAttr to newAttr` — explicit, one direction, never
		 * inferred (see objectquel-alter-table-design.md).
		 * @throws LexerException|ParserException
		 */
		private function parseRename(): AstAlterRenameColumn {
			$oldName = $this->lexer->match(Token::Identifier)->getStringValue();
			$this->lexer->matchKeyword('to');
			$newName = $this->lexer->match(Token::Identifier)->getStringValue();

			return new AstAlterRenameColumn($oldName, $newName);
		}

		/**
		 * @return string[]
		 * @throws LexerException|ParserException
		 */
		private function parseColumnNameList(): array {
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

		/**
		 * @throws LexerException
		 */
		private function consumeOptionalSemicolon(): void {
			if ($this->lexer->lookahead() === Token::Semicolon) {
				$this->lexer->match(Token::Semicolon);
			}
		}
	}
