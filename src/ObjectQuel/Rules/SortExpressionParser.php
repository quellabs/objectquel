<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parses a `sort by expr [asc|desc], ...` list. Shared by the query-level
	 * `sort by` clause (Retrieve) and the inline `sort by` inside sequence
	 * function calls (QueryFunction) — same grammar, two call sites.
	 */
	class SortExpressionParser {

		/** @var string Default sort order when ASC/DESC is not explicitly specified */
		private const string DEFAULT_SORT_ORDER = '';

		private Lexer $lexer;
		private ArithmeticExpression $expressionRule;

		/**
		 * @param Lexer $lexer
		 * @param ArithmeticExpression $expressionRule Rule used to parse each sort expression
		 */
		public function __construct(Lexer $lexer, ArithmeticExpression $expressionRule) {
			$this->lexer = $lexer;
			$this->expressionRule = $expressionRule;
		}

		/**
		 * Parse individual sort expressions and their order specifications.
		 * @return array<int, array{ast: \Quellabs\ObjectQuel\ObjectQuel\AstInterface, order: string}>
		 * @throws LexerException|ParserException on expression parsing errors
		 */
		public function parse(): array {
			$sortArray = [];

			do {
				$expression = $this->expressionRule->parse();
				$order = $this->parseSortOrder();

				$sortArray[] = [
					'ast'   => $expression,
					'order' => $order
				];
			} while ($this->lexer->optionalMatch(Token::Comma));

			return $sortArray;
		}

		/**
		 * Parse the sort order specification (ASC/DESC) for a sort expression.
		 * @return string Sort order: 'asc', 'desc', or default empty string
		 * @throws LexerException
		 */
		private function parseSortOrder(): string {
			if ($this->lexer->optionalMatch(Token::Asc)) {
				return 'asc';
			}

			if ($this->lexer->optionalMatch(Token::Desc)) {
				return 'desc';
			}

			return self::DEFAULT_SORT_ORDER;
		}
	}
