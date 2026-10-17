<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Reads a numeric literal with an optional minus sign.
	 */
	class SignedNumber {
		
		/**
		 * @param Lexer $lexer Source of the numeric literal
		 * @return int|float Parsed value
		 * @throws LexerException
		 */
		public static function parse(Lexer $lexer): int|float {
			$negative = $lexer->optionalMatch(Token::Minus) !== null;
			$value = $lexer->match(Token::Number)->getNumericValue();
			return $negative ? -$value : $value;
		}
	}
