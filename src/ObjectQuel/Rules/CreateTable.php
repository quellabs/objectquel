<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstColumnDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateTable;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateTableIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parser for `create [temporary] Name (attr = type constraints, ...)
	 * [if not exists]` statements in the ObjectQuel language.
	 *
	 * Authentic QUEL has no `table` keyword in this statement at all (see
	 * objectquel-create-table-plan.md).
	 *
	 * The primary key is declared via a standalone table-level
	 * `primary key (col {, col})` clause inside the column list, not an
	 * inline per-column constraint — see objectquel-primary-key-design.md.
	 *
	 * The column list may also contain `[unique|fulltext] index name
	 * (col {, col})` entries (any number, in any position relative to
	 * columns/primary key) — sugar assembled into real AstCreateIndex
	 * statements by Execution\Executors\CreateTableExecutor, same
	 * name+column-list grammar as alter's `add index` sub-operation, minus
	 * the `add` keyword, which is redundant here — every entry in a
	 * `create` column list is additive by construction. See
	 * objectquel-index-clause-design.md.
	 */
	class CreateTable {

		/**
		 * The lexer instance used for tokenizing and processing the input
		 */
		private Lexer $lexer;

		/**
		 * CreateTable parser constructor
		 * @param Lexer $lexer The lexer instance to use for tokenization
		 */
		public function __construct(Lexer $lexer) {
			$this->lexer = $lexer;
		}

		/**
		 * Parse a complete `create` statement.
		 * @return AstCreateTable
		 * @throws LexerException|ParserException
		 */
		public function parse(): AstCreateTable {
			$this->lexer->matchKeyword('create');

			$temporary = $this->lexer->optionalMatchKeyword('temporary') !== null;
			$tableName = $this->lexer->match(Token::Identifier)->getStringValue();

			[
				'columns'           => $columns,
				'primaryKeyColumns' => $primaryKeyColumns,
				'indexes'           => $indexes,
			] = $this->parseColumnList($tableName);

			$ifNotExists = $this->parseOptionalIfNotExists();

			$this->consumeOptionalSemicolon();

			return new AstCreateTable($tableName, $columns, $temporary, $ifNotExists, $primaryKeyColumns, $indexes);
		}

		/**
		 * Parse an optional trailing `if not exists` qualifier.
		 * @return bool
		 * @throws LexerException
		 */
		private function parseOptionalIfNotExists(): bool {
			if (!$this->lexer->optionalMatchKeyword('if')) {
				return false;
			}

			$this->lexer->match(Token::Not);
			$this->lexer->matchKeyword('exists');
			return true;
		}

		/**
		 * Parse the parenthesized, comma-separated column definition list,
		 * which may contain at most one `primary key (...)` clause and any
		 * number of `[unique|fulltext] index name (...)` entries alongside
		 * the column definitions (in any position).
		 * @param string $tableName Used only to produce readable error messages
		 * @return array{columns: AstColumnDefinition[], primaryKeyColumns: string[], indexes: AstCreateTableIndex[]}
		 * @throws LexerException|ParserException
		 */
		private function parseColumnList(string $tableName): array {
			$this->lexer->match(Token::ParenthesesOpen);

			$columns = [];
			$indexes = [];
			$primaryKeyColumns = null;
			$seenNames = [];

			do {
				if ($this->lexer->peekKeyword('primary')) {
					if ($primaryKeyColumns !== null) {
						throw new ParserException("Table '{$tableName}' declares more than one primary key clause");
					}

					$primaryKeyColumns = PrimaryKeyClause::parse($this->lexer);
					continue;
				}

				if ($this->isIndexEntryStart()) {
					$indexes[] = $this->parseIndexEntry();
					continue;
				}

				$column = ColumnDefinitionClause::parse($this->lexer);

				if (isset($seenNames[$column->getName()])) {
					throw new ParserException("Duplicate column name '{$column->getName()}' in create '{$tableName}'");
				}

				$seenNames[$column->getName()] = true;
				$columns[] = $column;
			} while ($this->lexer->optionalMatch(Token::Comma));

			$this->lexer->match(Token::ParenthesesClose);

			$primaryKeyColumns ??= [];
			$this->validatePrimaryKeyClause($tableName, $columns, $seenNames, $primaryKeyColumns);
			$this->validateIndexEntries($tableName, $seenNames, $indexes);

			return ['columns' => $columns, 'primaryKeyColumns' => $primaryKeyColumns, 'indexes' => $indexes];
		}

		/**
		 * Whether the lexer is positioned at the start of a
		 * `[unique|fulltext] index name (...)` entry — same lookahead style
		 * as Rules\AlterTable::parseAdd().
		 */
		private function isIndexEntryStart(): bool {
			return $this->lexer->peekKeyword('index')
				|| $this->lexer->peek()->getType() === Token::Unique
				|| $this->lexer->peekKeyword('fulltext');
		}

		/**
		 * `[unique|fulltext] index index_name (col {, col})` — same
		 * name+column-list grammar as standalone `index ... on Table is
		 * name (...)` and alter's `add index`, minus the `on Table is`/`add`
		 * phrasing.
		 * @throws LexerException|ParserException
		 */
		private function parseIndexEntry(): AstCreateTableIndex {
			$unique = false;
			$type = null;

			if ($this->lexer->optionalMatch(Token::Unique) !== null) {
				$unique = true;
			} elseif ($this->lexer->optionalMatchKeyword('fulltext') !== null) {
				$type = 'fulltext';
			}

			$this->lexer->matchKeyword('index');
			$indexName = $this->lexer->match(Token::Identifier)->getStringValue();
			$columns = $this->parseIndexColumnNameList();

			return new AstCreateTableIndex($indexName, $columns, $unique, $type);
		}

		/**
		 * @return string[]
		 * @throws LexerException|ParserException
		 */
		private function parseIndexColumnNameList(): array {
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
		 * Cross-references embedded index entries against the table's
		 * declared columns — the same self-consistency check
		 * validatePrimaryKeyClause() already does for the primary key
		 * clause, extended to indexes since the full column set is known
		 * within this one statement (unlike alter's `add index`, which has
		 * no such declared-columns list to check against).
		 * @param string $tableName Used only to produce readable error messages
		 * @param array<string, bool> $seenNames Declared column names, keyed for lookup
		 * @param AstCreateTableIndex[] $indexes
		 * @throws ParserException
		 */
		private function validateIndexEntries(string $tableName, array $seenNames, array $indexes): void {
			$seenIndexNames = [];

			foreach ($indexes as $index) {
				if (isset($seenIndexNames[$index->getIndexName()])) {
					throw new ParserException("Table '{$tableName}' declares more than one index named '{$index->getIndexName()}'");
				}

				$seenIndexNames[$index->getIndexName()] = true;

				foreach ($index->getColumns() as $column) {
					if (!isset($seenNames[$column])) {
						throw new ParserException("Table '{$tableName}' declares index '{$index->getIndexName()}' on unknown column '{$column}'");
					}
				}
			}
		}

		/**
		 * Cross-references a parsed `primary key (...)` clause against the
		 * table's declared columns, and enforces the identity/PK
		 * co-occurrence rule: an identity column must be the sole entry in
		 * the clause (every supported dialect ties auto-increment/identity
		 * semantics to being the sole PK column).
		 * @param string $tableName Used only to produce readable error messages
		 * @param AstColumnDefinition[] $columns
		 * @param array<string, bool> $seenNames Declared column names, keyed for lookup
		 * @param string[] $primaryKeyColumns
		 * @throws ParserException
		 */
		private function validatePrimaryKeyClause(string $tableName, array $columns, array $seenNames, array $primaryKeyColumns): void {
			foreach ($primaryKeyColumns as $pkColumn) {
				if (!isset($seenNames[$pkColumn])) {
					throw new ParserException("Table '{$tableName}' declares primary key on unknown column '{$pkColumn}'");
				}
			}

			$identityColumns = array_values(array_filter($columns, fn(AstColumnDefinition $column) => $column->isIdentity()));

			if (count($identityColumns) > 1) {
				throw new ParserException("Table '{$tableName}' declares more than one identity column");
			}

			if ($identityColumns === []) {
				return;
			}

			$identityColumnName = $identityColumns[0]->getName();

			if ($primaryKeyColumns !== [$identityColumnName]) {
				throw new ParserException("Column '{$identityColumnName}' declares 'identity' but is not the sole column in the table's primary key clause");
			}
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
