<?php

	namespace Quellabs\ObjectQuel\Sculpt\Helpers;

	/**
	 * Escapes a raw string value for splicing into a single-quoted Quel
	 * string literal — the write-side inverse of Lexer.php's
	 * escapeMapSingleQuote. Backslash must be escaped before quote: doing
	 * the quote first would introduce a fresh backslash the (already-run)
	 * backslash pass would never see, leaving it unescaped.
	 *
	 * Layer one of two when QuelMigrationBuilder splices a literal into
	 * generated PHP source: this escapes the Quel text itself, then the
	 * whole resulting statement gets ordinary addslashes() for its PHP
	 * string-literal wrapper.
	 */
	final class QuelLiteralEscaper {

		public static function escape(string $value): string {
			$value = str_replace('\\', '\\\\', $value);
			return str_replace("'", "\\'", $value);
		}
	}
