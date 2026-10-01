<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;

	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRange;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\Range;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parses zero or more `range of ... is ...` declarations in a row. Shared
	 * by Parser (ahead of a top-level statement) and Rules\Range (ahead of a
	 * subquery's own body) — same loop in both places.
	 */
	class RangeListParser {

		/**
		 * @param Lexer $lexer
		 * @param EntityStore $entityStore Used by Rules\Range to resolve `range of x is Name` against declared entities
		 * @return AstRange[]
		 * @throws LexerException|ParserException
		 */
		public static function parse(Lexer $lexer, EntityStore $entityStore): array {
			$ranges = [];
			$rangeRule = new Range($lexer, $entityStore);

			while ($lexer->peek()->getType() == Token::Range) {
				$ranges[] = $rangeRule->parse();
			}

			return $ranges;
		}
	}
