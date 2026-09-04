<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parses a `where <condition>` clause — shared by every rule that owns
	 * one: `retrieve` (optional — no `where` means no filter), and
	 * `replace`/`delete` (mandatory — there's no "current tuple of an
	 * enclosing retrieve loop" default here; that exception belongs to the
	 * EQUEL procedural layer, not bare `replace`/`delete` — see
	 * objectquel-replace-plan.md).
	 */
	class WhereClause {

		private Lexer $lexer;
		private LogicalExpression $conditionRule;

		/**
		 * WhereClause constructor.
		 * @param Lexer $lexer
		 */
		public function __construct(Lexer $lexer) {
			$this->lexer = $lexer;
			$this->conditionRule = new LogicalExpression($lexer);
		}

		/**
		 * Parses an optional `where <condition>` clause.
		 * @return AstInterface|null The parsed condition, or null if no `where` was present.
		 * @throws LexerException|ParserException
		 */
		public function parseOptional(): ?AstInterface {
			if (!$this->lexer->optionalMatch(Token::Where)) {
				return null;
			}

			return $this->conditionRule->parse();
		}

		/**
		 * Parses a mandatory `where <condition>` clause.
		 * @param string $statementName Used only to produce a readable error message (e.g. "replace")
		 * @return AstInterface
		 * @throws LexerException|ParserException
		 */
		public function parseRequired(string $statementName): AstInterface {
			$conditions = $this->parseOptional();

			if ($conditions === null) {
				throw new ParserException("'{$statementName}' requires a 'where' clause, on line {$this->lexer->getLineNumber()}");
			}

			return $conditions;
		}
	}
