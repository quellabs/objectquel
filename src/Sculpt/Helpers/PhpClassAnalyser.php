<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	class PhpClassAnalyser {
		
		private string $content;
		
		/**
		 * PhpClassAnalyser constructor
		 * @param string $content Raw PHP source to analyse
		 */
		public function __construct(string $content) {
			$this->content = $content;
		}
		
		/**
		 * Returns true when a method with the given name is declared anywhere in the source.
		 * The check is case-insensitive and matches any visibility modifier (public, protected,
		 * private) or no modifier at all.
		 * @param string $name Unqualified method name, e.g. "getId"
		 * @return bool True if at least one matching method declaration is found
		 */
		public function hasMethod(string $name): bool {
			return preg_match($this->getMethodPattern($name), $this->content) === 1;
		}
		
		/**
		 * Returns true when the source contains a use statement whose fully-qualified
		 * class/interface name matches $name exactly (case-sensitive, full string match).
		 * @param string $name Fully-qualified class name as it appears after the "use" keyword,
		 *                     e.g. "Quellabs\ObjectQuel\Annotations\Orm\Column"
		 * @return bool True if a matching use statement exists
		 */
		public function hasUseClause(string $name): bool {
			return in_array(
				$name,
				$this->getUseClauses(),
				true
			);
		}
		
		/**
		 * Returns true when a class-level property with the given name exists.
		 * Only direct class members are considered — properties declared inside method
		 * bodies (e.g. local variable assignments) are excluded via brace-depth checking.
		 * @param string $name Property name without the leading "$", e.g. "userId"
		 * @return bool True if the property is declared at class body depth
		 */
		public function hasProperty(string $name): bool {
			return $this->getPropertyStartPos($name) !== null;
		}
		
		/**
		 * Returns all fully-qualified names imported via use statements in the source,
		 * in the order they appear. Aliases (use Foo as Bar) are returned as written,
		 * i.e. "Foo as Bar" — no alias stripping is performed.
		 * @return array<int, string> Ordered list of use-clause targets
		 */
		public function getUseClauses(): array {
			preg_match_all('/^\s*use\s+([^;\r\n]+);$/m', $this->content, $matches);
			return $matches[1];
		}
		
		/**
		 * Returns the character position of the semicolon that closes the last use statement
		 * in the file. Useful for inserting a new use statement immediately after the existing
		 * import block without disturbing anything else.
		 * Returns null when the file contains no use statements at all.
		 * @return int|null Zero-based character index of the closing semicolon, or null
		 */
		public function getLastUseClauseEndPos(): ?int {
			if (!preg_match_all('/^\s*use\s+[^;\r\n]+;/m', $this->content, $matches, PREG_OFFSET_CAPTURE)) {
				return null;
			}
			
			$lastMatch = end($matches[0]);

			// end() returns false on an empty array; guard defensively even though
			// preg_match_all above already ensures at least one match exists.
			if ($lastMatch === false) {
				return null;
			}

			// Offset + length - 1 lands on the semicolon itself
			return $lastMatch[1] + strlen($lastMatch[0]) - 1;
		}
		
		/**
		 * Returns the character position of the semicolon that closes the namespace declaration.
		 * Useful as a fallback insertion point when no use statements exist yet.
		 * Returns null when the file contains no namespace declaration.
		 * @return int|null Zero-based character index of the closing semicolon, or null
		 */
		public function getNamespaceEndPos(): ?int {
			if (!preg_match('/^namespace\s+[^;\r\n]+;/m', $this->content, $match, PREG_OFFSET_CAPTURE)) {
				return null;
			}
			
			// Offset + length - 1 lands on the semicolon itself
			return $match[0][1] + strlen($match[0][0]) - 1;
		}
		
		/**
		 * Returns the character position where the declaration of a named method begins,
		 * i.e. the position of the first character of the line containing "function $name(".
		 * Returns null when no matching method is found.
		 * @param string $name Unqualified method name, e.g. "__construct"
		 * @return int|null Zero-based character index of the method declaration start, or null
		 */
		public function getMethodStartPos(string $name): ?int {
			if (!preg_match($this->getMethodPattern($name), $this->content, $methodMatch, PREG_OFFSET_CAPTURE)) {
				return null;
			}
			
			return $methodMatch[0][1];
		}
		
		/**
		 * Returns the character position of the closing brace "}" that ends the named method.
		 * Returns null when the method is not found or its brace structure is malformed.
		 * @param string $name Unqualified method name, e.g. "setTitle"
		 * @return int|null Zero-based character index of the closing brace, or null
		 */
		public function getMethodEndPos(string $name): ?int {
			$startPos = $this->getMethodStartPos($name);
			
			if ($startPos === null) {
				return null;
			}
			
			// Scan forward from the declaration to locate the opening brace
			$openBracePos = strpos($this->content, '{', $startPos);
			
			if ($openBracePos === false) {
				return null;
			}
			
			return $this->findClosingBrace($openBracePos);
		}
		
		/**
		 * Returns the character position of the opening brace "{" of the named method's body.
		 * Returns null when the method is not found.
		 * @param string $name Unqualified method name, e.g. "getId"
		 * @return int|null Zero-based character index of the opening brace, or null
		 */
		public function getMethodOpeningBracePos(string $name): ?int {
			$startPos = $this->getMethodStartPos($name);
			
			if ($startPos === null) {
				return null;
			}
			
			$pos = strpos($this->content, '{', $startPos);
			
			if ($pos === false) {
				return null;
			}
			
			return $pos;
		}
		
		/**
		 * Returns the character position of the first character inside the named method's
		 * body, i.e. one position past the opening brace. Use this as the insertion point
		 * when prepending code to an existing method body.
		 * Returns null when the method is not found.
		 * @param string $name Unqualified method name, e.g. "__construct"
		 * @return int|null Zero-based character index of the first character inside the body, or null
		 */
		public function getMethodBodyStartPos(string $name): ?int {
			$bracePos = $this->getMethodOpeningBracePos($name);
			
			if ($bracePos === null) {
				return null;
			}
			
			// The body starts immediately after the opening brace
			return $bracePos + 1;
		}
		
		/**
		 * Returns the character position where a class-level property declaration begins.
		 * Only properties declared at direct class body depth (brace depth 1) are considered,
		 * so local variables that happen to share the name inside method bodies are ignored.
		 * Returns null when no matching property is found at class scope.
		 * @param string $name Property name without the leading "$", e.g. "createdAt"
		 * @return int|null Zero-based character index of the property declaration start, or null
		 */
		public function getPropertyStartPos(string $name): ?int {
			$nameQuoted = preg_quote($name, '/');
			$pattern = '/^\s*(?:protected|private|public)\s+[^;\r\n]*\$' . $nameQuoted . '\b[^;\r\n]*;/m';
			
			$classOpen = $this->getClassOpeningBracePosition();
			
			if ($classOpen === null) {
				return null;
			}
			
			// Restrict the search to the class body to avoid false positives in file-level code
			$classBody = substr($this->content, $classOpen);
			
			if (!preg_match_all($pattern, $classBody, $matches, PREG_OFFSET_CAPTURE)) {
				return null;
			}
			
			// Return the first match that sits at direct class member depth (depth 1)
			foreach ($matches[0] as $match) {
				if ($this->getDepthAt($classOpen + $match[1]) === 1) {
					return $classOpen + $match[1];
				}
			}
			
			return null;
		}
		
		/**
		 * Returns the character position of the semicolon that ends the named property's
		 * declaration line. Useful when the entire declaration (docblock + property line)
		 * needs to be replaced or removed.
		 * Returns null when the property is not found.
		 * @param string $name Property name without the leading "$", e.g. "userId"
		 * @return int|null Zero-based character index of the closing semicolon, or null
		 */
		public function getPropertyEndPos(string $name): ?int {
			$startPos = $this->getPropertyStartPos($name);
			
			if ($startPos === null) {
				return null;
			}
			
			$semicolonPos = strpos(
				$this->content,
				';',
				$startPos
			);
			
			return $semicolonPos !== false ? $semicolonPos : null;
		}
		
		/**
		 * Returns the character position of the last character (the semicolon) of the last
		 * property declaration at direct class body depth. Use this as an insertion point
		 * when appending a new property after all existing ones.
		 * Returns null when the class contains no property declarations.
		 * @return int|null Zero-based character index of the final semicolon, or null
		 */
		public function getLastPropertyEndPos(): ?int {
			$classOpen = $this->getClassOpeningBracePosition();
			
			if ($classOpen === null) {
				return null;
			}
			
			// Restrict the search to the class body
			$classBody = substr($this->content, $classOpen);
			
			if (!preg_match_all('/^\s*(?:protected|private|public)\s+[^;\r\n]*;/m', $classBody, $matches, PREG_OFFSET_CAPTURE)) {
				return null;
			}
			
			// Walk matches in reverse to find the last one at direct class member depth (depth 1)
			foreach (array_reverse($matches[0]) as $match) {
				if ($this->getDepthAt($classOpen + $match[1]) === 1) {
					return $classOpen + $match[1] + strlen($match[0]) - 1;
				}
			}
			
			return null;
		}
	
		/**
		 * Detects the indentation string used by the first property or method declaration
		 * found in the source. Falls back to a single tab when the file contains no
		 * recognisable declarations, which covers the edge case of an empty class body.
		 * @return string Indentation string, e.g. "\t" or "    " (four spaces)
		 */
		public function getMemberIndentation(): string {
			if (preg_match('/^([ \t]+)(?:protected|private|public|function)/m', $this->content, $indentMatch) !== 1) {
				// No indented declaration found — default to a single tab
				return "\t";
			}
			
			return $indentMatch[1];
		}
		
		/**
		 * Returns the whitespace (spaces or tabs) that precedes the class keyword.
		 * Used by PhpClassEditor to correctly indent inserted snippets when the class
		 * itself is indented (e.g. a tab-indented namespace convention).
		 * Returns an empty string when the class is declared at column zero.
		 * @return string Indentation prefix of the class declaration line
		 */
		public function getClassIndentation(): string {
			if (preg_match('/^([ \t]*)class\s+\w/m', $this->content, $match) !== 1) {
				return '';
			}
			
			return $match[1];
		}
		
		/**
		 * Returns the character position of the opening brace "{" of the class declaration.
		 * This is used as the anchor for all class-body searches, since properties and
		 * methods only make sense relative to this boundary.
		 * Returns null when no class declaration is found in the source.
		 * @return int|null Zero-based character index of the class opening brace, or null
		 */
		public function getClassOpeningBracePosition(): ?int {
			if (
				!preg_match(
					'/class\s+\w+(?:\s+extends\s+\w+)?(?:\s+implements\s+[\w\s,]+)?\s*\{/s',
					$this->content,
					$match,
					PREG_OFFSET_CAPTURE
				)
			) {
				return null;
			}
			
			// The brace is the last character of the matched string
			return $match[0][1] + strlen($match[0][0]) - 1;
		}
		
		/**
		 * Returns the character position of the closing brace "}" that ends the class body.
		 * Returns null when the class opening brace cannot be located or the brace
		 * structure is malformed (e.g. unclosed blocks).
		 * @return int|null Zero-based character index of the class closing brace, or null
		 */
		public function getClassClosingBracePosition(): ?int {
			$openingBrace = $this->getClassOpeningBracePosition();
			
			if ($openingBrace === null) {
				return null;
			}
			
			return $this->findClosingBrace($openingBrace);
		}
		
		/**
		 * Returns the character position of the first character on the line that
		 * contains the class closing brace.
		 * @return int|null Zero-based character index of the start of the class
		 *                  closing-brace line, or null when the class closing brace
		 *                  cannot be located.
		 */
		public function getClassClosingBraceLineStartPosition(): ?int {
			$closingBrace = $this->getClassClosingBracePosition();
			
			if ($closingBrace === null) {
				return null;
			}
			
			$lineStart = strrpos(substr($this->content, 0, $closingBrace), "\n");
			return $lineStart !== false ? $lineStart + 1 : 0;
		}
		
		// =====================================================================================
		// Helpers
		// =====================================================================================
		
		/**
		 * Returns the brace nesting depth at a given character position.
		 * Depth 0 means the position is outside all braces (file scope).
		 * Depth 1 means directly inside the class body.
		 * Depth 2 means inside a method body, and so on.
		 *
		 * Note: brace characters inside string literals or comments are counted as-is.
		 * This is acceptable for well-formed entity source files where such edge cases
		 * do not arise in practice.
		 * @param int $pos Zero-based character position to measure depth at
		 * @return int Brace depth at $pos
		 */
		private function getDepthAt(int $pos): int {
			$depth = 0;
			
			for ($i = 0; $i < $pos; $i++) {
				if ($this->content[$i] === '{') {
					$depth++;
				} elseif ($this->content[$i] === '}') {
					$depth--;
				}
			}
			
			return $depth;
		}
		
		/**
		 * Scans forward from the opening brace at $openBracePos and returns the position
		 * of the matching closing brace, accounting for arbitrarily nested brace pairs.
		 * Returns null when the end of the string is reached without finding a match,
		 * which indicates malformed source (unclosed block).
		 * @param int $openBracePos Zero-based character index of the "{" to match
		 * @return int|null Zero-based character index of the matching "}", or null
		 */
		private function findClosingBrace(int $openBracePos): ?int {
			$offset = $openBracePos + 1;
			$braceLevel = 1;
			$length = strlen($this->content);
			
			while ($offset < $length) {
				if ($this->content[$offset] === '{') {
					// Nested block opens — go one level deeper
					$braceLevel++;
				} elseif ($this->content[$offset] === '}') {
					$braceLevel--;
					
					// Back to zero means we found the brace that matches $openBracePos
					if ($braceLevel === 0) {
						return $offset;
					}
				}
				
				$offset++;
			}
			
			// Reached end of string without finding a match — malformed source
			return null;
		}
		
		/**
		 * Builds a regex pattern that matches a method declaration for the given name.
		 * The pattern requires at least one whitespace or newline character before the
		 * optional visibility keyword so that partial name matches (e.g. "set" matching
		 * "setUp") are not returned. Matching is case-insensitive to handle both
		 * conventional and unconventionally-cased method names.
		 * @param string $name Unqualified method name to match, e.g. "getId"
		 * @return string PCRE pattern string (including delimiters)
		 */
		private function getMethodPattern(string $name): string {
			return '/[\r\n\s]+((?:public|private|protected)\s+)?function\s+' . preg_quote($name, '/') . '\s*\(/i';
		}
	}