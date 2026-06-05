<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	class PhpClassAnalyser {
		
		private string $content;
		
		public function __construct(string $content) {
			$this->content = $content;
		}
		
		/**
		 * Read file contents
		 * @param string $filename
		 * @return PhpClassAnalyser
		 */
		public static function createFromFile(string $filename): PhpClassAnalyser {
			$content = file_get_contents($filename);
			
			if ($content === false) {
				throw new \RuntimeException("Unable to read file: {$filename}");
			}
			
			return new PhpClassAnalyser($content);
		}
		
		/**
		 * Checks if the method exists in the class
		 * @return bool True if method found
		 */
		public function hasMethod(string $name): bool {
			return preg_match($this->getMethodPattern($name), $this->content) === 1;
		}
		
		public function hasUseClause(string $name): bool {
			return in_array(
				$name,
				$this->getUseClauses(),
				true
			);
		}
		
		/**
		 * Checks if the property exists in the class
		 * @return bool True if property found
		 */
		public function hasProperty(string $name): bool {
			$nameQuoted = preg_quote($name, '/');
			$pattern = '/^\s*(?:protected|private|public)\s+[^;]*\$' . $nameQuoted . '\b[^;]*;/m';
			return preg_match($pattern, $this->content) === 1;
		}
		
		public function getUseClauses(): array {
			preg_match_all('/^\s*use\s+([^;\r\n]+);$/m', $this->content, $matches);
			return $matches[1];
		}
		
		/**
		 * Returns the character position of the last character of the last use statement,
		 * i.e. the position of its closing semicolon. Returns null when no use statements exist.
		 * @return int|null Character position of the semicolon ending the last use statement
		 */
		public function getLastUseClauseEndPos(): ?int {
			if (!preg_match_all('/^\s*use\s+[^;\r\n]+;/m', $this->content, $matches, PREG_OFFSET_CAPTURE)) {
				return null;
			}
			
			$lastMatch = end($matches[0]);
			
			// Offset + length - 1 lands on the semicolon itself
			return $lastMatch[1] + strlen($lastMatch[0]) - 1;
		}
		
		/**
		 * Returns the character position of the semicolon ending the namespace declaration.
		 * Returns null when no namespace statement exists.
		 * @return int|null Character position of the semicolon ending the namespace declaration
		 */
		public function getNamespaceEndPos(): ?int {
			if (!preg_match('/^namespace\s+[^;\r\n]+;/m', $this->content, $match, PREG_OFFSET_CAPTURE)) {
				return null;
			}
			
			// Offset + length - 1 lands on the semicolon itself
			return $match[0][1] + strlen($match[0][0]) - 1;
		}
		
		/**
		 * Locates the character position where constructor declaration begins
		 * @return int|null Character position of constructor start, or null if not found
		 */
		public function getMethodStartPos(string $name): ?int {
			if (!preg_match($this->getMethodPattern($name), $this->content, $methodMatch, PREG_OFFSET_CAPTURE)) {
				return null;
			}
			
			return $methodMatch[0][1];
		}
		
		/**
		 * Locates the character position where constructor declaration begins
		 * @return int|null Character position of constructor start, or null if not found
		 */
		public function getMethodEndPos(string $name): ?int {
			$startPos = $this->getMethodStartPos($name);
			
			if ($startPos === null) {
				return null;
			}
			
			$openBracePos = strpos($this->content, '{', $startPos);
			
			if ($openBracePos === false) {
				return null;
			}
			
			return $this->findClosingBrace($openBracePos);
		}
		
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
		
		public function getMethodBodyStartPos(string $name): ?int {
			$bracePos  = $this->getMethodOpeningBracePos($name);
			
			if ($bracePos === null) {
				return null;
			}
			
			return $bracePos + 1;
		}
		
		public function getPropertyStartPos(string $name): ?int {
			$nameQuoted = preg_quote($name, '/');
			
			if (
				!preg_match(
					'/^\s*(?:protected|private|public)\s+[^;]*\$'
					. $nameQuoted
					. '\b[^;]*;/m',
					$this->content,
					$propertyMatch,
					PREG_OFFSET_CAPTURE
				)
			) {
				return null;
			}
			
			return $propertyMatch[0][1];
		}
		
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
		
		public function getLastPropertyEndPos(): ?int {
			preg_match_all(
				'/^\s*(?:protected|private|public)\s+[^;]*;/m',
				$this->content,
				$matches,
				PREG_OFFSET_CAPTURE
			);
			
			if (empty($matches[0])) {
				return null;
			}
			
			$lastMatch = end($matches[0]);
			
			return $lastMatch[1] + strlen($lastMatch[0]) - 1;
		}
		
		public function getIndentation(): string {
			if (preg_match('/^([ \t]+)(?:protected|private|public|function)/m', $this->content, $indentMatch) !== 1) {
				return "\t";
			}
			
			return $indentMatch[1];
		}
		
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
			
			return $match[0][1] + strlen($match[0][0]) - 1;
		}
		
		public function getClassClosingBracePosition(): ?int {
			$openingBrace = $this->getClassOpeningBracePosition();
			
			if ($openingBrace === null) {
				return null;
			}
			
			return $this->findClosingBrace($openingBrace);
		}
		
		// =====================================================================================
		// Helpers
		// =====================================================================================
		
		/**
		 * Finds matching closing brace for an opening brace at given position
		 * @param int $openBracePos Character index of opening brace
		 * @return int|null Character index of matching closing brace, or null if not found
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
		
		private function getMethodPattern(string $name): string {
			return '/[\r\n\s]+((?:public|private|protected)\s+)?function\s+' . preg_quote($name, '/') . '\s*\(/i';
		}
	}