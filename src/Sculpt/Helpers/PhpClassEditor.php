<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\ObjectQuel\Sculpt\SculptTypes;/**
	 * Generic PHP class source editor.
	 *
	 * This class provides structural operations for modifying an existing PHP class
	 * without embedding domain-specific knowledge. It supports:
	 *
	 * - use statement insertion
	 * - property insertion
	 * - method insertion
	 * - constructor creation
	 * - constructor body extension
	 * - existence checks
	 *
	 * Assumptions:
	 *
	 * - One class per file
	 * - No code after the class closing brace
	 * - Existing formatting should be preserved where possible
	 *
	 * @phpstan-import-type BaseProperty from SculptTypes
	 * @phpstan-import-type EnumProperty from SculptTypes
	 * @phpstan-import-type RelationProperty from SculptTypes
	 * @phpstan-import-type PropertyDefinition from SculptTypes
	 */
	class PhpClassEditor {
		
		public static function parseClassContent(string $content): false|array {
			// Locate class declaration with optional extends/implements
			if (!preg_match('/class\s+(\w+)(?:\s+extends\s+\w+)?(?:\s+implements\s+[\w\s,]+)?\s*\{/s', $content, $classMatch, PREG_OFFSET_CAPTURE)) {
				return false;
			}
			
			// $classStartPos points to the first character inside the class body (after the opening brace)
			$classStartPos = (int)$classMatch[0][1] + strlen($classMatch[0][0]);
			
			// Use the last closing brace in the file as the class end — assumes no code after the class
			$lastBracePos = strrpos($content, '}');
			
			if ($lastBracePos === false) {
				return false;
			}
			
			// Extract everything between the class opening brace and its closing brace
			$classBody = substr($content, $classStartPos, $lastBracePos - $classStartPos);
			
			// Find where methods begin — properties occupy everything before the first method declaration
			$methodPattern = '/\s*(public|protected|private)?\s+function\s+\w+/';
			
			if (preg_match($methodPattern, $classBody, $methodMatch, PREG_OFFSET_CAPTURE)) {
				$firstMethodPos = $methodMatch[0][1];
				
				// If there's a docblock immediately before the method, include it in the methods section
				// rather than the properties section to keep the split clean
				$potentialDocBlockStart = strrpos(substr($classBody, 0, $firstMethodPos), '/**');
				
				if ($potentialDocBlockStart !== false && ($firstMethodPos - $potentialDocBlockStart) < 100) {
					$firstMethodPos = $potentialDocBlockStart;
				}
				
				$propertiesSection = trim(substr($classBody, 0, $firstMethodPos));
				$propertiesRaw = substr($classBody, 0, $firstMethodPos);
				$methodsSection = trim(substr($classBody, $firstMethodPos));
			} else {
				// No methods found — entire body is properties
				$propertiesSection = trim($classBody);
				$propertiesRaw = $classBody;
				$methodsSection = '';
			}
			
			return [
				'header'        => substr($content, 0, $classStartPos),
				'properties'    => $propertiesSection,
				'propertiesRaw' => $propertiesRaw,
				'methods'       => $methodsSection,
				'footer'        => substr($content, $lastBracePos)
			];
		}
		
		/**
		 * Checks if a constructor method exists in the class
		 * @param string $content Entity file content
		 * @return bool True if __construct method found
		 */
		public static function constructorExists(string $content): bool {
			return preg_match('/[\r\n\s]+((?:public|private|protected)\s+)?function\s+__construct\s*\(/i', $content) === 1;
		}
		
		/**
		 * Locates the character position where constructor declaration begins
		 * @param string $content Complete PHP file content
		 * @return int|null Character position of constructor start, or null if not found
		 */
		public static function getConstructorStartPos(string $content): ?int {
			if (!preg_match('/[\r\n\s]+((?:public|private|protected)\s+)?function\s+__construct\s*\(/i', $content, $constructorMatch, PREG_OFFSET_CAPTURE)) {
				return null;
			}
			
			return $constructorMatch[0][1];
		}
		
		/**
		 * Finds matching closing brace for an opening brace at given position
		 * @param string $content Source code to search
		 * @param int $openBracePos Character index of opening brace
		 * @return int|null Character index of matching closing brace, or null if not found
		 */
		public static function findClosingBrace(string $content, int $openBracePos): ?int {
			$offset = $openBracePos + 1;
			$braceLevel = 1;
			$length = strlen($content);
			
			while ($offset < $length) {
				if ($content[$offset] === '{') {
					// Nested block opens — go one level deeper
					$braceLevel++;
				} elseif ($content[$offset] === '}') {
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
		 * Determines optimal position to insert a new constructor
		 * @param string $content Complete PHP file content
		 * @return int|null Character position for insertion, or null if not found
		 */
		public static function findConstructorInsertPosition(string $content): ?int {
			// Use parseClassContent to isolate the properties section — this prevents the
			// property regex from matching $variable assignments inside method bodies
			$parsed = self::parseClassContent($content);
			
			if ($parsed === false) {
				return null;
			}
			
			// Use propertiesRaw (untrimmed) so offsets map directly to full-content positions
			$propertiesRaw = $parsed['propertiesRaw'];
			$headerLength = strlen($parsed['header']);
			
			// Find the last semicolon in the raw properties section — insert after it
			$lastSemicolon = strrpos($propertiesRaw, ';');
			
			if ($lastSemicolon !== false) {
				// Convert properties-section offset to full-content offset
				return $headerLength + $lastSemicolon + 1;
			}
			
			// No properties found — insert immediately after the class opening brace
			if (!preg_match('/class[\s\r\n]+[^{]+\{/i', $content, $classMatch, PREG_OFFSET_CAPTURE)) {
				return null;
			}
			
			$classOpenBracePos = strpos($content, '{', $classMatch[0][1]);
			return $classOpenBracePos !== false ? $classOpenBracePos + 1 : null;
		}
		
		/**
		 * Modifies existing constructor to add collection initialization statements
		 * @param string $content Entity file content
		 * @param array<int, PropertyDefinition> $inverseOfProperties Collections to initialize
		 * @return string Updated content with modified constructor
		 */
		public static function updateExistingConstructor(string $content, array $inverseOfProperties): string {
			// Find the start of the constructor
			$constructorStart = self::getConstructorStartPos($content);
			
			// getConstructorStartPos() returns null when no constructor exists — should not happen
			// here since updateExistingConstructor() is only called after constructorExists() returns
			// true, but the guard satisfies PHPStan's type narrowing
			if ($constructorStart === null) {
				return $content;
			}
			
			// Find the opening brace of the constructor body
			$openBracePos = strpos($content, '{', $constructorStart);
			
			// strpos can in theory return null, but in practice will never do that because
			// every constructor has an opening brace. Still the test is present for PHPStan.
			if ($openBracePos === false) {
				return $content;
			}
			
			// Walk forward from the opening brace to find the matching closing brace
			$constructorEnd = self::findClosingBrace($content, $openBracePos);
			
			// Every constructor has a closing brace, but findClosingBrace can in theory
			// return null, so this test is here. For PHPStan.
			if ($constructorEnd === null) {
				return $content;
			}
			
			// Build initialization statements for any collections not already initialized
			$initCode = self::generateCollectionInitializations($content, $inverseOfProperties);
			
			// Insert the new statements immediately before the closing brace
			if (!empty($initCode)) {
				return substr($content, 0, $constructorEnd) . $initCode . "\n\t" . substr($content, $constructorEnd);
			}
			
			// Return content
			return $content;
		}
		
		/**
		 * Creates a new constructor with collection initializations
		 * @param string $content Entity file content
		 * @param array<int, PropertyDefinition> $inverseOfProperties Collections to initialize
		 * @return string Updated content with new constructor
		 */
		public static function addNewConstructor(string $content, array $inverseOfProperties): string {
			// Detect indentation from existing content
			$indent = '\t';
			if (preg_match('/^([ \t]+)(?:protected|private|public|function)/m', $content, $indentMatch)) {
				$indent = $indentMatch[1];
			}
			$indent2 = $indent . "\t";
			
			$constructorCode = "\n{$indent}/**\n{$indent} * Constructor to initialize collections\n{$indent} */\n{$indent}public static function __construct() {";
			
			foreach ($inverseOfProperties as $property) {
				$propertyName = $property['name'];
				$constructorCode .= "\n{$indent2}\self::{$propertyName} = new Collection();";
			}
			
			$constructorCode .= "\n{$indent}}\n";
			
			// Find the best insertion point — after the last property, or after the class opening brace
			$insertPosition = self::findConstructorInsertPosition($content);
			
			if ($insertPosition !== null) {
				return substr($content, 0, $insertPosition) . "\n" . $constructorCode . substr($content, $insertPosition);
			}
			
			return $content;
		}
		
		/**
		 * Generates collection initialization code for constructor body
		 * @param string $content Entity file content
		 * @param array<int, PropertyDefinition> $inverseOfProperties Collections needing initialization
		 * @return string Initialization code statements
		 */
		public static function generateCollectionInitializations(string $content, array $inverseOfProperties): string {
			$initCode = '';
			
			foreach ($inverseOfProperties as $property) {
				$propertyName = $property['name'];
				
				// Skip properties already assigned a Collection instance — avoids duplicating the line
				if (!preg_match('/\self::' . preg_quote($propertyName, '/') . '\s*=\s*new\s+Collection\(\)/s', $content)) {
					$initCode .= "\n\t\t\self::{$propertyName} = new Collection();";
				}
			}
			
			return $initCode;
		}
	}