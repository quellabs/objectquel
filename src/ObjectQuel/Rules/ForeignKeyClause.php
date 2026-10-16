<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parser for the `foreign key (col {, col}) references Table [(col
	 * {, col})] [on delete action] [on update action]` clause — shared
	 * grammar between `create`'s column list (Rules\CreateTable) and
	 * `alter`'s `add foreign key`/`drop foreign key` sub-operations
	 * (Rules\AlterTable), rather than duplicating the parse routine in
	 * both places (mirrors Rules\PrimaryKeyClause).
	 *
	 * The local and referenced column lists are ordered and positionally
	 * paired; when both are given they must be the same length.
	 *
	 * `on delete`/`on update` may appear in either order (or be omitted
	 * entirely), each defaulting to RESTRICT/NO ACTION respectively —
	 * the same defaults @Orm\ForeignKeyAction falls back to when absent.
	 * No name is parsed here at all: the constraint name is always derived
	 * by the caller, never author-supplied.
	 *
	 * `references Table` may omit the column list, mirroring
	 * @Orm\ForeignKey's optional referencedColumn/columns — this returns
	 * `null` for it then, and the caller (AlterTableExecutor/CreateTableExecutor)
	 * resolves it to the target's primary key via schema introspection.
	 */
	class ForeignKeyClause {

		/** @var array<int, string> Valid values for onDelete/onUpdate, matching @Orm\ForeignKeyAction::VALID_ACTIONS */
		private const array VALID_ACTIONS = ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION'];

		/**
		 * Parses a `foreign key (col {, col}) references Table [(col {,
		 * col})] [on delete action] [on update action]` clause, assuming
		 * `foreign` is the current lookahead token.
		 * @return array{columns: list<string>, referencedTable: string, referencedColumns: ?list<string>, onDelete: string, onUpdate: string}
		 * @throws LexerException|ParserException
		 */
		public static function parse(Lexer $lexer): array {
			$lexer->matchKeyword('foreign');
			$lexer->matchKeyword('key');
			$columns = self::parseColumnList($lexer);

			$lexer->matchKeyword('references');
			$referencedTable = $lexer->match(Token::Identifier)->getStringValue();
			$referencedColumns = $lexer->peek()->getType() === Token::ParenthesesOpen
				? self::parseColumnList($lexer)
				: null;

			if ($referencedColumns !== null && count($referencedColumns) !== count($columns)) {
				throw new ParserException(
					"Foreign key declares " . count($columns) . " local column(s) but " . count($referencedColumns) .
					" referenced column(s) — the two lists must be the same length, on line {$lexer->getLineNumber()}"
				);
			}

			$onDelete = 'RESTRICT';
			$onUpdate = 'NO ACTION';

			while ($lexer->optionalMatchKeyword('on') !== null) {
				if ($lexer->optionalMatchKeyword('delete') !== null) {
					$onDelete = self::parseAction($lexer);
				} elseif ($lexer->optionalMatchKeyword('update') !== null) {
					$onUpdate = self::parseAction($lexer);
				} else {
					throw new ParserException("Expected 'delete' or 'update' after 'on' in foreign key clause, on line {$lexer->getLineNumber()}");
				}
			}

			return [
				'columns'           => $columns,
				'referencedTable'   => $referencedTable,
				'referencedColumns' => $referencedColumns,
				'onDelete'          => $onDelete,
				'onUpdate'          => $onUpdate,
			];
		}

		/**
		 * A parenthesized, comma-separated column name list — `(col {,
		 * col})`, rejecting a column repeated within it. Shared by the
		 * local column list, the optional referenced column list, and
		 * `drop foreign key`'s column list (Rules\AlterTable).
		 * @return list<string>
		 * @throws LexerException|ParserException
		 */
		public static function parseColumnList(Lexer $lexer): array {
			$lexer->match(Token::ParenthesesOpen);

			$columns = [];
			$seenColumns = [];

			do {
				$column = $lexer->match(Token::Identifier)->getStringValue();

				if (isset($seenColumns[$column])) {
					throw new ParserException("Duplicate column '{$column}' in foreign key column list, on line {$lexer->getLineNumber()}");
				}

				$seenColumns[$column] = true;
				$columns[] = $column;
			} while ($lexer->optionalMatch(Token::Comma));

			$lexer->match(Token::ParenthesesClose);

			return $columns;
		}

		/**
		 * One of `restrict|cascade|set null|no action`, matching
		 * @Orm\ForeignKeyAction::VALID_ACTIONS.
		 * @throws LexerException|ParserException
		 */
		private static function parseAction(Lexer $lexer): string {
			if ($lexer->optionalMatchKeyword('restrict') !== null) {
				return 'RESTRICT';
			}

			if ($lexer->optionalMatchKeyword('cascade') !== null) {
				return 'CASCADE';
			}

			if ($lexer->optionalMatchKeyword('set') !== null) {
				$lexer->match(Token::Null);
				return 'SET NULL';
			}

			if ($lexer->optionalMatchKeyword('no') !== null) {
				$lexer->matchKeyword('action');
				return 'NO ACTION';
			}

			throw new ParserException(
				"Expected a foreign key action (one of: " . implode(', ', self::VALID_ACTIONS) . "), on line {$lexer->getLineNumber()}"
			);
		}
	}
