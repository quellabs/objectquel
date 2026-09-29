<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;

	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\LexerException;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Token;

	/**
	 * Parses zero or more `@name value` compiler directives, e.g. `@ignoreSoftDelete true`.
	 * Shared by Parser (ahead of a top-level statement) and ProcedureParser (ahead of
	 * `define function`) — same syntax and directive names in both places.
	 */
	class CompilerDirectiveParser {

		/**
		 * @param Lexer $lexer
		 * @return array<string, bool|int|float|string> Directive values by lowercased name
		 * @throws LexerException|ParserException
		 */
		public static function parse(Lexer $lexer): array {
			$directives = [];

			while ($lexer->peek()->getType() == Token::CompilerDirective) {
				$directive = $lexer->match(Token::CompilerDirective);
				$directiveName = $directive->getStringValue();

				// Stored lowercase so directive names are case-insensitive —
				// see AstRetrieve/AstDelete/AstRoutineDefinition::getDirective(),
				// which lowercase the lookup key the same way.
				$directives[strtolower($directiveName)] = self::matchValue($lexer, $directiveName);
			}

			return $directives;
		}

		/**
		 * @param Lexer $lexer
		 * @param string $directiveName Used only for the error message
		 * @return bool|int|float|string
		 * @throws LexerException|ParserException
		 */
		private static function matchValue(Lexer $lexer, string $directiveName): bool|int|float|string {
			if ($lexer->optionalMatch(Token::Minus)) {
				return -$lexer->match(Token::Number)->getNumericValue();
			}

			if ($lexer->optionalMatch(Token::True)) {
				return true;
			} elseif ($lexer->optionalMatch(Token::False)) {
				return false;
			} elseif (($token = $lexer->optionalMatch(Token::Number)) !== null) {
				return $token->getNumericValue();
			} elseif (($token = $lexer->optionalMatch(Token::Identifier)) !== null) {
				return $token->getStringValue();
			} else {
				throw new ParserException("Invalid compiler directive value for @{$directiveName}");
			}
		}
	}
