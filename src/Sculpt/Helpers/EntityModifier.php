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
			
			// Collection InverseOf properties need initialization in the constructor
			$inverseOfProperties = array_filter($properties, fn($p) => ($p['collection'] ?? false) === true);
			
			$updatedContent = $content;
			
			// Inject any missing use statements required by the new properties
			$updatedContent = $this->ensureRequiredImports($updatedContent, $properties);
			
			if (!empty($inverseOfProperties)) {
				$analyser = new PhpClassAnalyser($updatedContent);
				
				if ($analyser->hasMethod("__construct")) {
					$updatedContent = PhpClassEditor::updateExistingConstructor($updatedContent, $inverseOfProperties);
				} else {
					$updatedContent = PhpClassEditor::addNewConstructor($updatedContent, $inverseOfProperties);
				}
			}
			
			$updatedContent = $this->insertProperties($updatedContent, $properties);
			
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
				
				if ($property['collection'] ?? false) {
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
				$content = PhpClassEditor::addUseStatement($content, $import);
			}
			
			return $content;
		}
		
		/**
		 * Inserts new property declarations into the class
		 * @param string $content Entity file content
		 * @param array<int, PropertyDefinition> $properties Properties to add
		 * @return string Updated content with new property declarations spliced in
		 */
		protected function insertProperties(string $content, array $properties): string {
			$generator = new PhpClassGenerator();
			
			// Indentation is stable across the whole file — read it once up front
			$indent = (new PhpClassAnalyser($content))->getIndentation();
			
			foreach ($properties as $property) {
				// Re-create the analyser each iteration: addProperty mutates $content,
				// so positions and existence checks must come from the current version
				$analyser = new PhpClassAnalyser($content);
				
				// Skip if a declaration for this property already exists in the class body
				if ($analyser->hasProperty($property['name'])) {
					continue;
				}
				
				// Relationship properties get a different docblock than plain column properties
				if (isset($property['relationshipType'])) {
					$docComment = $generator->generateRelationshipDocComment($property);
				} else {
					$docComment = $generator->generatePropertyDocComment($property);
				}
				
				$propertyDefinition = $generator->generatePropertyDefinition($property);
				
				$snippet =
					"\n\n"
					. $indent
					. str_replace("\n", "\n{$indent}", $docComment)
					. "\n"
					. $indent
					. $propertyDefinition;
				
				$content = PhpClassEditor::addProperty($content, $snippet);
			}
			
			return $content;
		}
		
		/**
		 * Inserts getter/setter methods and collection adder/remover methods
		 * @param string $content Current class content
		 * @param array<int, PropertyDefinition> $properties Properties needing accessors
		 * @param string $entityName Current entity name for relationship methods
		 * @return string Updated class content with new methods
		 */
		protected function insertGettersAndSetters(string $content, array $properties, string $entityName): string {
			$generator = new PhpClassGenerator();
			
			foreach ($properties as $property) {
				// Re-create the analyser each iteration: addMethod mutates $content,
				// so hasMethod must check the current version
				$analyser = new PhpClassAnalyser($content);
				
				$getterName = 'get' . ucfirst($property['name']);
				$setterName = 'set' . ucfirst($property['name']);
				
				// Collection InverseOf properties use add/remove instead of get/set
				if (isset($property['relationshipType']) && ($property['collection'] ?? false)) {
					$singularName = StringInflector::singularize($property['name']);
					$addMethodName = 'add' . ucfirst($singularName);
					$removeMethodName = 'remove' . ucfirst($singularName);
					
					// Only generate each method if it doesn't already exist
					if (!$analyser->hasMethod($addMethodName)) {
						$content = PhpClassEditor::addMethod($content, $generator->generateCollectionAdder($property, $entityName));
					}
					
					if (!$analyser->hasMethod($removeMethodName)) {
						$content = PhpClassEditor::addMethod($content, $generator->generateCollectionRemover($property, $entityName));
					}
					
					continue;
				}
				
				// Generate getter if not already present
				if (!$analyser->hasMethod($getterName)) {
					$content = PhpClassEditor::addMethod($content, $generator->generateGetter($property));
				}
				
				// Readonly properties get no setter
				if (!($property['readonly'] ?? false) && !$analyser->hasMethod($setterName)) {
					$content = PhpClassEditor::addMethod($content, $generator->generateSetter($property));
				}
			}
			
			return $content;
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
				if ($property['collection'] ?? false) {
					$hasInverseOf = true;
					break;
				}
			}
			
			// Collection InverseOf properties must be initialized to an empty Collection in the
			// constructor, otherwise accessing the collection before it's set would cause a null-dereference
			if ($hasInverseOf) {
				$content .= "\n        /**\n         * Constructor to initialize collections\n         */\n";
				$content .= "        public function __construct() {\n";
				
				foreach ($properties as $property) {
					if ($property['collection'] ?? false) {
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
				
				// Collection InverseOf properties expose add/remove methods rather than a single setter
				if (isset($property['relationshipType']) && ($property['collection'] ?? false)) {
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
		
	}