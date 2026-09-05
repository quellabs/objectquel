<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parses the index form's trailing clause, `on Table [if exists]`, given
	 * an already-parsed index name — Rules\Destroy owns the shared `destroy
	 * Name` prefix and delegates here once it sees `on` follows (see that
	 * class, and objectquel-destroy-index-plan.md).
	 */
	class DestroyIndex {

		private Lexer $lexer;

		public function __construct(Lexer $lexer) {
			$this->lexer = $lexer;
		}

		/**
		 * @param string $indexName Already consumed by Rules\Destroy
		 * @throws LexerException
		 */
		public function parse(string $indexName): AstDestroyIndex {
			$this->lexer->match(Token::On);
			$tableName = $this->lexer->match(Token::Identifier)->getStringValue();

			$ifExists = $this->parseOptionalIfExists();

			$this->consumeOptionalSemicolon();

			return new AstDestroyIndex($indexName, $tableName, $ifExists);
		}

		/**
		 * @throws LexerException
		 */
		private function parseOptionalIfExists(): bool {
			if (!$this->lexer->optionalMatch(Token::If)) {
				return false;
			}

			$this->lexer->match(Token::Exists);
			return true;
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
