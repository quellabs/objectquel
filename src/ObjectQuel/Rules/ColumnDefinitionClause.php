<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstColumnDefinition;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parser for a single `attr = [unsigned] type[(limit)|(precision,scale)]
	 * [constraints]` column definition — shared grammar between `create`'s
	 * column list (Rules\CreateTable) and `alter`'s `add`/`retype`
	 * sub-operations (Rules\AlterTable), rather than duplicating the parse
	 * routine in both places (see objectquel-alter-table-design.md: "`alter`
	 * should reuse this exact grammar for any sub-operation that specifies a
	 * column shape ... rather than invent a second column syntax").
	 *
	 * Extracted out of Rules\CreateTable, mirroring how PrimaryKeyClause was
	 * already extracted for the same reason.
	 */
	class ColumnDefinitionClause {

		/**
		 * Parse a single `attr = [unsigned] type[(limit)|(precision,scale)] [constraints]`
		 * definition. `unsigned` precedes the type name, matching C's `unsigned int`
		 * order rather than MySQL's inline `INT UNSIGNED` suffix.
		 *
		 * `unsigned` on a type that can never be signed or unsigned in the
		 * first place (e.g. `string`) is a genuine authoring mistake and is
		 * rejected here at parse time, regardless of target engine. Whether
		 * the target engine actually *has* an UNSIGNED modifier is a separate,
		 * platform-level question this parser has no opinion on — the AST is
		 * engine-agnostic; see DDLTypeMapper.
		 * @param Lexer $lexer
		 * @return AstColumnDefinition
		 * @throws LexerException|ParserException
		 */
		public static function parse(Lexer $lexer): AstColumnDefinition {
			$name = $lexer->match(Token::Identifier)->getStringValue();
			$lexer->match(Token::Equals);

			$unsigned = $lexer->optionalMatchKeyword('unsigned') !== null;
			$type = self::parseColumnType($lexer, $unsigned, $name);

			if ($unsigned && !TypeMapper::supportsUnsigned($type)) {
				throw new ParserException("Column '{$name}' declares 'unsigned' but type '{$type}' does not support it");
			}

			$typeArguments = self::parseOptionalTypeArguments($lexer);
			$constraints = self::parseColumnConstraints($lexer);

			return new AstColumnDefinition(
				$name,
				$type,
				$typeArguments->limit,
				$typeArguments->precision,
				$typeArguments->scale,
				$unsigned,
				$constraints->nullable,
				$constraints->identity
			);
		}

		/**
		 * Parses the column's type name. Mirrors C's `unsigned` shorthand: when
		 * `unsigned` was just consumed and no type name follows it, the type
		 * defaults to `integer` (i.e. bare `unsigned` means `unsigned integer`,
		 * the same way C's bare `unsigned` means `unsigned int`).
		 * @param Lexer $lexer
		 * @param bool $unsigned Whether the `unsigned` keyword was just consumed
		 * @param string $columnName Used only to produce readable error messages
		 * @return string
		 * @throws LexerException|ParserException
		 */
		private static function parseColumnType(Lexer $lexer, bool $unsigned, string $columnName): string {
			if ($unsigned && $lexer->lookahead() !== Token::Identifier) {
				return 'integer';
			}

			$typeToken = $lexer->match(Token::Identifier);
			$type = strtolower($typeToken->getStringValue());

			if (!TypeMapper::isValidColumnType($type)) {
				throw new ParserException("Unknown column type '{$type}' for column '{$columnName}'");
			}

			return $type;
		}

		/**
		 * Parse an optional `(limit)` or `(precision, scale)` suffix after a type name.
		 * @param Lexer $lexer
		 * @return ColumnTypeArguments
		 * @throws LexerException|ParserException
		 */
		private static function parseOptionalTypeArguments(Lexer $lexer): ColumnTypeArguments {
			if (!$lexer->optionalMatch(Token::ParenthesesOpen)) {
				return new ColumnTypeArguments();
			}

			$first = (int)$lexer->match(Token::Number)->getNumericValue();

			if ($lexer->optionalMatch(Token::Comma)) {
				$scale = (int)$lexer->match(Token::Number)->getNumericValue();
				$lexer->match(Token::ParenthesesClose);
				return new ColumnTypeArguments(precision: $first, scale: $scale);
			}

			$lexer->match(Token::ParenthesesClose);
			return new ColumnTypeArguments(limit: $first);
		}

		/**
		 * Parse the constraint keywords following a column's type: any combination
		 * of `nullable`, `identity`, in any order. Columns are NOT NULL by default —
		 * `create`/`alter` deliberately don't read like raw SQL DDL (nullable unless
		 * `not null` is written); `nullable` opts a column out of that default
		 * instead, matching @Orm\Column's `nullable` parameter. `unsigned` is not
		 * parsed here — it precedes the type name instead (see parseColumnType()).
		 * `primary key` is not a column-level constraint either — see
		 * PrimaryKeyClause.
		 * @param Lexer $lexer
		 * @return ColumnConstraints
		 * @throws LexerException
		 */
		private static function parseColumnConstraints(Lexer $lexer): ColumnConstraints {
			$nullable = false;
			$identity = false;

			while (true) {
				if ($lexer->optionalMatchKeyword('nullable')) {
					$nullable = true;
					continue;
				}

				if ($lexer->optionalMatchKeyword('identity')) {
					$identity = true;
					continue;
				}

				break;
			}

			return new ColumnConstraints($nullable, $identity);
		}
	}
