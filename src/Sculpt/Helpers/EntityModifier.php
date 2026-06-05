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
			
			// InverseOf properties need collection initialization in the constructor
			$inverseOfProperties = array_filter($properties, fn($p) => ($p['relationshipType'] ?? null) === 'InverseOf');
			
			$updatedContent = $content;
			
			// Inject any missing use statements required by the new properties
			$updatedContent = $this->ensureRequiredImports($updatedContent, $properties);
			
			if (!empty($inverseOfProperties)) {
				$updatedContent = $this->updateConstructor($updatedContent, $inverseOfProperties);
			}
			
			$updatedContent = $this->insertProperties($reparsed, $properties);
			
			// Strip suffix before passing to insertGettersAndSetters, which uses the name for method bodies
			$bareName = str_ends_with($entityName, 'Entity') ? substr($entityName, 0, -6) : $entityName;
			$updatedContent = $this->insertGettersAndSetters($updatedContent, $properties, $bareName);
			return file_put_contents($filePath, $updatedContent) !== false;
		}
		
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
		 * @param string $content Parsed class sections from parseClassContent()
		 * @param array<int, PropertyDefinition> $properties Properties to add
		 * @return string Updated class content with new properties
		 */
		protected function insertProperties(string $content, array $properties): string {
			$analyser = new PhpClassAnalyser($content);
			$generator = new PHPClassGenerator();
			$indent = $analyser->getIndentation();
			
			$newProperties = '';
			
			foreach ($properties as $property) {
				$propertyName = $property['name'];
				
				// Skip if a declaration for this property already exists in the class body
				if ($analyser->hasProperty($propertyName)) {
					continue;
				}
				
				// Relationship properties get a different docblock than plain column properties
				if (isset($property['relationshipType'])) {
					$docComment = $generator->generateRelationshipDocComment($property);
				} else {
					$docComment = $generator->generatePropertyDocComment($property);
				}
				
				// Make property
				$propertyDefinition = $generator->generatePropertyDefinition($property);
				
				// Strip all but the last character of the prefix (keeps the space before *)
				$newProperties .=
					"\n\n"
					. $indent
					. str_replace(
						"\n",
						"\n{$indent}",
						$docComment
					)
					. "\n"
					. $indent
					. $propertyDefinition;
			}
			
			$updatedPropertyCode = $propertyCode . $newProperties;
			
			// Reassemble: header + updated properties + methods + closing brace
			// Use the raw (untrimmed) properties to preserve the original whitespace
			// between the class opening brace and the first property
			$headerRaw = rtrim($classContent->header);
			$leadingWhitespace = $classContent->propertiesRaw;
			preg_match('/^[\r\n]+/', $leadingWhitespace, $leadingMatch);
			$leading = $leadingMatch[0] ?? "\n";
			$methods = ltrim($classContent->methods, "\r\n");
			return $headerRaw . $leading . $updatedPropertyCode . "\n\n{$indent}" . $methods . $classContent->footer;
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
			
			$generator = new PhpClassGenerator();
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
						$methodsToAdd .= $generator->generateCollectionAdder($property, $entityName);
					}
					
					if (!preg_match('/function\s+' . $removeMethodName . '\s*\(/i', $content)) {
						$methodsToAdd .= $generator->generateCollectionRemover($property, $entityName);
					}
					
					continue;
				}
				
				// Generate getter if not already present
				if (!preg_match('/function\s+' . $getterName . '\s*\(/i', $content)) {
					$methodsToAdd .= $generator->generateGetter($property);
				}
				
				// Readonly properties get no setter
				if (!($property['readonly'] ?? false) && !preg_match('/function\s+' . $setterName . '\s*\(/i', $content)) {
					$methodsToAdd .= $generator->generateSetter($property);
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
			$generator = new PhpClassGenerator();
			
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
				if (isset($property['relationshipType'])) {
					$docComment = $generator->generateRelationshipDocComment($property);
				} else {
					$docComment = $generator->generatePropertyDocComment($property);
				}
				
				$propertyDefinition = $generator->generatePropertyDefinition($property);
				
				$content .= "\n        " . str_replace("\n       ", "\n        ", $docComment) . "\n";
				$content .= "        " . $propertyDefinition . "\n";
			}
			
			// Primary key getter — always present regardless of property list
			$content .= $generator->generateGetter(['name' => 'id', 'type' => 'integer']);
			
			// Generate accessors for every declared property
			foreach ($properties as $property) {
				$readOnly = $property['readonly'] ?? false;
				
				// InverseOf collections expose add/remove methods rather than a single setter
				if (isset($property['relationshipType']) && $property['relationshipType'] === 'InverseOf') {
					$content .= $generator->generateCollectionAdder($property, $entityName);
					$content .= $generator->generateCollectionRemover($property, $entityName);
					continue;
				}
				
				$content .= $generator->generateGetter($property);
				
				// Readonly properties intentionally have no setter
				if (!$readOnly) {
					$content .= $generator->generateSetter($property);
				}
			}
			
			$content .= "    }\n";
			
			return $content;
		}
		
		/**
		 * Updates constructor to initialize InverseOf collections
		 * @param string $content Entity file content
		 * @param array<int, PropertyDefinition> $inverseOfProperties InverseOf properties needing initialization
		 * @return string Updated content with constructor modifications
		 */
		protected function updateConstructor(string $content, array $inverseOfProperties): string {
			$analyser = new PHpClassAnalyser($content);
			
			if ($analyser->hasMethod("__construct")) {
				return PhpClassEditor::updateExistingConstructor($content, $inverseOfProperties);
			} else {
				return PhpClassEditor::addNewConstructor($content, $inverseOfProperties);
			}
		}
	}