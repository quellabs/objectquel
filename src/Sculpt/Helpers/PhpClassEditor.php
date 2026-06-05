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
			
			return substr($content, 0, $insertPos + 1) . $snippet . substr($content, $insertPos + 1);
		}
		
		/**
		 * Splices a method snippet into the class source before the class closing brace.
		 * @param string $content Class file content
		 * @param string $snippet Fully-formed method snippet to insert
		 * @return string Updated content with the method spliced in
		 */
		public static function addMethod(string $content, string $snippet): string {
			$closingBrace = (new PhpClassAnalyser($content))->getClassClosingBracePosition();
			
			if ($closingBrace === null) {
				return $content;
			}
			
			return substr($content, 0, $closingBrace) . $snippet . "\n" . substr($content, $closingBrace);
		}
		
		/**
		 * Modifies existing constructor to add collection initialization statements
		 * @param string $content Entity file content
		 * @param array<int, PropertyDefinition> $inverseOfProperties Collections to initialize
		 * @return string Updated content with modified constructor
		 */
		public static function updateExistingConstructor(string $content, array $inverseOfProperties): string {
			$analyser = new PhpClassAnalyser($content);
			$indent = $analyser->getIndentation();
			
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
			$initCode = self::generateCollectionInitializations($constructorBody, $inverseOfProperties, $analyser->getIndentation());
			
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
			// Detect indentation from existing content
			$analyser = new PHpClassAnalyser($content);
			$indent = $analyser->getIndentation();
			$indent2 = $indent . "\t";
			
			// Create constructor code
			$constructorCode = "\n{$indent}/**\n{$indent} * Constructor to initialize collections\n{$indent} */\n{$indent}public function __construct() {";
			
			foreach ($inverseOfProperties as $property) {
				$propertyName = $property['name'];
				$constructorCode .= "\n{$indent2}\$this->{$propertyName} = new Collection();";
			}
			
			$constructorCode .= "\n{$indent}}\n\n";
			
			// Find the best insertion point — after the last property, or after the class opening brace
			$insertPosition = $analyser->getLastPropertyEndPos();
			
			if ($insertPosition === null) {
				$insertPosition = $analyser->getClassOpeningBracePosition();
			}
			
			return substr($content, 0, $insertPosition + 1) . "\n" . $constructorCode . substr($content, $insertPosition + 1);
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