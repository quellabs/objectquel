<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parser for the `foreign key (col) references Table (col) [on delete
	 * action] [on update action]` clause — shared grammar between `create`'s
	 * column list (Rules\CreateTable) and `alter`'s `add foreign key`
	 * sub-operation (Rules\AlterTable), rather than duplicating the parse
	 * routine in both places (mirrors Rules\PrimaryKeyClause; see
	 * objectquel-foreign-key-design.md, "Implementation surface").
	 *
	 * Single column each side, never a list — Postgres can't safely pair a
	 * composite FK's columns back from information_schema, and
	 * @Orm\ForeignKey never declares more than one column anyway (see
	 * objectquel-foreign-key-design.md, decision 3).
	 *
	 * `on delete`/`on update` may appear in either order (or be omitted
	 * entirely), each defaulting to RESTRICT/NO ACTION respectively —
	 * the same defaults @Orm\ForeignKeyAction falls back to when absent.
	 * No name is parsed here at all: the constraint name is always derived
	 * by the caller, never author-supplied (see
	 * objectquel-foreign-key-design.md, decision 1).
	 */
	class ForeignKeyClause {

		/** @var array<int, string> Valid values for onDelete/onUpdate, matching @Orm\ForeignKeyAction::VALID_ACTIONS */
		private const array VALID_ACTIONS = ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION'];

		/**
		 * Parses a `foreign key (col) references Table (col) [on delete
		 * action] [on update action]` clause, assuming `foreign` is the
		 * current lookahead token.
		 * @param Lexer $lexer
		 * @return array{column: string, referencedTable: string, referencedColumn: string, onDelete: string, onUpdate: string}
		 * @throws LexerException|ParserException
		 */
		public static function parse(Lexer $lexer): array {
			$lexer->matchKeyword('foreign');
			$lexer->matchKeyword('key');
			$column = self::parseSingleColumn($lexer);

			$lexer->matchKeyword('references');
			$referencedTable = $lexer->match(Token::Identifier)->getStringValue();
			$referencedColumn = self::parseSingleColumn($lexer);

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
				'column'           => $column,
				'referencedTable'  => $referencedTable,
				'referencedColumn' => $referencedColumn,
				'onDelete'         => $onDelete,
				'onUpdate'         => $onUpdate,
			];
		}

		/**
		 * A single parenthesized column name — `(col)`, never a list (see
		 * class docblock). Matching `)` right after the one identifier
		 * naturally rejects a comma-separated list with the same
		 * "Unexpected token" error every other unsupported construct gets.
		 * @throws LexerException
		 */
		private static function parseSingleColumn(Lexer $lexer): string {
			$lexer->match(Token::ParenthesesOpen);
			$column = $lexer->match(Token::Identifier)->getStringValue();
			$lexer->match(Token::ParenthesesClose);
			return $column;
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
