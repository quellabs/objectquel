<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAssignment;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parser for `append to <range/entity> (...)` statements in the
	 * ObjectQuel language — QUEL's insert verb, plus multi-row append and
	 * insert-from-select (see objectquel-append-plan.md).
	 *
	 * Two shapes share the same opening syntax and are distinguished by
	 * whether `=` follows the first name inside the parentheses:
	 *
	 *   append to u (name = "Alice", email = "alice@example.com")
	 *   append to u (name = "Alice", ...), (name = "Bob", ...)
	 *   append to o (name, total) retrieve (a.name, a.total) where a.closed = true
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

			$entityName = $this->parseTarget($ranges);

			$this->lexer->match(Token::ParenthesesOpen);

			$firstProperty = $this->lexer->match(Token::Identifier)->getStringValue();

			if ($this->lexer->optionalMatch(Token::Equals)) {
				$rows = $this->parseValueRows($firstProperty);
				$this->consumeOptionalSemicolon();
				return AstAppend::forValues($entityName, $rows);
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
		 * Parses the `to <range/entity>` target and resolves it to an entity
		 * class name — either a declared range's entity, or (when no range
		 * with that name exists) the identifier itself, treated as a bare
		 * entity name.
		 * @param AstRange[] $ranges
		 * @return string
		 * @throws LexerException|ParserException
		 */
		private function parseTarget(array $ranges): string {
			$name = $this->lexer->match(Token::Identifier)->getStringValue();

			// Range aliases are never namespaced — a following backslash means
			// this can only be a bare (possibly namespaced) entity name, not
			// a range lookup.
			if ($this->lexer->lookahead() === Token::Backslash) {
				while ($this->lexer->optionalMatch(Token::Backslash)) {
					$name .= "\\" . $this->lexer->match(Token::Identifier)->getStringValue();
				}

				return $name;
			}

			foreach ($ranges as $range) {
				if ($range->getName() !== $name) {
					continue;
				}

				if (!$range instanceof AstRangeDatabase) {
					throw new ParserException("append target '{$name}' must be a database entity range");
				}

				return $range->getEntityName();
			}

			// No matching range — treat the identifier as a bare entity name.
			return $name;
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
