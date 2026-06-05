<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\ObjectQuel\Sculpt\SculptTypes;
	
	/**
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
		
		public const string INDENT = "\t";
		
		/**
		 * Inserts a use statement into the class source if not already present.
		 * Inserts after the last existing use statement, preserving its indentation.
		 * Falls back to inserting after the namespace declaration when no use statements exist yet.
		 * Has no effect if the import is already present.
		 * @param string $content Class file content
		 * @param string $import Fully-qualified import string, e.g. "use Foo\Bar;"
		 * @return string Updated content with the import spliced in
		 */
		public static function addUseStatement(string $content, string $import): string {
			if (str_contains($content, $import)) {
				return $content;
			}
			
			$analyser = new PhpClassAnalyser($content);
			$lastUseEnd = $analyser->getLastUseClauseEndPos();
			
			if ($lastUseEnd !== null) {
				// Reproduce the indentation of the last use statement for the new line
				$lineStart = strrpos($content, "\n", $lastUseEnd - strlen($content)) + 1;
				$useIndent = substr($content, $lineStart, strspn($content, " \t", $lineStart));
				return substr($content, 0, $lastUseEnd + 1) . "\n" . $useIndent . $import . substr($content, $lastUseEnd + 1);
			}
			
			// No use statements yet — insert after the namespace declaration
			$namespaceEnd = $analyser->getNamespaceEndPos();
			
			if ($namespaceEnd !== null) {
				return substr($content, 0, $namespaceEnd + 1) . "\n" . $import . substr($content, $namespaceEnd + 1);
			}
			
			// No namespace either — return unchanged rather than corrupting the file
			return $content;
		}
		
		/**
		 * Splices a property snippet into the class source.
		 * Inserts after the last existing property declaration, or after the class opening
		 * brace when no properties exist yet.
		 * @param string $content Class file content
		 * @param string $snippet Fully-formed property snippet to insert (docblock + declaration)
		 * @return string Updated content with the property spliced in
		 */
		public static function addProperty(string $content, string $snippet): string {
			$analyser = new PhpClassAnalyser($content);
			
			// Prefer inserting after the last property so new properties group together;
			// fall back to the class opening brace for an empty class body
			$insertPos = $analyser->getLastPropertyEndPos() ?? $analyser->getClassOpeningBracePosition();
			
			if ($insertPos === null) {
				return $content;
			}
			
			// Determine indentation
			$classIndent = $analyser->getClassIndentation();
			
			// Strip the class-level prefix to get the single-level indent unit
			$indented = self::indentSnippet($snippet, $classIndent);
			return substr($content, 0, $insertPos + 1) . $indented . substr($content, $insertPos + 1);
		}
		
		/**
		 * Splices a method snippet into the class source before the class closing brace.
		 * The snippet is expected to use \t per indent level; addMethod re-indents it
		 * to match the indentation style detected in the file.
		 * @param string $content Class file content
		 * @param string $snippet Fully-formed method snippet to insert (zero-based \t indentation)
		 * @return string Updated content with the method spliced in
		 */
		public static function addMethod(string $content, string $snippet): string {
			$analyser = new PhpClassAnalyser($content);
			$insertPos = $analyser->getClassClosingBraceLineStartPosition();
			
			if ($insertPos === null) {
				return $content;
			}
			
			// Determine indentation
			$classIndent = $analyser->getClassIndentation();
			
			// Strip the class-level prefix to get the single-level indent unit
			$indented = self::indentSnippet($snippet, $classIndent);
			return substr($content, 0, $insertPos) . rtrim($indented, "\n") . "\n" . substr($content, $insertPos);
		}
		
		/**
		 * Re-indents a snippet from canonical single-\t-per-level to the target indentation.
		 * Each leading \t on a line is replaced by one $unit. The $classIndent prefix is
		 * prepended to every line so members land at the correct depth even when the class
		 * itself is indented (e.g. a tab-indented namespace convention).
		 * Blank lines are preserved as-is.
		 * @param string $snippet   Snippet using \t as the indent unit
		 * @param string $classIndent Whitespace prefix of the class declaration line
		 * @return string Re-indented snippet
		 */
		private static function indentSnippet(string $snippet, string $classIndent = ''): string {
			$lines = explode("\n", $snippet);
			$result = [];
			
			foreach ($lines as $line) {
				// Preserve blank lines as-is
				if ($line === '') {
					$result[] = $line;
					continue;
				}
				
				// Count and strip leading tabs
				$tabCount = strlen($line) - strlen(ltrim($line, "\t"));
				$rest = substr($line, $tabCount);
				
				// Member level = classIndent + one unit; each extra \t adds another unit
				$result[] = $classIndent . str_repeat(self::INDENT, $tabCount + 1) . $rest;
			}
			
			return implode("\n", $result);
		}
		
		/**
		 * Modifies existing constructor to add collection initialization statements
		 * @param string $content Entity file content
		 * @param array<int, PropertyDefinition> $inverseOfProperties Collections to initialize
		 * @return string Updated content with modified constructor
		 */
		public static function updateExistingConstructor(string $content, array $inverseOfProperties): string {
			$analyser = new PhpClassAnalyser($content);
			$indent = $analyser->getMemberIndentation();
			
			// Find the start of the constructor
			$openBracePos = $analyser->getMethodBodyStartPos("__construct");
			
			// getMethodBodyStartPos() returns null when no constructor exists — should not happen
			if ($openBracePos === null) {
				return $content;
			}
			
			// Walk forward from the opening brace to find the matching closing brace
			$constructorEnd = $analyser->getMethodEndPos("__construct");
			
			// Every constructor has a closing brace, but findClosingBrace can in theory
			// return null, so this test is here. For PHPStan.
			if ($constructorEnd === null) {
				return $content;
			}
			
			// Extract constructor body
			$constructorBody = substr(
				$content,
				$openBracePos,
				$constructorEnd - $openBracePos
			);
			
			// Build initialization statements for any collections not already initialized
			$initCode = self::generateCollectionInitializations($constructorBody, $inverseOfProperties, $analyser->getMemberIndentation());
			
			// Insert the new statements immediately before the closing brace
			if (!empty($initCode)) {
				return substr($content, 0, $constructorEnd) . $initCode . "\n" . $indent . substr($content, $constructorEnd);
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
			$analyser = new PhpClassAnalyser($content);
			$generator = new PhpClassGenerator();
			
			// Determine input position
			$insertPos = $analyser->getLastPropertyEndPos() ?? $analyser->getClassOpeningBracePosition();
			
			if ($insertPos === null) {
				return $content;
			}
			
			// Constructor
			$indented = self::indentSnippet(
				$generator->generateConstructor($inverseOfProperties),
				$analyser->getClassIndentation()
			);
			
			return
				substr($content, 0, $insertPos + 1)
				. "\n"
				. $indented
				. substr($content, $insertPos + 1);
		}
		
		/**
		 * Generates collection initialization code for constructor body
		 * @param string $constructorBody
		 * @param array<int, PropertyDefinition> $inverseOfProperties Collections needing initialization
		 * @param string $indent
		 * @return string Initialization code statements
		 */
		public static function generateCollectionInitializations(
			string $constructorBody,
			array $inverseOfProperties,
			string $indent
		): string {
			$initCode = '';
			
			foreach ($inverseOfProperties as $property) {
				$propertyName = $property['name'];
				
				$pattern = '/\$this->' . preg_quote($propertyName, '/') . '\s*=\s*new\s+Collection\s*\(/';
				
				if (preg_match($pattern, $constructorBody)) {
					continue;
				}
				
				$initCode .=
					"\n"
					. $indent
					. "\t"
					. '$this->'
					. $propertyName
					. ' = new Collection();';
			}
			
			return $initCode;
		}
	}