<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
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
	 * objectquel-create-table-plan.md) — `table` is only used separately, as
	 * the `range of x is table Name` disambiguation marker.
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
			$this->lexer->match(Token::Create);

			$temporary = $this->lexer->optionalMatch(Token::Temporary) !== null;
			$tableName = $this->lexer->match(Token::Identifier)->getStringValue();

			$columns = $this->parseColumnList($tableName);
			$ifNotExists = $this->parseOptionalIfNotExists();

			$this->consumeOptionalSemicolon();

			return new AstCreateTable($tableName, $columns, $temporary, $ifNotExists);
		}

		/**
		 * Parse an optional trailing `if not exists` qualifier.
		 * @return bool
		 * @throws LexerException|ParserException
		 */
		private function parseOptionalIfNotExists(): bool {
			if (!$this->lexer->optionalMatch(Token::If)) {
				return false;
			}

			$this->lexer->match(Token::Not);
			$this->lexer->match(Token::Exists);
			return true;
		}

		/**
		 * Parse the parenthesized, comma-separated column definition list.
		 * @param string $tableName Used only to produce a readable error message
		 * @return AstColumnDefinition[]
		 * @throws LexerException|ParserException
		 */
		private function parseColumnList(string $tableName): array {
			$this->lexer->match(Token::ParenthesesOpen);

			$columns = [];
			$seenPrimaryKey = false;
			$seenNames = [];

			do {
				$column = $this->parseColumnDefinition();

				if (isset($seenNames[$column->getName()])) {
					throw new ParserException("Duplicate column name '{$column->getName()}' in create '{$tableName}'");
				}

				$seenNames[$column->getName()] = true;

				if ($column->isPrimaryKey()) {
					if ($seenPrimaryKey) {
						throw new ParserException("Table '{$tableName}' declares more than one primary key column");
					}

					$seenPrimaryKey = true;
				}

				$columns[] = $column;
			} while ($this->lexer->optionalMatch(Token::Comma));

			$this->lexer->match(Token::ParenthesesClose);

			return $columns;
		}

		/**
		 * Parse a single `attr = type[(limit)|(precision,scale)] [constraints]` definition.
		 * @return AstColumnDefinition
		 * @throws LexerException|ParserException
		 */
		private function parseColumnDefinition(): AstColumnDefinition {
			$name = $this->lexer->match(Token::Identifier)->getStringValue();
			$this->lexer->match(Token::Equals);

			$typeToken = $this->lexer->match(Token::Identifier);
			$type = strtolower($typeToken->getStringValue());

			if (!TypeMapper::isValidColumnType($type)) {
				throw new ParserException("Unknown column type '{$type}' for column '{$name}'");
			}

			[$limit, $precision, $scale] = $this->parseOptionalTypeArguments();
			[$notNull, $primaryKey, $identity] = $this->parseColumnConstraints($name);

			return new AstColumnDefinition($name, $type, $limit, $precision, $scale, false, $notNull, $primaryKey, $identity);
		}

		/**
		 * Parse an optional `(limit)` or `(precision, scale)` suffix after a type name.
		 * @return array{0: int|null, 1: int|null, 2: int|null} [limit, precision, scale]
		 * @throws LexerException|ParserException
		 */
		private function parseOptionalTypeArguments(): array {
			if (!$this->lexer->optionalMatch(Token::ParenthesesOpen)) {
				return [null, null, null];
			}

			$first = (int)$this->lexer->match(Token::Number)->getNumericValue();

			if ($this->lexer->optionalMatch(Token::Comma)) {
				$scale = (int)$this->lexer->match(Token::Number)->getNumericValue();
				$this->lexer->match(Token::ParenthesesClose);
				return [null, $first, $scale];
			}

			$this->lexer->match(Token::ParenthesesClose);
			return [$first, null, null];
		}

		/**
		 * Parse the constraint keywords following a column's type: any combination
		 * of `not null`, `null`, `primary key`, `identity`, in any order.
		 * @param string $columnName Used only to produce a readable error message
		 * @return array{0: bool, 1: bool, 2: bool} [notNull, primaryKey, identity]
		 * @throws LexerException|ParserException
		 */
		private function parseColumnConstraints(string $columnName): array {
			$notNull = false;
			$primaryKey = false;
			$identity = false;

			while (true) {
				if ($this->lexer->optionalMatch(Token::Not)) {
					$this->lexer->match(Token::Null);
					$notNull = true;
					continue;
				}

				if ($this->lexer->optionalMatch(Token::Null)) {
					// Explicit 'null' is a no-op (the default); consume and move on.
					continue;
				}

				if ($this->lexer->optionalMatch(Token::Primary)) {
					$this->lexer->match(Token::Key);
					$primaryKey = true;
					$notNull = true; // A primary key is implicitly NOT NULL.
					continue;
				}

				if ($this->lexer->optionalMatch(Token::Identity)) {
					$identity = true;
					continue;
				}

				break;
			}

			// Identity columns are rendered assuming they're the primary key
			// (e.g. SQLite's AUTOINCREMENT only exists on INTEGER PRIMARY KEY).
			if ($identity && !$primaryKey) {
				throw new ParserException("Column '{$columnName}' declares 'identity' without 'primary key' — identity columns must be the table's primary key");
			}

			return [$notNull, $primaryKey, $identity];
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
