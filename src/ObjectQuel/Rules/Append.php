<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parser for `append to <range> (...)` statements in the ObjectQuel
	 * language — QUEL's insert verb, plus multi-row append and
	 * insert-from-select (see objectquel-append-plan.md).
	 *
	 * The target must already be a declared range (`range of <name> is
	 * <Entity>`) — same restriction `replace`/`delete` have, and for the
	 * same reason the upsert extension below needs it: a bare entity name
	 * has nothing for the target to resolve identifiers against.
	 *
	 * Two shapes share the same opening syntax and are distinguished by
	 * whether `=` follows the first name inside the parentheses:
	 *
	 *   append to u (name = "Alice", email = "alice@example.com")
	 *   append to u (name = "Alice", ...), (name = "Bob", ...)
	 *   append to o (name, total) retrieve (a.name, a.total) where a.closed = true
	 *
	 * The literal-values form also accepts a trailing upsert extension (see
	 * objectquel-upsert-plan.md) — `or replace`'s assignment list is itself
	 * optional, defaulting to "overwrite with the row that would have been
	 * inserted" when omitted:
	 *
	 *   append to u (email = :e, name = :n) or replace where u.email = :e
	 *   append to u (email = :e, views = 1) or replace (views = u.views + 1) where u.email = :e
	 */
	class Append {

		/**
		 * The lexer instance used for tokenizing and processing the input
		 */
		private Lexer $lexer;

		/**
		 * Append parser constructor
		 * @param Lexer $lexer The lexer instance to use for tokenization
		 */
		public function __construct(Lexer $lexer) {
			$this->lexer = $lexer;
		}

		/**
		 * Parse a complete `append` statement.
		 * @param AstRange[] $ranges Ranges already parsed ahead of this statement
		 * @return AstAppend
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		public function parse(array $ranges): AstAppend {
			$this->lexer->match(Token::Append);
			$this->lexer->match(Token::To);

			$targetName = $this->lexer->match(Token::Identifier)->getStringValue();
			$targetRange = TargetRange::resolve($targetName, $ranges, 'append');
			$entityName = $targetRange->getEntityName();

			$this->lexer->match(Token::ParenthesesOpen);

			$firstProperty = $this->lexer->match(Token::Identifier)->getStringValue();

			if ($this->lexer->optionalMatch(Token::Equals)) {
				$rows = $this->parseValueRows($firstProperty);
				$onConflict = $this->parseOptionalOnConflict($targetRange);
				$this->consumeOptionalSemicolon();
				return AstAppend::forValues($entityName, $rows, $onConflict);
			}

			$columns = $this->parseColumnList($firstProperty);

			if ($this->lexer->lookahead() !== Token::Retrieve) {
				throw new ParserException("Expected 'retrieve' after the column list in an insert-from-select append, on line {$this->lexer->getLineNumber()}");
			}

			$retrieveRule = new Retrieve($this->lexer);
			$source = $retrieveRule->parse([], $ranges);

			return AstAppend::forSelect($entityName, $columns, $source);
		}

		/**
		 * Parses upsert's optional trailing `or replace [(...)] where ...`
		 * clause (see objectquel-upsert-plan.md). The target range is implied
		 * to be this statement's own target. The assignment list itself is
		 * optional here (unlike a standalone `replace`) — `or replace where
		 * <cond>` with no list means "on conflict, overwrite with the row
		 * that would have been inserted"; see QuelToSQLUpsert.
		 * @param AstRangeDatabase $targetRange
		 * @return AstReplace|null
		 * @throws LexerException|ParserException
		 */
		private function parseOptionalOnConflict(AstRangeDatabase $targetRange): ?AstReplace {
			if (!$this->lexer->optionalMatch(Token::Or)) {
				return null;
			}

			$this->lexer->match(Token::Replace);

			$replaceRule = new Replace($this->lexer);
			return $replaceRule->parseAssignmentsAndConditions($targetRange, assignmentsOptional: true);
		}

		/**
		 * Parses one or more comma-separated, parenthesized assignment-rows:
		 * `(name = "Alice", ...), (name = "Bob", ...)`. The first row's first
		 * property/`=` pair has already been consumed by the caller (needed to
		 * distinguish this form from insert-from-select).
		 * @param string $firstProperty Property name already consumed for the first row
		 * @return AstAssignment[][]
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		private function parseValueRows(string $firstProperty): array {
			$rows = [$this->parseAssignmentRow($firstProperty)];
			$referenceProperties = $this->propertyNames($rows[0]);

			while ($this->lexer->optionalMatch(Token::Comma)) {
				$this->lexer->match(Token::ParenthesesOpen);

				$property = $this->lexer->match(Token::Identifier)->getStringValue();
				$this->lexer->match(Token::Equals);

				$row = $this->parseAssignmentRow($property);

				if ($this->propertyNames($row) !== $referenceProperties) {
					throw new ParserException("Every row in a multi-row append must assign the same set of properties, on line {$this->lexer->getLineNumber()}");
				}

				$rows[] = $row;
			}

			return $rows;
		}

		/**
		 * Parses a single `property = value, property = value, ...)` row —
		 * the leading `property =` has already been consumed by the caller.
		 * @param string $firstProperty
		 * @return AstAssignment[]
		 * @throws LexerException|ParserException|\ReflectionException
		 */
		private function parseAssignmentRow(string $firstProperty): array {
			$expressionRule = new ArithmeticExpression($this->lexer);

			$row = [];
			$seenProperties = [];
			$property = $firstProperty;

			while (true) {
				if (isset($seenProperties[$property])) {
					throw new ParserException("Duplicate assignment to property '{$property}' in append, on line {$this->lexer->getLineNumber()}");
				}

				$seenProperties[$property] = true;
				$row[] = new AstAssignment($property, $expressionRule->parse());

				if (!$this->lexer->optionalMatch(Token::Comma)) {
					break;
				}

				$property = $this->lexer->match(Token::Identifier)->getStringValue();
				$this->lexer->match(Token::Equals);
			}

			$this->lexer->match(Token::ParenthesesClose);

			return $row;
		}

		/**
		 * Parses the bare column list of an insert-from-select append. The
		 * first column has already been consumed by the caller (needed to
		 * distinguish this form from the literal-values form).
		 * @param string $firstColumn
		 * @return string[]
		 * @throws LexerException|ParserException
		 */
		private function parseColumnList(string $firstColumn): array {
			$columns = [$firstColumn];
			$seenColumns = [$firstColumn => true];

			while ($this->lexer->optionalMatch(Token::Comma)) {
				$column = $this->lexer->match(Token::Identifier)->getStringValue();

				if (isset($seenColumns[$column])) {
					throw new ParserException("Duplicate column '{$column}' in append column list, on line {$this->lexer->getLineNumber()}");
				}

				$seenColumns[$column] = true;
				$columns[] = $column;
			}

			$this->lexer->match(Token::ParenthesesClose);

			return $columns;
		}

		/**
		 * @param AstAssignment[] $row
		 * @return string[]
		 */
		private function propertyNames(array $row): array {
			$names = array_map(fn(AstAssignment $assignment) => $assignment->getProperty(), $row);
			sort($names);
			return $names;
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
