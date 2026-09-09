<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstColumnDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateTable;
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

			['columns' => $columns, 'primaryKeyColumns' => $primaryKeyColumns] = $this->parseColumnList($tableName);
			$ifNotExists = $this->parseOptionalIfNotExists();

			$this->consumeOptionalSemicolon();

			return new AstCreateTable($tableName, $columns, $temporary, $ifNotExists, $primaryKeyColumns);
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
		 * which may contain at most one `primary key (...)` clause alongside
		 * the column definitions (in any position).
		 * @param string $tableName Used only to produce readable error messages
		 * @return array{columns: AstColumnDefinition[], primaryKeyColumns: string[]}
		 * @throws LexerException|ParserException
		 */
		private function parseColumnList(string $tableName): array {
			$this->lexer->match(Token::ParenthesesOpen);

			$columns = [];
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

			return ['columns' => $columns, 'primaryKeyColumns' => $primaryKeyColumns];
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
