<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\Sculpt\SculptTypes;
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	use Quellabs\ObjectQuel\Sculpt\Commands\MakeEntityCommand;
	use Quellabs\Support\StringInflector;
	
	/**
	 * Handles creating new entity classes with properties, relationships, and accessors,
	 * as well as updating existing entities with new properties while preserving existing code.
	 * @phpstan-import-type BaseProperty from SculptTypes
	 * @phpstan-import-type EnumProperty from SculptTypes
	 * @phpstan-import-type RelationProperty from SculptTypes
	 * @phpstan-import-type PropertyDefinition from SculptTypes
	 *
	 * @phpstan-type IndexDefinition array{
	 *     type?: 'INDEX'|'UNIQUE'|'FULLTEXT',
	 *     name: string,
	 *     columns: array<int, string>
	 * }
	 *
	 * @phpstan-type ParsedClassContent array{
	 *     header: string,
	 *     properties: string,
	 *     propertiesRaw: string,
	 *     methods: string,
	 *     footer: string
	 * }
	 */
	class EntityModifier {
		
		/** @var Configuration Application configuration for entity paths and namespaces */
		private Configuration $configuration;
		
		/**
		 * Constructor
		 * @param Configuration $configuration Application configuration instance
		 */
		public function __construct(Configuration $configuration) {
			$this->configuration = $configuration;
		}
		
		/**
		 * Checks if an entity file exists on disk
		 * @param string $entityName Entity class name (with or without "Entity" suffix)
		 * @return bool True if the entity file exists
		 */
		public function entityExists(string $entityName): bool {
			return file_exists($this->getEntityPath($entityName));
		}
		
		/**
		 * Constructs the full file path for an entity
		 * @param string $entityName Entity class name
		 * @return string Absolute path to the entity PHP file
		 */
		public function getEntityPath(string $entityName): string {
			return $this->configuration->getEntityPath() . '/' . $entityName . '.php';
		}
		
		/**
		 * Creates a new entity or updates an existing one based on file existence
		 * @param string $entityName Entity name without "Entity" suffix
		 * @param array<int, PropertyDefinition> $properties Property metadata with type, nullable, relationship info
		 * @param array<int, IndexDefinition> $indexes Optional index definitions (regular, unique, full-text)
		 * @return bool True if operation succeeded
		 */
		public function createOrUpdateEntity(string $entityName, array $properties, array $indexes = []): bool {
			// Check both naming conventions: ElephantEntity.php (default) and Elephant.php (renamed)
			$suffixedExists = $this->entityExists($entityName . "Entity");
			$bareExists = $this->entityExists($entityName);
			
			// Both files existing is an unresolvable ambiguity — bail out rather than silently pick one
			if ($suffixedExists && $bareExists) {
				throw new \RuntimeException(
					"Ambiguous entity: both '{$entityName}.php' and '{$entityName}Entity.php' exist. Remove one before proceeding."
				);
			}
			
			if ($suffixedExists) {
				return $this->updateEntity($entityName . "Entity", $properties);
			} elseif ($bareExists) {
				return $this->updateEntity($entityName, $properties);
			} else {
				return $this->createNewEntity($entityName, $properties, $indexes);
			}
		}
		
		/**
		 * Generates a complete entity class file from scratch
		 * @param string $entityName Entity name without "Entity" suffix
		 * @param array<int, PropertyDefinition> $properties Property definitions with metadata
		 * @param array<int, IndexDefinition> $indexes Optional index definitions (regular, unique, full-text)
		 * @return bool True if file was created successfully
		 */
		public function createNewEntity(string $entityName, array $properties, array $indexes = []): bool {
			$entityPath = $this->configuration->getEntityPath();
			
			// Ensure the entity directory exists before writing
			if (!is_dir($entityPath)) {
				mkdir($entityPath, 0755, true);
			}
			
			$content = $this->generateEntityContent($entityName, $properties, $indexes);
			return file_put_contents($this->getEntityPath($entityName . "Entity"), $content) !== false;
		}
		
		/**
		 * Updates an existing entity with new properties and methods
		 * @param string $entityName Entity name without "Entity" suffix
		 * @param array<int, PropertyDefinition> $properties New properties to add with full metadata
		 * @return bool True if file was updated successfully
		 */
		public function updateEntity(string $entityName, array $properties): bool {
			// Resolve the full file path for the given entity name (with or without "Entity" suffix)
			$filePath = $this->getEntityPath($entityName);
			
			// Read the existing file content
			$content = file_get_contents($filePath);
			
			if ($content === false) {
				return false;
			}
			
			// Split the file into header, properties section, methods section, and footer
			$classContent = PhpClassEditor::parseClassContent($content);
			
			if (!$classContent) {
				return false;
			}
			
			// InverseOf properties need collection initialization in the constructor
			$inverseOfProperties = array_filter($properties, fn($p) => ($p['relationshipType'] ?? null) === 'InverseOf');
			
			$updatedContent = $content;
			
			// Inject any missing use statements required by the new properties
			$updatedContent = $this->ensureRequiredImports($updatedContent, $properties);
			
			if (!empty($inverseOfProperties)) {
				$updatedContent = $this->updateConstructor($updatedContent, $inverseOfProperties);
			}
			
			// Reparse after constructor changes, as insertion points may have shifted
			$reparsed = PhpClassEditor::parseClassContent($updatedContent);
			
			if ($reparsed === false) {
				return false;
			}
			
			$updatedContent = $this->insertProperties($reparsed, $properties);
			
			// Strip suffix before passing to insertGettersAndSetters, which uses the name for method bodies
			$bareName = str_ends_with($entityName, 'Entity') ? substr($entityName, 0, -6) : $entityName;
			$updatedContent = $this->insertGettersAndSetters($updatedContent, $properties, $bareName);
			return file_put_contents($filePath, $updatedContent) !== false;
		}
		
		/**
		 * Parses class file to identify structural sections
		 * @param string $content Complete entity file content
		 * @return ParsedClassContent|false Array with keys: header, properties, methods, footer; or false on parse error
		 */
		/**
		 * Ensures all use statements required by the given properties exist in the file.
		 * Injects any missing imports before the class declaration.
		 * @param string $content Entity file content
		 * @param array<int, PropertyDefinition> $properties Properties being added
		 * @return string Updated content with required imports
		 */
		protected function ensureRequiredImports(string $content, array $properties): string {
			$hasInverseOf = false;
			$hasRelationship = false;
			
			foreach ($properties as $property) {
				if (!isset($property['relationshipType'])) {
					continue;
				}
				
				$hasRelationship = true;
				
				if ($property['relationshipType'] === 'InverseOf') {
					$hasInverseOf = true;
				}
			}
			
			if (!$hasRelationship) {
				return $content;
			}
			
			// Required imports for InverseOf properties
			$needed = [];
			
			if ($hasInverseOf) {
				$needed[] = 'use Quellabs\\ObjectQuel\\Annotations\\Orm\\InverseOf;';
				$needed[] = 'use Quellabs\\ObjectQuel\\Collections\\Collection;';
				$needed[] = 'use Quellabs\\ObjectQuel\\Collections\\CollectionInterface;';
			}
			
			foreach ($needed as $import) {
				// Only inject if not already present
				if (str_contains($content, $import)) {
					continue;
				}
				
				// Insert after the last existing use statement, matching its indentation
				if (preg_match_all('/^(\\s*)use\\s+[^;\\r\\n]+;/m', $content, $useMatches, PREG_OFFSET_CAPTURE)) {
					$lastUseMatch = end($useMatches[0]);
					$lastUseIndent = end($useMatches[1])[0]; // capture group 1 = leading whitespace
					$insertPos = $lastUseMatch[1] + strlen($lastUseMatch[0]);
					$content = substr($content, 0, $insertPos) . "\n" . $lastUseIndent . $import . substr($content, $insertPos);
				} elseif (preg_match('/^namespace\\s+[^;\\r\\n]+;/m', $content, $nsMatch, PREG_OFFSET_CAPTURE)) {
					// No use statements yet — insert after namespace declaration
					$insertPos = $nsMatch[0][1] + strlen($nsMatch[0][0]);
					$content = substr($content, 0, $insertPos) . "\n" . $import . substr($content, $insertPos);
				}
			}
			
			return $content;
		}
		
		/**
		 * Inserts new property declarations into the class
		 * @param ParsedClassContent $classContent Parsed class sections from parseClassContent()
		 * @param array<int, PropertyDefinition> $properties Properties to add
		 * @return string Updated class content with new properties
		 */
		protected function insertProperties(array $classContent, array $properties): string {
			$propertyCode = $classContent['properties'];
			$newProperties = '';
			
			// Detect indentation from first property declaration in the existing code
			$indent = '\t';
			if (preg_match('/^([ \t]+)(?:\/\*\*|(protected|private|public))/m', $propertyCode, $indentMatch)) {
				$indent = $indentMatch[1];
			}
			
			foreach ($properties as $property) {
				$propertyName = $property['name'];
				
				// Skip if a declaration for this property already exists in the class body
				if (preg_match('/\s*(protected|private|public)\s+.*\$' . $propertyName . '\s*;/is', $propertyCode)) {
					continue;
				}
				
				// Relationship properties get a different docblock than plain column properties
				if (isset($property['relationshipType'])) {
					$docComment = $this->generateRelationshipDocComment($property);
				} else {
					$docComment = $this->generatePropertyDocComment($property);
				}
				
				$propertyDefinition = $this->generatePropertyDefinition($property);
				
				// Detect and strip the consistent indentation prefix from the docComment,
				// preserving internal spacing (e.g. " * " on annotation lines).
				// Find the leading whitespace of the second line (first " * " line) as the prefix.
				$docLines = explode("\n", $docComment);
				$docPrefix = '';
				foreach ($docLines as $docLine) {
					if (preg_match('/^([ \t]+)\*/', $docLine, $prefixMatch)) {
						$docPrefix = $prefixMatch[1];
						break;
					}
				}
				// Strip all but the last character of the prefix (keeps the space before *)
				$strippedPrefix = strlen($docPrefix) > 1 ? substr($docPrefix, 1) : $docPrefix;
				$cleanDoc = $strippedPrefix !== '' ? preg_replace('/^' . preg_quote($strippedPrefix, '/') . '/m', '', $docComment) : $docComment;
				$newProperties .= "\n\n{$indent}" . str_replace("\n", "\n{$indent}", $cleanDoc) . "\n{$indent}" . $propertyDefinition;
			}
			
			$updatedPropertyCode = $propertyCode . $newProperties;
			
			// Reassemble: header + updated properties + methods + closing brace
			// Use the raw (untrimmed) properties to preserve the original whitespace
			// between the class opening brace and the first property
			$headerRaw = rtrim($classContent['header']);
			$leadingWhitespace = $classContent['propertiesRaw'];
			preg_match('/^[\r\n]+/', $leadingWhitespace, $leadingMatch);
			$leading = $leadingMatch[0] ?? "\n";
			$methods = ltrim($classContent['methods'], "\r\n");
			return $headerRaw . $leading . $updatedPropertyCode . "\n\n{$indent}" . $methods . $classContent['footer'];
		}
		
		/**
		 * Inserts getter/setter methods and collection adder/remover methods
		 * @param string $content Current class content
		 * @param array<int, PropertyDefinition> $properties Properties needing accessors
		 * @param string $entityName Current entity name for relationship methods
		 * @return string Updated class content with new methods
		 */
		protected function insertGettersAndSetters(string $content, array $properties, string $entityName): string {
			$lastBracePos = strrpos($content, '}');
			if ($lastBracePos === false) {
				return $content;
			}
			
			$methodsToAdd = '';
			
			foreach ($properties as $property) {
				$getterName = 'get' . ucfirst($property['name']);
				$setterName = 'set' . ucfirst($property['name']);
				
				// InverseOf collections don't use get/set — they use add/remove instead
				if (isset($property['relationshipType']) && $property['relationshipType'] === 'InverseOf') {
					$singularName = StringInflector::singularize($property['name']);
					$addMethodName = 'add' . ucfirst($singularName);
					$removeMethodName = 'remove' . ucfirst($singularName);
					
					// Only generate each method if it doesn't already exist
					if (!preg_match('/function\s+' . $addMethodName . '\s*\(/i', $content)) {
						$methodsToAdd .= $this->generateCollectionAdder($property, $entityName);
					}
					
					if (!preg_match('/function\s+' . $removeMethodName . '\s*\(/i', $content)) {
						$methodsToAdd .= $this->generateCollectionRemover($property, $entityName);
					}
					
					continue;
				}
				
				// Generate getter if not already present
				if (!preg_match('/function\s+' . $getterName . '\s*\(/i', $content)) {
					$methodsToAdd .= $this->generateGetter($property);
				}
				
				// Readonly properties get no setter
				if (!($property['readonly'] ?? false) && !preg_match('/function\s+' . $setterName . '\s*\(/i', $content)) {
					$methodsToAdd .= $this->generateSetter($property);
				}
			}
			
			// Splice new methods in before the final closing brace of the class
			return substr($content, 0, $lastBracePos) . $methodsToAdd . "\n}" . substr($content, $lastBracePos + 1);
		}
		
		/**
		 * Generates complete entity class file content
		 * @param string $entityName Entity name without "Entity" suffix
		 * @param array<int, PropertyDefinition> $properties All properties for this entity
		 * @param array<int, IndexDefinition> $indexes Optional index definitions. Each entry: ['type' => 'INDEX'|'UNIQUE'|'FULLTEXT', 'name' => '...', 'columns' => [...]]
		 * @return string Complete PHP class file content
		 */
		protected function generateEntityContent(string $entityName, array $properties, array $indexes = []): string {
			$namespace = $this->configuration->getEntityNameSpace();
			$content = "<?php\n\n    namespace {$namespace};\n";
			
			// Use statements for ORM annotations and collections
			$content .= "\n";
			$content .= "    use Quellabs\\ObjectQuel\\Annotations\\Orm\\Table;\n";
			$content .= "    use Quellabs\\ObjectQuel\\Annotations\\Orm\\Column;\n";
			$content .= "    use Quellabs\\ObjectQuel\\Annotations\\Orm\\Index;\n";
			$content .= "    use Quellabs\\ObjectQuel\\Annotations\\Orm\\UniqueIndex;\n";
			$content .= "    use Quellabs\\ObjectQuel\\Annotations\\Orm\\FullTextIndex;\n";
			$content .= "    use Quellabs\\ObjectQuel\\Annotations\\Orm\\PrimaryKeyStrategy;\n";
			$content .= "    use Quellabs\\ObjectQuel\\Annotations\\Orm\\OneToOne;\n";
			$content .= "    use Quellabs\\ObjectQuel\\Annotations\\Orm\\InverseOf;\n";
			$content .= "    use Quellabs\\ObjectQuel\\Annotations\\Orm\\ManyToOne;\n";
			$content .= "    use Quellabs\\ObjectQuel\\Collections\\Collection;\n";
			$content .= "    use Quellabs\\ObjectQuel\\Collections\\CollectionInterface;\n";
			
			// Derive the table name: pluralize the entity name, then convert to snake_case
			// e.g. "ProductCategory" → "product_categories"
			$tableNamePlural = StringInflector::pluralize($entityName);
			$tableName = StringInflector::snakeCase($tableNamePlural);
			
			// Build class docblock — @Table first, then all index annotations
			$content .= "\n    /**\n";
			$content .= "     * @Orm\\Table(name=\"{$tableName}\")\n";
			
			foreach ($indexes as $index) {
				$indexName = $index['name'];
				$indexColumns = '{"' . implode('", "', $index['columns']) . '"}';
				$indexType = strtoupper($index['type'] ?? 'INDEX');
				
				// Map the index type string to its annotation class name
				$annotationClass = match ($indexType) {
					'UNIQUE' => 'UniqueIndex',
					'FULLTEXT' => 'FullTextIndex',
					default => 'Index',
				};
				
				$content .= "     * @Orm\\{$annotationClass}(name=\"{$indexName}\", columns={$indexColumns})\n";
			}
			
			$content .= "     */\n";
			$content .= "    class {$entityName}Entity {\n";
			
			// Every entity gets an auto-increment primary key
			$content .= "\n        /**\n";
			$content .= "         * @Orm\\Column(name=\"id\", type=\"integer\", unsigned=true, primary_key=true)\n";
			$content .= "         * @Orm\\PrimaryKeyStrategy(strategy=\"identity\")\n";
			$content .= "         */\n";
			$content .= "        protected ?int \$id = null;\n";
			
			// Check whether any property requires a constructor for collection initialization
			$hasInverseOf = false;
			foreach ($properties as $property) {
				if (isset($property['relationshipType']) && $property['relationshipType'] === 'InverseOf') {
					$hasInverseOf = true;
					break;
				}
			}
			
			// InverseOf properties must be initialized to an empty Collection in the constructor,
			// otherwise accessing the collection before it's set would cause a null-dereference
			if ($hasInverseOf) {
				$content .= "\n        /**\n         * Constructor to initialize collections\n         */\n";
				$content .= "        public function __construct() {\n";
				
				foreach ($properties as $property) {
					if (isset($property['relationshipType']) && $property['relationshipType'] === 'InverseOf') {
						$content .= "            \$this->{$property['name']} = new Collection();\n";
					}
				}
				
				$content .= "        }\n";
			}
			
			// Emit all property declarations with their ORM annotation docblocks
			foreach ($properties as $property) {
				$docComment = isset($property['relationshipType'])
					? $this->generateRelationshipDocComment($property)
					: $this->generatePropertyDocComment($property);
				
				$propertyDefinition = $this->generatePropertyDefinition($property);
				
				$content .= "\n        " . str_replace("\n       ", "\n        ", $docComment) . "\n";
				$content .= "        " . $propertyDefinition . "\n";
			}
			
			// Primary key getter — always present regardless of property list
			$content .= $this->generateGetter(['name' => 'id', 'type' => 'integer']);
			
			// Generate accessors for every declared property
			foreach ($properties as $property) {
				$readOnly = $property['readonly'] ?? false;
				
				// InverseOf collections expose add/remove methods rather than a single setter
				if (isset($property['relationshipType']) && $property['relationshipType'] === 'InverseOf') {
					$content .= $this->generateCollectionAdder($property, $entityName);
					$content .= $this->generateCollectionRemover($property, $entityName);
					continue;
				}
				
				$content .= $this->generateGetter($property);
				
				// Readonly properties intentionally have no setter
				if (!$readOnly) {
					$content .= $this->generateSetter($property);
				}
			}
			
			$content .= "    }\n";
			
			return $content;
		}
		
		/**
		 * Generates ORM Column annotation docblock for regular properties
		 * @param PropertyDefinition $property Property metadata (name, type, nullable, limit, precision, etc.)
		 * @return string PHPDoc comment with @Orm\Column annotation
		 */
		protected function generatePropertyDocComment(array $property): string {
			$nullable = $property['nullable'] ?? false;
			$type = $property['type'];
			
			// Column name follows snake_case convention regardless of the PHP property name
			$snakeCaseName = StringInflector::snakeCase($property['name']);
			
			$properties = [
				"name=\"{$snakeCaseName}\"",
				"type=\"{$type}\""
			];
			
			// Enum columns reference the backing PHP enum class
			if (!empty($property['enumType'])) {
				$properties[] = "enumType=" . ltrim($property['enumType'], "\\") . "::class";
			}
			
			// Optional column attributes — only emit attributes that were explicitly provided
			if (isset($property['limit']) && is_numeric($property['limit'])) {
				$properties[] = "limit={$property['limit']}";
			}
			
			if (isset($property['unsigned'])) {
				$properties[] = "unsigned=" . ($property['unsigned'] ? "true" : "false");
			}
			
			if (isset($property['precision'])) {
				$properties[] = "precision={$property['precision']}";
			}
			
			if (isset($property['scale'])) {
				$properties[] = "scale={$property['scale']}";
			}
			
			if ($nullable) {
				$properties[] = "nullable=true";
			}
			
			$propertiesString = implode(", ", $properties);
			return "/**\n         * @Orm\\Column({$propertiesString})\n         */";
		}
		
		/**
		 * Generates ORM relationship annotation docblock
		 * @phpstan-param RelationProperty $property
		 * @param array $property Relationship metadata (targetEntity, relation, referencedColumn, etc.)
		 * @return string PHPDoc comment with relationship annotation
		 */
		protected function generateRelationshipDocComment(array $property): string {
			$relationshipType = $property['relationshipType'];
			$targetEntity = $property['targetEntity'];
			
			$options = [];
			
			// relation identifies the property on the owning entity that points to this entity
			if (!empty($property['relation'])) {
				$options[] = "relation=\"{$property['relation']}\"";
			}
			
			// referencedColumn identifies the target property the FK points to
			if (!empty($property['referencedColumn'])) {
				$options[] = "referencedColumn=\"{$property['referencedColumn']}\"";
			}
			
			// Collections are fetched lazily to avoid loading the entire related set on access
			if ($relationshipType === 'InverseOf') {
				$options[] = "fetch=\"LAZY\"";
			}
			
			// The owning side is the one without relation — it holds the foreign key column
			$isOwningSide = empty($property['relation']);
			
			if ($isOwningSide) {
				// The column in the current table that stores the foreign key value
				if (!empty($property['localColumn'])) {
					$options[] = "localColumn=\"{$property['localColumn']}\"";
				}
			}
			
			$optionsStr = !empty($options) ? ', ' . implode(', ', $options) : '';
			$comment = "/**\n         * @Orm\\{$relationshipType}(targetEntity=\"{$targetEntity}Entity\"{$optionsStr})";
			
			// InverseOf gets an additional @var hint so IDEs know the collection's generic type
			if ($relationshipType === 'InverseOf') {
				$comment .= "\n         * @var CollectionInterface<{$targetEntity}Entity>";
			}
			
			$comment .= "\n         */";
			
			return $comment;
		}
		
		/**
		 * Resolves the PHP type string for a non-relationship property.
		 * Accepts only BaseProperty|EnumProperty so PHPStan can verify that
		 * enumType is present whenever type === 'enum'.
		 * @param BaseProperty|EnumProperty $property
		 * @return string PHP type string (e.g. 'int', 'string', '\App\Enum\Status')
		 */
		private function resolvePhpType(array $property): string {
			// Enum properties map directly to their PHP enum class rather than a primitive
			if ($property['type'] === 'enum') {
				return "\\" . ltrim($property['enumType'], "\\");
			}
			
			// All other types go through the database-to-PHP type mapping table
			return TypeMapper::phinxTypeToPhpType($property['type']);
		}
		
		/**
		 * Generates typed property declaration
		 * @param PropertyDefinition $property Property metadata
		 * @return string Property declaration with type hint
		 */
		protected function generatePropertyDefinition(array $property): string {
			$nullable = $property['nullable'] ?? false;
			$nullableIndicator = $nullable ? '?' : '';
			
			// Relationship properties use the entity class name as their type hint
			if (isset($property['relationshipType'])) {
				$type = $property['type'];
				
				// InverseOf collection properties must be public and non-nullable —
				// they are always initialized in the constructor to an empty Collection
				if ($property['relationshipType'] === 'InverseOf') {
					return "public {$type} \${$property['name']};";
				}
				
				return "protected {$nullableIndicator}{$type} \${$property['name']};";
			}
			
			// Regular properties map the database column type to an equivalent PHP type
			$phpType = $this->resolvePhpType($property);
			return "protected {$nullableIndicator}{$phpType} \${$property['name']};";
		}
		
		/**
		 * Generates getter method with proper type hints and docblock
		 * @param PropertyDefinition $property Property metadata
		 * @return string Complete getter method
		 */
		protected function generateGetter(array $property): string {
			$propertyName = $property['name'];
			$methodName = 'get' . ucfirst($propertyName);
			
			// Relationship getters need the entity type rather than a primitive
			if (isset($property['relationshipType'])) {
				$type = $property['type'];
				$nullable = $property['nullable'] ?? false;
				$nullableIndicator = $nullable ? '?' : '';
				
				// InverseOf returns a typed collection interface instead of a single entity
				if ($property['relationshipType'] === 'InverseOf') {
					$targetEntity = $property['targetEntity'] . 'Entity';
					
					return "\n        /**\n" .
						"         * Gets the {$propertyName} collection\n" .
						"         * @return CollectionInterface<{$targetEntity}>\n" .
						"         */\n" .
						"        public function {$methodName}(): CollectionInterface {\n" .
						"            return \$this->{$propertyName};\n" .
						"        }\n";
				}
				
				// OneToOne / ManyToOne return a nullable entity reference
				return "\n        /**\n" .
					"         * Gets the {$propertyName} relationship\n" .
					"         * @return {$nullableIndicator}{$type}\n" .
					"         */\n" .
					"        public function {$methodName}(): {$nullableIndicator}{$type} {\n" .
					"            return \$this->{$propertyName};\n" .
					"        }\n";
			}
			
			// Plain column getter
			$nullable = $property['nullable'] ?? false;
			$nullableIndicator = $nullable ? '?' : '';
			$phpType = $this->resolvePhpType($property);
			
			return "\n        /**\n" .
				"         * Gets the {$propertyName} value\n" .
				"         * @return {$nullableIndicator}{$phpType}\n" .
				"         */\n" .
				"        public function {$methodName}(): {$nullableIndicator}{$phpType} {\n" .
				"            return \$this->{$propertyName};\n" .
				"        }\n";
		}
		
		/**
		 * Generates setter method with fluent interface and bidirectional sync
		 * @param PropertyDefinition $property Property metadata
		 * @return string Complete setter method
		 */
		protected function generateSetter(array $property): string {
			$propertyName = $property['name'];
			$methodName = 'set' . ucfirst($propertyName);
			
			// Relationship setters need bidirectional sync logic
			if (isset($property['relationshipType'])) {
				$type = $property['type'];
				$nullable = $property['nullable'] ?? false;
				$nullableIndicator = $nullable ? '?' : '';
				
				// Identity check short-circuits the setter when the value hasn't actually changed,
				// which prevents infinite loops in bidirectional sync chains
				$setterBody = "            // Prevent redundant updates\n";
				$setterBody .= "            if (\$this->{$propertyName} === \${$propertyName}) {\n";
				$setterBody .= "                return \$this;\n";
				$setterBody .= "            }\n";
				
				// Before reassigning, remove this entity from the previous parent's collection
				// so the old parent's inverse side stays consistent
				if ($property['relationshipType'] === 'ManyToOne' && !empty($property['referencedColumn'])) {
					$singularName = StringInflector::singularize($property['referencedColumn']);
					$removerMethod = 'remove' . ucfirst($singularName);
					
					$setterBody .= "\n";
					$setterBody .= "            // Remove from previous parent's collection\n";
					$setterBody .= "            \$this->{$propertyName}?->{$removerMethod}(\$this);\n";
				}
				
				$setterBody .= "\n";
				$setterBody .= "            // Set new property\n";
				$setterBody .= "            \$this->{$propertyName} = \${$propertyName};\n";
				
				// Add this entity to the new parent's collection to keep the inverse side in sync
				if ($property['relationshipType'] === 'ManyToOne' && !empty($property['referencedColumn'])) {
					$singularName = StringInflector::singularize($property['referencedColumn']);
					$adderMethod = 'add' . ucfirst($singularName);
					
					$setterBody .= "            \${$propertyName}?->{$adderMethod}(\$this);";
				}
				
				return sprintf(
					"
        /**
         * Sets the {$propertyName} relationship
         * @param %s%s $%s The related entity
         * @return \$this
         */
        public function %s(%s%s $%s): self {
%s
            return \$this;
        }
",
					$nullableIndicator,
					$type,
					$propertyName,
					$methodName,
					$nullableIndicator,
					$type,
					$propertyName,
					$setterBody
				);
			}
			
			// Plain column setter — no sync logic needed
			$nullable = $property['nullable'] ?? false;
			$nullableIndicator = $nullable ? '?' : '';
			$phpType = $this->resolvePhpType($property);
			
			return "\n" .
				"        /**\n" .
				"         * Sets the {$propertyName} value\n" .
				"         * @param {$nullableIndicator}{$phpType} \${$propertyName} New value to set\n" .
				"         * @return \$this\n" .
				"         */\n" .
				"        public function {$methodName}({$nullableIndicator}{$phpType} \${$propertyName}): self {\n" .
				"            \$this->{$propertyName} = \${$propertyName};\n" .
				"            return \$this;\n" .
				"        }\n";
		}
		
		/**
		 * Generates method to add item to InverseOf collection
		 *
		 * Checks for duplicates and syncs the inverse side of bidirectional relationships.
		 *
		 * @phpstan-param RelationProperty $property
		 * @param array $property Collection property metadata
		 * @param string $entityName Current entity name
		 * @return string Complete adder method
		 */
		protected function generateCollectionAdder(array $property, string $entityName): string {
			$collectionName = $property['name'];
			$singularName = StringInflector::singularize($collectionName);
			$methodName = 'add' . ucfirst($singularName);
			$targetEntity = $property['targetEntity'] . 'Entity';
			
			$inverseSetter = '';
			
			// If relation is set this is the inverse side — sync the owning side's reference
			// so both ends of the relationship stay consistent after the add
			if (!empty($property['relation'])) {
				$setterMethod = 'set' . ucfirst($property['relation']);
				$inverseSetter = "\n                // Assign this entity on the owning side so the FK is set correctly\n";
				$inverseSetter .= "                \${$singularName}->{$setterMethod}(\$this);";
			}
			
			return "\n        /**\n" .
				"         * Adds an entity to the {$collectionName} collection\n" .
				"         * @param {$targetEntity} \${$singularName} Entity to add\n" .
				"         * @return \$this\n" .
				"         */\n" .
				"        public function {$methodName}({$targetEntity} \${$singularName}): self {\n" .
				"            if (!\$this->{$collectionName}->contains(\${$singularName})) {\n" .
				"                \$this->{$collectionName}[] = \${$singularName};{$inverseSetter}\n" .
				"            }\n" .
				"            return \$this;\n" .
				"        }\n";
		}
		
		/**
		 * Generates method to remove item from InverseOf collection
		 * @phpstan-param RelationProperty $property
		 * @param array $property Collection property metadata
		 * @param string $entityName Current entity name
		 * @return string Complete remover method
		 */
		protected function generateCollectionRemover(array $property, string $entityName): string {
			$collectionName = $property['name'];
			$singularName = StringInflector::singularize($collectionName);
			$methodName = 'remove' . ucfirst($singularName);
			$targetEntity = $property['targetEntity'] . 'Entity';
			
			$inverseRemover = '';
			
			// Only null out the inverse side when it still points at this entity —
			// avoids clobbering a reference that was already reassigned elsewhere
			if (!empty($property['relation'])) {
				$viaField = $property['relation'];
				$getterMethod = 'get' . ucfirst($viaField);
				$setterMethod = 'set' . ucfirst($viaField);
				
				$inverseRemover = "                // Unset inverse side if it still references this entity\n";
				$inverseRemover .= "                if (\${$singularName}->{$getterMethod}() === \$this) {\n";
				$inverseRemover .= "                    \${$singularName}->{$setterMethod}(null);\n";
				$inverseRemover .= "                }";
			}
			
			return "\n        /**\n" .
				"         * Removes an entity from the {$collectionName} collection\n" .
				"         * @param {$targetEntity} \${$singularName} Entity to remove\n" .
				"         * @return \$this\n" .
				"         */\n" .
				"        public function {$methodName}({$targetEntity} \${$singularName}): self {\n" .
				"            if (\$this->{$collectionName}->remove(\${$singularName})) {\n" .
				"                {$inverseRemover}\n" .
				"            }\n" .
				"            \n" .
				"            return \$this;\n" .
				"        }\n";
		}
		
		/**
		 * Updates constructor to initialize InverseOf collections
		 * @param string $content Entity file content
		 * @param array<int, PropertyDefinition> $inverseOfProperties InverseOf properties needing initialization
		 * @return string Updated content with constructor modifications
		 */
		protected function updateConstructor(string $content, array $inverseOfProperties): string {
			if (PhpClassEditor::constructorExists($content)) {
				return PhpClassEditor::updateExistingConstructor($content, $inverseOfProperties);
			} else {
				return PhpClassEditor::addNewConstructor($content, $inverseOfProperties);
			}
		}
	}