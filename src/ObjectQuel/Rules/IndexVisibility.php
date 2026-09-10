<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstHideIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstShowIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parser for `hide Name on Table` / `show Name on Table` statements in
	 * the ObjectQuel language. Unlike `destroy`, `hide`/`show` are never
	 * ambiguous with another statement's leading keyword, so each gets its
	 * own unconditional entry point (parseHide()/parseShow()) rather than a
	 * shared-prefix dispatch like Rules\Destroy.
	 *
	 * Both forms share one grammar past the leading verb — a bare index name
	 * plus `on Table`, same shape as Rules\DestroyIndex's trailing clause,
	 * no column list and no `is` naming clause since neither statement
	 * defines anything new — so that shared tail is parsed once here.
	 */
	class IndexVisibility {

		private Lexer $lexer;

		public function __construct(Lexer $lexer) {
			$this->lexer = $lexer;
		}

		/**
		 * Parse a complete `hide Name on Table` statement.
		 * @throws LexerException
		 */
		public function parseHide(): AstHideIndex {
			$this->lexer->matchKeyword('hide');

			[$indexName, $tableName] = $this->parseTail();

			return new AstHideIndex($indexName, $tableName);
		}

		/**
		 * Parse a complete `show Name on Table` statement.
		 * @throws LexerException
		 */
		public function parseShow(): AstShowIndex {
			$this->lexer->matchKeyword('show');

			[$indexName, $tableName] = $this->parseTail();

			return new AstShowIndex($indexName, $tableName);
		}

		/**
		 * Parses the `Name on Table` tail shared by both forms.
		 * @return array{0: string, 1: string} [$indexName, $tableName]
		 * @throws LexerException
		 */
		private function parseTail(): array {
			$indexName = $this->lexer->match(Token::Identifier)->getStringValue();

			$this->lexer->matchKeyword('on');
			$tableName = $this->lexer->match(Token::Identifier)->getStringValue();

			$this->consumeOptionalSemicolon();

			return [$indexName, $tableName];
		}

		/**
		 * @throws LexerException
		 */
		private function consumeOptionalSemicolon(): void {
			if ($this->lexer->lookahead() === Token::Semicolon) {
				$this->lexer->match(Token::Semicolon);
			}
		}
	}
