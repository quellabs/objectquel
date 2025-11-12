<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	use Quellabs\Support\StringInflector;
	
	/**
	 * Manages entity class file generation and modification
	 *
	 * Handles creating new entity classes with properties, relationships, and accessors,
	 * as well as updating existing entities with new properties while preserving existing code.
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
		 * @param array $properties Property metadata with type, nullable, relationship info
		 * @return bool True if operation succeeded
		 */
		public function createOrUpdateEntity(string $entityName, array $properties): bool {
			$fullEntityName = $entityName . "Entity";
			
			if ($this->entityExists($fullEntityName)) {
				return $this->updateEntity($entityName, $properties);
			} else {
				return $this->createNewEntity($entityName, $properties);
			}
		}
		
		/**
		 * Generates a complete entity class file from scratch
		 * @param string $entityName Entity name without "Entity" suffix
		 * @param array $properties Property definitions with metadata
		 * @return bool True if file was created successfully
		 */
		public function createNewEntity(string $entityName, array $properties): bool {
			$entityPath = $this->configuration->getEntityPath();
			
			if (!is_dir($entityPath)) {
				mkdir($entityPath, 0755, true);
			}
			
			$content = $this->generateEntityContent($entityName, $properties);
			return file_put_contents($this->getEntityPath($entityName . "Entity"), $content) !== false;
		}
		
		/**
		 * Updates an existing entity with new properties and methods
		 * @param string $entityName Entity name without "Entity" suffix
		 * @param array $properties New properties to add with full metadata
		 * @return bool True if file was updated successfully
		 */
		public function updateEntity(string $entityName, array $properties): bool {
			$filePath = $this->getEntityPath($entityName . "Entity");
			$content = file_get_contents($filePath);
			
			if ($content === false) {
				return false;
			}
			
			$classContent = $this->parseClassContent($content);
			
			if (!$classContent) {
				return false;
			}
			
			// Extract OneToMany relationships that need collection initialization
			$oneToManyProperties = array_filter($properties, fn($p) => ($p['relationshipType'] ?? null) === 'OneToMany');
			
			$updatedContent = $content;
			if (!empty($oneToManyProperties)) {
				$updatedContent = $this->updateConstructor($updatedContent, $oneToManyProperties);
			}
			
			// Reparse to get accurate insertion points after constructor modifications
			$updatedContent = $this->insertProperties(
				$this->parseClassContent($updatedContent),
				$properties
			);
			
			$updatedContent = $this->insertGettersAndSetters($updatedContent, $properties, $entityName);
			
			// Return true if write was successful
			return file_put_contents($filePath, $updatedContent) !== false;
		}
		
		/**
		 * Parses class file to identify structural sections
		 * @param string $content Complete entity file content
		 * @return array|false Array with keys: header, properties, methods, footer; or false on parse error
		 */
		protected function parseClassContent(string $content): false|array {
			// Locate class declaration with optional extends/implements
			if (!preg_match('/class\s+(\w+)(?:\s+extends\s+\w+)?(?:\s+implements\s+[\w\s,]+)?\s*\{/s', $content, $classMatch, PREG_OFFSET_CAPTURE)) {
				return false;
			}
			
			$classStartPos = (int)$classMatch[0][1] + strlen($classMatch[0][0]);
			$lastBracePos = strrpos($content, '}');
			
			if ($lastBracePos === false) {
				return false;
			}
			
			$classBody = substr($content, $classStartPos, $lastBracePos - $classStartPos);
			
			// Find where methods begin (properties end at first method declaration)
			$methodPattern = '/\s*(public|protected|private)?\s+function\s+\w+/';
			
			if (preg_match($methodPattern, $classBody, $methodMatch, PREG_OFFSET_CAPTURE)) {
				$firstMethodPos = $methodMatch[0][1];
				
				// Check for docblock preceding the method
				$potentialDocBlockStart = strrpos(substr($classBody, 0, $firstMethodPos), '/**');
				
				if ($potentialDocBlockStart !== false && ($firstMethodPos - $potentialDocBlockStart) < 100) {
					$firstMethodPos = $potentialDocBlockStart;
				}
				
				$propertiesSection = trim(substr($classBody, 0, $firstMethodPos));
				$methodsSection = trim(substr($classBody, $firstMethodPos));
			} else {
				$propertiesSection = trim($classBody);
				$methodsSection = '';
			}
			
			return [
				'header'     => substr($content, 0, $classStartPos),
				'properties' => $propertiesSection,
				'methods'    => $methodsSection,
				'footer'     => substr($content, $lastBracePos)
			];
		}
		
		/**
		 * Inserts new property declarations into the class
		 * @param array $classContent Parsed class sections from parseClassContent()
		 * @param array $properties Properties to add
		 * @return string Updated class content with new properties
		 */
		protected function insertProperties(array $classContent, array $properties): string {
			$propertyCode = $classContent['properties'];
			$newProperties = '';
			
			foreach ($properties as $property) {
				$propertyName = $property['name'];
				
				// Skip if property already declared
				if (preg_match('/\s*(protected|private|public)\s+.*\$' . $propertyName . '\s*;/i', $propertyCode)) {
					continue;
				}
				
				$docComment = isset($property['relationshipType'])
					? $this->generateRelationshipDocComment($property)
					: $this->generatePropertyDocComment($property);
				
				$propertyDefinition = $this->generatePropertyDefinition($property);
				
				$newProperties .= "\n\n\t" . $docComment . "\n\t" . $propertyDefinition;
			}
			
			$updatedPropertyCode = $propertyCode . $newProperties;
			
			return $classContent['header'] . $updatedPropertyCode . "\n\n\t" . $classContent['methods'] . $classContent['footer'];
		}
		
		/**
		 * Inserts getter/setter methods and collection adder/remover methods
		 * @param string $content Current class content
		 * @param array $properties Properties needing accessors
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
				$isOneToMany = isset($property['relationshipType']) && $property['relationshipType'] === 'OneToMany';
				
				$getterName = 'get' . ucfirst($property['name']);
				$setterName = 'set' . ucfirst($property['name']);
				
				// Generate getter if not OneToMany and doesn't exist
				if (!$isOneToMany && !preg_match('/function\s+' . $getterName . '\s*\(/i', $content)) {
					$methodsToAdd .= $this->generateGetter($property);
				}
				
				// Generate setter if not OneToMany, not readonly, and doesn't exist
				if (!$isOneToMany && !($property['readonly'] ?? false) && !preg_match('/function\s+' . $setterName . '\s*\(/i', $content)) {
					$methodsToAdd .= $this->generateSetter($property);
				}
				
				// For OneToMany, generate collection management methods
				if ($isOneToMany) {
					$singularName = StringInflector::singularize($property['name']);
					$addMethodName = 'add' . ucfirst($singularName);
					$removeMethodName = 'remove' . ucfirst($singularName);
					
					if (!preg_match('/function\s+' . $addMethodName . '\s*\(/i', $content)) {
						$methodsToAdd .= $this->generateCollectionAdder($property, $entityName);
					}
					
					if (!preg_match('/function\s+' . $removeMethodName . '\s*\(/i', $content)) {
						$methodsToAdd .= $this->generateCollectionRemover($property, $entityName);
					}
				}
			}
			
			return substr($content, 0, $lastBracePos) . $methodsToAdd . "\n}" . substr($content, $lastBracePos + 1);
		}
		
		/**
		 * Generates complete entity class file content
		 * @param string $entityName Entity name without "Entity" suffix
		 * @param array $properties All properties for this entity
		 * @return string Complete PHP class file content
		 */
		protected function generateEntityContent(string $entityName, array $properties): string {
			$namespace = $this->configuration->getEntityNameSpace();
			$content = "<?php\n\n    namespace {$namespace};\n";
			
			// Use statements for ORM annotations and collections
			$content .= "\n";
			$content .= "    use Quellabs\\ObjectQuel\\Annotations\\Orm\\Table;\n";
			$content .= "    use Quellabs\\ObjectQuel\\Annotations\\Orm\\Column;\n";
			$content .= "    use Quellabs\\ObjectQuel\\Annotations\\Orm\\PrimaryKeyStrategy;\n";
			$content .= "    use Quellabs\\ObjectQuel\\Annotations\\Orm\\OneToOne;\n";
			$content .= "    use Quellabs\\ObjectQuel\\Annotations\\Orm\\OneToMany;\n";
			$content .= "    use Quellabs\\ObjectQuel\\Annotations\\Orm\\ManyToOne;\n";
			$content .= "    use Quellabs\\ObjectQuel\\Collections\\Collection;\n";
			$content .= "    use Quellabs\\ObjectQuel\\Collections\\CollectionInterface;\n";
			
			// Generate table name in snake_case plural form
			$tableNamePlural = StringInflector::pluralize($entityName);
			$tableName = StringInflector::snakeCase($tableNamePlural);
			
			$content .= "\n    /**\n     * @Orm\\Table(name=\"{$tableName}\")\n     */\n";
			$content .= "    class {$entityName}Entity {\n";
			
			// Primary key property
			$content .= "\n        /**\n";
			$content .= "         * @Orm\\Column(name=\"id\", type=\"integer\", unsigned=true, primary_key=true)\n";
			$content .= "         * @Orm\\PrimaryKeyStrategy(strategy=\"identity\")\n";
			$content .= "         */\n";
			$content .= "        protected ?int \$id = null;\n";
			
			// Check if constructor needed for OneToMany initialization
			$hasOneToMany = false;
			foreach ($properties as $property) {
				if (isset($property['relationshipType']) && $property['relationshipType'] === 'OneToMany') {
					$hasOneToMany = true;
					break;
				}
			}
			
			// Add constructor if OneToMany relationships exist
			if ($hasOneToMany) {
				$content .= "\n        /**\n         * Constructor to initialize collections\n         */\n";
				$content .= "        public function __construct() {\n";
				
				foreach ($properties as $property) {
					if (isset($property['relationshipType']) && $property['relationshipType'] === 'OneToMany') {
						$content .= "            \$this->{$property['name']} = new Collection();\n";
					}
				}
				
				$content .= "        }\n";
			}
			
			// Add all properties with appropriate docblocks
			foreach ($properties as $property) {
				$docComment = isset($property['relationshipType'])
					? $this->generateRelationshipDocComment($property)
					: $this->generatePropertyDocComment($property);
				
				$propertyDefinition = $this->generatePropertyDefinition($property);
				
				$content .= "\n        " . str_replace("\n       ", "\n        ", $docComment) . "\n";
				$content .= "        " . $propertyDefinition . "\n";
			}
			
			// Primary key getter
			$content .= $this->generateGetter(['name' => 'id', 'type' => 'integer']);
			
			// Accessors for all properties
			foreach ($properties as $property) {
				$readOnly = $property['readonly'] ?? false;
				$isOneToMany = isset($property['relationshipType']) && $property['relationshipType'] === 'OneToMany';
				
				if ($isOneToMany) {
					$content .= $this->generateCollectionAdder($property, $entityName);
					$content .= $this->generateCollectionRemover($property, $entityName);
					continue;
				}
				
				$content .= $this->generateGetter($property);
				
				if (!$readOnly) {
					$content .= $this->generateSetter($property);
				}
			}
			
			$content .= "    }\n";
			
			return $content;
		}
		
		/**
		 * Generates ORM Column annotation docblock for regular properties
		 * @param array $property Property metadata (name, type, nullable, limit, precision, etc.)
		 * @return string PHPDoc comment with @Orm\Column annotation
		 */
		protected function generatePropertyDocComment(array $property): string {
			$nullable = $property['nullable'] ?? false;
			$type = $property['type'] ?? 'string';
			$snakeCaseName = StringInflector::snakeCase($property['name']);
			
			$properties = [
				"name=\"{$snakeCaseName}\"",
				"type=\"{$type}\""
			];
			
			// Add enum class reference if applicable
			if (!empty($property['enumType'])) {
				$properties[] = "enumType=" . ltrim($property['enumType'], "\\") . "::class";
			}
			
			// Optional column attributes
			if (isset($property['limit']) && is_numeric($property['limit'])) {
				$properties[] = "limit={$property['limit']}";
			}
			
			if (isset($property['unsigned'])) {
				$properties[] = "unsigned=" . ($property['unsigned'] ? "true" : "false");
			}
			
			if (isset($property['precision']) && is_numeric($property['precision'])) {
				$properties[] = "precision={$property['precision']}";
			}
			
			if (isset($property['scale']) && is_numeric($property['scale'])) {
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
		 * @param array $property Relationship metadata (targetEntity, mappedBy, inversedBy, etc.)
		 * @return string PHPDoc comment with relationship annotation
		 */
		protected function generateRelationshipDocComment(array $property): string {
			$relationshipType = $property['relationshipType'];
			$targetEntity = $property['targetEntity'];
			
			$options = [];
			
			// Inverse side property name (for bidirectional relationships)
			if (!empty($property['mappedBy'])) {
				$options[] = "mappedBy=\"{$property['mappedBy']}\"";
			}
			
			// Owning side property name (for bidirectional relationships)
			if (!empty($property['inversedBy'])) {
				$options[] = "inversedBy=\"{$property['inversedBy']}\"";
			}
			
			// Lazy loading for collections to avoid N+1 queries
			if ($relationshipType === 'OneToMany') {
				$options[] = "fetch=\"LAZY\"";
			}
			
			// Owning side owns the foreign key
			$isOwningSide = empty($property['mappedBy']);
			
			if ($isOwningSide) {
				if ($property['nullable'] ?? false) {
					$options[] = "nullable=true";
				}
				
				// Foreign key column in current table
				if (!empty($property['relationColumn'])) {
					$options[] = "relationColumn=\"{$property['relationColumn']}\"";
				}
				
				// Referenced column in target table (defaults to 'id')
				if (isset($property['foreignColumn']) && $property['foreignColumn'] !== 'id') {
					$options[] = "foreignColumn=\"{$property['foreignColumn']}\"";
				}
			}
			
			$optionsStr = !empty($options) ? ', ' . implode(', ', $options) : '';
			$comment = "/**\n         * @Orm\\{$relationshipType}(targetEntity=\"{$targetEntity}Entity\"{$optionsStr})";
			
			// Add collection type hint for OneToMany
			if ($relationshipType === 'OneToMany') {
				$comment .= "\n         * @var CollectionInterface<{$targetEntity}Entity>";
			}
			
			$comment .= "\n         */";
			
			return $comment;
		}
		
		/**
		 * Generates typed property declaration
		 * @param array $property Property metadata
		 * @return string Property declaration with type hint
		 */
		protected function generatePropertyDefinition(array $property): string {
			$nullable = $property['nullable'] ?? false;
			$nullableIndicator = $nullable ? '?' : '';
			
			// Relationship properties use entity type
			if (isset($property['relationshipType'])) {
				$type = $property['type'];
				return "protected {$nullableIndicator}{$type} \${$property['name']};";
			}
			
			// Regular properties map database type to PHP type
			$type = $property['type'] ?? 'string';
			
			if ($type === "enum") {
				$phpType = "\\" . ltrim($property["enumType"], "\\");
			} else {
				$phpType = TypeMapper::phinxTypeToPhpType($type);
			}
			
			return "protected {$nullableIndicator}{$phpType} \${$property['name']};";
		}
		
		/**
		 * Generates getter method with proper type hints and docblock
		 * @param array $property Property metadata
		 * @return string Complete getter method
		 */
		protected function generateGetter(array $property): string {
			$propertyName = $property['name'];
			$methodName = 'get' . ucfirst($propertyName);
			
			// Relationship getters
			if (isset($property['relationshipType'])) {
				$type = $property['type'];
				$nullable = $property['nullable'] ?? false;
				$nullableIndicator = $nullable ? '?' : '';
				
				// OneToMany returns collection interface
				if ($property['relationshipType'] === 'OneToMany') {
					$targetEntity = $property['targetEntity'] . 'Entity';
					
					return "\n        /**\n" .
						"         * Gets the {$propertyName} collection\n" .
						"         * @return CollectionInterface<{$targetEntity}>\n" .
						"         */\n" .
						"        public function {$methodName}(): CollectionInterface {\n" .
						"            return \$this->{$propertyName};\n" .
						"        }\n";
				}
				
				return "\n        /**\n" .
					"         * Gets the {$propertyName} relationship\n" .
					"         * @return {$nullableIndicator}{$type}\n" .
					"         */\n" .
					"        public function {$methodName}(): {$nullableIndicator}{$type} {\n" .
					"            return \$this->{$propertyName};\n" .
					"        }\n";
			}
			
			// Regular property getters
			$nullable = $property['nullable'] ?? false;
			$type = $property['type'] ?? 'string';
			$nullableIndicator = $nullable ? '?' : '';
			
			if ($type === "enum") {
				$phpType = "\\" . ltrim($property["enumType"], "\\");
			} else {
				$phpType = TypeMapper::phinxTypeToPhpType($type);
			}
			
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
		 * @param array $property Property metadata
		 * @return string Complete setter method
		 */
		protected function generateSetter(array $property): string {
			$propertyName = $property['name'];
			$methodName = 'set' . ucfirst($propertyName);
			
			// Relationship setters with bidirectional sync
			if (isset($property['relationshipType'])) {
				$type = $property['type'];
				$nullable = $property['nullable'] ?? false;
				$nullableIndicator = $nullable ? '?' : '';
				
				// Identity check prevents infinite loops in bidirectional relationships
				$setterBody  = "            // Prevent redundant updates\n";
				$setterBody .= "            if (\$this->{$propertyName} === \${$propertyName}) {\n";
				$setterBody .= "                return \$this;\n";
				$setterBody .= "            }\n";
				
				// Clean up old ManyToOne relationship before reassigning
				if ($property['relationshipType'] === 'ManyToOne' && !empty($property['inversedBy'])) {
					$singularName = StringInflector::singularize($property['inversedBy']);
					$removerMethod = 'remove' . ucfirst($singularName);
					
					$setterBody .= "\n";
					$setterBody .= "            // Remove from previous parent's collection\n";
					$setterBody .= "            \$this->{$propertyName}?->{$removerMethod}(\$this);\n";
				}
				
				$setterBody .= "\n";
				$setterBody .= "            // Set new property\n";
				$setterBody .= "            \$this->{$propertyName} = \${$propertyName};\n";
				
				// Sync bidirectional ManyToOne relationship
				if ($property['relationshipType'] === 'ManyToOne' && !empty($property['inversedBy'])) {
					$singularName = StringInflector::singularize($property['inversedBy']);
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
			
			// Regular property setters
			$nullable = $property['nullable'] ?? false;
			$type = $property['type'] ?? 'string';
			$nullableIndicator = $nullable ? '?' : '';
			
			if ($type === "enum") {
				$phpType = "\\" . ltrim($property["enumType"], "\\");
			} else {
				$phpType = TypeMapper::phinxTypeToPhpType($type);
			}
			
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
		 * Generates method to add item to OneToMany collection
		 *
		 * Checks for duplicates and syncs the inverse side of bidirectional relationships.
		 *
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
			
			if (!empty($property['mappedBy'])) {
				$setterMethod = 'set' . ucfirst($property['mappedBy']);
				$inverseSetter = "\n                // Sync bidirectional relationship\n";
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
		 * Generates method to remove item from OneToMany collection
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
			
			if (!empty($property['mappedBy'])) {
				$mappedByField = $property['mappedBy'];
				$getterMethod = 'get' . ucfirst($mappedByField);
				$setterMethod = 'set' . ucfirst($mappedByField);
				
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
		 * Updates constructor to initialize OneToMany collections
		 * @param string $content Entity file content
		 * @param array $oneToManyProperties OneToMany properties needing initialization
		 * @return string Updated content with constructor modifications
		 */
		protected function updateConstructor(string $content, array $oneToManyProperties): string {
			if ($this->constructorExists($content)) {
				return $this->updateExistingConstructor($content, $oneToManyProperties);
			} else {
				return $this->addNewConstructor($content, $oneToManyProperties);
			}
		}
		
		/**
		 * Checks if a constructor method exists in the class
		 * @param string $content Entity file content
		 * @return bool True if __construct method found
		 */
		protected function constructorExists(string $content): bool {
			return preg_match('/\s+((?:public|private|protected)\s+)?function\s+__construct\s*\(/i', $content) === 1;
		}
		
		/**
		 * Locates the character position where constructor declaration begins
		 * @param string $content Complete PHP file content
		 * @return int|null Character position of constructor start, or null if not found
		 */
		protected function getConstructorStartPos(string $content): ?int {
			if (!preg_match('/\s+((?:public|private|protected)\s+)?function\s+__construct\s*\(/i', $content, $constructorMatch, PREG_OFFSET_CAPTURE)) {
				return null;
			}
			
			return $constructorMatch[0][1];
		}
		
		/**
		 * Modifies existing constructor to add collection initialization statements
		 * @param string $content Entity file content
		 * @param array $oneToManyProperties Collections to initialize
		 * @return string Updated content with modified constructor
		 */
		protected function updateExistingConstructor(string $content, array $oneToManyProperties): string {
			$constructorStart = $this->getConstructorStartPos($content);
			$openBracePos = strpos($content, '{', $constructorStart);
			
			if ($openBracePos === false) {
				return $content;
			}
			
			$constructorEnd = $this->findClosingBrace($content, $openBracePos);
			
			if ($constructorEnd === null) {
				return $content;
			}
			
			$initCode = $this->generateCollectionInitializations($content, $oneToManyProperties);
			
			if (!empty($initCode)) {
				return substr($content, 0, $constructorEnd) . $initCode . "\n\t" . substr($content, $constructorEnd);
			}
			
			return $content;
		}
		
		/**
		 * Finds matching closing brace for an opening brace at given position
		 * @param string $content Source code to search
		 * @param int $openBracePos Character index of opening brace
		 * @return int|null Character index of matching closing brace, or null if not found
		 */
		protected function findClosingBrace(string $content, int $openBracePos): ?int {
			$offset = $openBracePos + 1;
			$braceLevel = 1;
			$length = strlen($content);
			
			while ($offset < $length) {
				if ($content[$offset] === '{') {
					$braceLevel++;
				} elseif ($content[$offset] === '}') {
					$braceLevel--;
					
					if ($braceLevel === 0) {
						return $offset;
					}
				}
				
				$offset++;
			}
			
			return null;
		}
		
		/**
		 * Generates collection initialization code for constructor body
		 * @param string $content Entity file content
		 * @param array $oneToManyProperties Collections needing initialization
		 * @return string Initialization code statements
		 */
		protected function generateCollectionInitializations(string $content, array $oneToManyProperties): string {
			$initCode = '';
			
			foreach ($oneToManyProperties as $property) {
				$propertyName = $property['name'];
				
				// Skip if already initialized
				if (!preg_match('/\$this->' . preg_quote($propertyName, '/') . '\s*=\s*new\s+Collection\(\)/', $content)) {
					$initCode .= "\n\t\t\$this->{$propertyName} = new Collection();";
				}
			}
			
			return $initCode;
		}
		
		/**
		 * Creates a new constructor with collection initializations
		 * @param string $content Entity file content
		 * @param array $oneToManyProperties Collections to initialize
		 * @return string Updated content with new constructor
		 */
		protected function addNewConstructor(string $content, array $oneToManyProperties): string {
			$constructorCode = "\n\t/**\n\t * Constructor to initialize collections\n\t */\n\tpublic function __construct() {";
			
			foreach ($oneToManyProperties as $property) {
				$propertyName = $property['name'];
				$constructorCode .= "\n\t\t\$this->{$propertyName} = new Collection();";
			}
			
			$constructorCode .= "\n\t}\n";
			
			$insertPosition = $this->findConstructorInsertPosition($content);
			
			if ($insertPosition !== null) {
				return substr($content, 0, $insertPosition) . "\n" . $constructorCode . substr($content, $insertPosition);
			}
			
			return $content;
		}
		
		/**
		 * Determines optimal position to insert a new constructor
		 * @param string $content Complete PHP file content
		 * @return int|null Character position for insertion, or null if not found
		 */
		protected function findConstructorInsertPosition(string $content): ?int {
			if (!preg_match('/class\s+[^{]+\{/i', $content, $classMatch, PREG_OFFSET_CAPTURE)) {
				return null;
			}
			
			$classOpenBracePos = strpos($content, '{', $classMatch[0][1]);
			
			if ($classOpenBracePos === false) {
				return null;
			}
			
			// Find all property declarations
			preg_match_all('/(?:(protected|private|public)\s+|^\s*)\$[^;]+;/im', $content, $propertyMatches, PREG_OFFSET_CAPTURE);
			
			if (!empty($propertyMatches[0])) {
				// Insert after last property
				$lastPropertyPos = $propertyMatches[0][count($propertyMatches[0]) - 1][1];
				return strpos($content, ';', $lastPropertyPos) + 1;
			}
			
			// No properties found, insert after class opening brace
			return $classOpenBracePos + 1;
		}
	}