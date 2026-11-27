<?php

	namespace Quellabs\ObjectQuel\Execution\Helpers;

	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstString;

	/**
	 * Converts a string literal with unescaped * and ? wildcards into an SQL LIKE or fnmatch() pattern.
	 */
	final class WildcardPattern {

		/** Escape character in both pattern forms; LIKE needs an explicit ESCAPE clause since SQLite and SQL Server have no default */
		public const string ESCAPE_CHAR = '\\';

		/**
		 * Builds an SQL LIKE pattern: wildcards become % and _, literal %, _ and \ are escaped.
		 * @param AstString $string String literal containing wildcards
		 * @return string
		 */
		public static function toSqlLike(AstString $string): string {
			return self::build($string, ['*' => '%', '?' => '_'], '%_\\');
		}

		/**
		 * Builds an fnmatch() pattern: wildcards stay as-is, literal *, ?, [, ] and \ are escaped.
		 * @param AstString $string String literal containing wildcards
		 * @return string
		 */
		public static function toFnmatch(AstString $string): string {
			return self::build($string, ['*' => '*', '?' => '?'], '*?[]\\');
		}

		/**
		 * Rewrites the value byte by byte, mapping wildcard offsets and backslash-escaping literal special characters.
		 * @param AstString $string String literal containing wildcards
		 * @param array<string, string> $wildcardMap Replacement for each wildcard character
		 * @param string $specialChars Characters to escape when they appear as literals
		 * @return string
		 */
		private static function build(AstString $string, array $wildcardMap, string $specialChars): string {
			$value = $string->getValue();
			$wildcardOffsets = array_flip($string->getWildcardOffsets());
			$pattern = '';

			for ($i = 0, $length = strlen($value); $i < $length; $i++) {
				$char = $value[$i];

				if (isset($wildcardOffsets[$i])) {
					$pattern .= $wildcardMap[$char];
				} elseif (str_contains($specialChars, $char)) {
					$pattern .= self::ESCAPE_CHAR . $char;
				} else {
					$pattern .= $char;
				}
			}

			return $pattern;
		}
	}
