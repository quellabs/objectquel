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
				
				// Pass zero-indented snippet; PhpClassEditor::addProperty() applies indentation
				$snippet = "\n\n" . $docComment . "\n" . $propertyDefinition;
				
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
				
				// Collection InverseOf properties use add/remove instead of get/set
				if (isset($property['relationshipType']) && ($property['collection'] ?? false)) {
					$content = $this->insertCollectionMethods($content, $property, $entityName, $analyser, $generator);
					continue;
				}
				
				$content = $this->insertGetterAndSetter($content, $property, $analyser, $generator);
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
			// Build the file skeleton: header, class docblock, and an empty class shell.
			// All subsequent insertions go through PhpClassEditor, which detects the
			// tab indentation from the class line and applies it uniformly.
			$content  = $this->generateFileHeader($entityName);
			$content .= $this->generateClassDocBlock($entityName, $indexes);
			$content .= "\tclass {$entityName}Entity {\n";
			$content .= "\t}\n";
			
			// Insert $id through PhpClassGenerator + PhpClassEditor, identical to
			// how every other property is inserted in updateEntity().
			$generator = new PhpClassGenerator();
			$content = PhpClassEditor::addProperty($content, $generator->generateIdProperty());
			
			// Constructor, additional properties, and accessors all go through the same
			// PhpClassEditor methods used by updateEntity().
			$inverseOfProperties = array_filter($properties, fn($p) => ($p['collection'] ?? false) === true);
			
			if (!empty($inverseOfProperties)) {
				$content = PhpClassEditor::addNewConstructor($content, $inverseOfProperties);
			}
			
			$content = $this->insertProperties($content, $properties);
			$content = $this->insertGettersAndSetters($content, $properties, $entityName);
			
			return $content;
		}
		
		// -------------------------------------------------------------------------
		// Private helpers
		// -------------------------------------------------------------------------
		
		/**
		 * Inserts add/remove methods for a collection InverseOf property
		 * @param string $content Current class content
		 * @param array $property The collection property
		 * @phpstan-param RelationProperty $property
		 * @param string $entityName Entity name for method bodies
		 * @param PhpClassAnalyser $analyser Analyser for the current content state
		 * @param PhpClassGenerator $generator Code generator instance
		 * @return string Updated class content
		 */
		private function insertCollectionMethods(string $content, array $property, string $entityName, PhpClassAnalyser $analyser, PhpClassGenerator $generator): string {
			$singularName = StringInflector::singularize($property['name']);
			$addMethodName    = 'add'    . ucfirst($singularName);
			$removeMethodName = 'remove' . ucfirst($singularName);
			
			// Only generate each method if it doesn't already exist
			if (!$analyser->hasMethod($addMethodName)) {
				$content = PhpClassEditor::addMethod($content, $generator->generateCollectionAdder($property, $entityName));
			}
			
			if (!$analyser->hasMethod($removeMethodName)) {
				$content = PhpClassEditor::addMethod($content, $generator->generateCollectionRemover($property, $entityName));
			}
			
			return $content;
		}
		
		/**
		 * Inserts getter and (unless readonly) setter for a scalar/relation property
		 * @param string $content Current class content
		 * @param PropertyDefinition $property The property to generate accessors for
		 * @param PhpClassAnalyser $analyser Analyser for the current content state
		 * @param PhpClassGenerator $generator Code generator instance
		 * @return string Updated class content
		 */
		private function insertGetterAndSetter(string $content, array $property, PhpClassAnalyser $analyser, PhpClassGenerator $generator): string {
			$getterName = 'get' . ucfirst($property['name']);
			$setterName = 'set' . ucfirst($property['name']);
			
			// Generate getter if not already present
			if (!$analyser->hasMethod($getterName)) {
				$content = PhpClassEditor::addMethod($content, $generator->generateGetter($property));
			}
			
			// Readonly properties get no setter
			if (!($property['readonly'] ?? false) && !$analyser->hasMethod($setterName)) {
				$content = PhpClassEditor::addMethod($content, $generator->generateSetter($property));
			}
			
			return $content;
		}
		
		/**
		 * Generates the <?php header, namespace declaration, and all use statements
		 * @param string $entityName Entity name (used only to determine the namespace)
		 * @return string PHP file opening through last use statement, ending with a newline
		 */
		private function generateFileHeader(string $entityName): string {
			$namespace = $this->configuration->getEntityNameSpace();
			
			$content  = "<?php\n\n\tnamespace {$namespace};\n";
			$content .= "\n";
			$content .= "\tuse Quellabs\\ObjectQuel\\Annotations\\Orm\\Table;\n";
			$content .= "\tuse Quellabs\\ObjectQuel\\Annotations\\Orm\\Column;\n";
			$content .= "\tuse Quellabs\\ObjectQuel\\Annotations\\Orm\\Index;\n";
			$content .= "\tuse Quellabs\\ObjectQuel\\Annotations\\Orm\\UniqueIndex;\n";
			$content .= "\tuse Quellabs\\ObjectQuel\\Annotations\\Orm\\FullTextIndex;\n";
			$content .= "\tuse Quellabs\\ObjectQuel\\Annotations\\Orm\\PrimaryKeyStrategy;\n";
			$content .= "\tuse Quellabs\\ObjectQuel\\Annotations\\Orm\\OneToOne;\n";
			$content .= "\tuse Quellabs\\ObjectQuel\\Annotations\\Orm\\InverseOf;\n";
			$content .= "\tuse Quellabs\\ObjectQuel\\Annotations\\Orm\\ManyToOne;\n";
			$content .= "\tuse Quellabs\\ObjectQuel\\Collections\\Collection;\n";
			$content .= "\tuse Quellabs\\ObjectQuel\\Collections\\CollectionInterface;\n";
			
			return $content;
		}
		
		/**
		 * Generates the class-level docblock containing @Table and all index annotations
		 * @param string $entityName Entity name without "Entity" suffix
		 * @param array<int, IndexDefinition> $indexes Index definitions to emit as annotations
		 * @return string The docblock string, ending with a newline
		 */
		private function generateClassDocBlock(string $entityName, array $indexes): string {
			// Derive the table name: pluralize the entity name, then convert to snake_case
			// e.g. "ProductCategory" → "product_categories"
			$tableNamePlural = StringInflector::pluralize($entityName);
			$tableName = StringInflector::snakeCase($tableNamePlural);
			
			$content  = "\n\t/**\n";
			$content .= "\t * @Orm\\Table(name=\"{$tableName}\")\n";
			
			foreach ($indexes as $index) {
				$indexName    = $index['name'];
				$indexColumns = '{"'  . implode('", "', $index['columns']) . '"}';
				$indexType    = strtoupper($index['type'] ?? 'INDEX');
				
				// Map the index type string to its annotation class name
				$annotationClass = match ($indexType) {
					'UNIQUE'   => 'UniqueIndex',
					'FULLTEXT' => 'FullTextIndex',
					default    => 'Index',
				};
				
				$content .= "\t * @Orm\\{$annotationClass}(name=\"{$indexName}\", columns={$indexColumns})\n";
			}
			
			$content .= "\t */\n";
			
			return $content;
		}
		
	}