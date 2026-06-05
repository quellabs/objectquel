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