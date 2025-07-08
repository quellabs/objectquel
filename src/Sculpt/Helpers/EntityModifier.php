<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	
	class EntityModifier {
		
		private Configuration $configuration;
		private TypeMapper $typeMapper;
		
		/**
		 * Constructor for EntityModifier
		 * @param Configuration $configuration
		 */
		public function __construct(Configuration $configuration) {
			$this->configuration = $configuration;
			$this->typeMapper = new TypeMapper();
		}
		
		/**
		 * Checks if an entity exists
		 * @param string $entityName Name of the entity
		 * @return bool True if entity exists
		 */
		public function entityExists(string $entityName): bool {
			return file_exists($this->getEntityPath($entityName));
		}
		
		/**
		 * Gets the file path for an entity
		 * @param string $entityName Name of the entity
		 * @return string Path to the entity file
		 */
		public function getEntityPath(string $entityName): string {
			return $this->configuration->getEntityPath() . '/' . $entityName . '.php';
		}
		
		/**
		 * Creates a new entity or updates an existing one
		 * This function serves as the main entry point for entity management, determining
		 * whether to create a new entity class or update an existing one based on file existence
		 * @param string $entityName Name of the entity (without "Entity" suffix)
		 * @param array $properties List of properties to add - detailed metadata for each property
		 * @return bool True if successful - indicates whether the operation completed correctly
		 */
		public function createOrUpdateEntity(string $entityName, array $properties): bool {
			// Check if the entity already exists by looking for its file
			// The entityExists method likely checks for the presence of the file at the expected path
			// We append "Entity" to match the standard naming convention for entity classes
			if ($this->entityExists($entityName . "Entity")) {
				// If the entity exists, delegate to the updateEntity method
				// This method handles the complex task of modifying an existing class
				// without disrupting existing code or functionality
				// It will add the new properties and methods while preserving existing ones
				return $this->updateEntity($entityName, $properties);
			}
			
			// If the entity doesn't exist, delegate to the createNewEntity method
			// This method generates a complete class file from scratch with all required
			// properties, methods, annotations, and proper initialization
			return $this->createNewEntity($entityName, $properties);
		}
		
		/**
		 * Creates a new entity with properties and getters/setters
		 * This function generates a complete entity class file from scratch, including
		 * all necessary properties, constructors, and accessor methods
		 * @param string $entityName Name of the entity (without "Entity" suffix)
		 * @param array $properties List of properties to add - detailed metadata for each property
		 * @return bool True if successful - indicates whether the file was created correctly
		 */
		public function createNewEntity(string $entityName, array $properties): bool {
			// Ensure the entity directory exists before attempting to create files
			// This is important for first-time setup or when deploying to new environments
			if (!is_dir($this->configuration->getEntityPath())) {
				// Create the directory structure recursively with standard permissions
				// 0755 allows the owner to read/write/execute and others to read/execute
				// The 'true' parameter creates parent directories as needed
				mkdir($this->configuration->getEntityPath(), 0755, true);
			}
			
			// Generate the complete entity class content as a string
			// This includes:
			// - Namespace declaration
			// - Use statements for imports
			// - Class declaration with proper inheritance
			// - Property declarations with proper annotations
			// - Constructor for collection initialization
			// - Getter/setter methods for all properties
			// - Adder/remover methods for collections
			$content = $this->generateEntityContent($entityName, $properties);
			
			// Write the generated content to a new file
			// The file path is constructed using the entity name plus "Entity" suffix
			// Returns boolean success/failure of the write operation
			return file_put_contents($this->getEntityPath($entityName . "Entity"), $content) !== false;
		}
		
		/**
		 * Updates an existing entity with new properties and getters/setters
		 * This function modifies entity classes by adding new properties, updating constructors
		 * for collection initialization, and generating accessor methods
		 * @param string $entityName Name of the entity (without "Entity" suffix)
		 * @param array $properties List of properties to add - detailed metadata for each property
		 * @return bool True if successful - indicates whether the file was updated correctly
		 */
		public function updateEntity(string $entityName, array $properties): bool {
			// Construct the full file path to the entity class using the entity name
			// The getEntityPath method likely adds necessary directory prefixes and file extension
			$filePath = $this->getEntityPath($entityName . "Entity");
			
			// Read the current content of the entity file
			// This gives us the starting point for our modifications
			$content = file_get_contents($filePath);
			
			// Verify that the file exists and was read successfully
			// Return false immediately if file cannot be read
			if ($content === false) {
				return false;
			}
			
			// Parse the class structure to identify where to insert new code
			// This helper method likely extracts information about class boundaries,
			// existing properties, methods, etc.
			$classContent = $this->parseClassContent($content);
			
			// Safety check - if parsing failed, abort the operation
			// This prevents corrupting the file with improperly placed code
			if (!$classContent) {
				return false;
			}
			
			// Analyze the properties to determine if we need to handle OneToMany relationships.
			// OneToMany relationships require special handling for collection initialization.
			$hasNewOneToMany = false;
			$oneToManyProperties = [];
			
			// Iterate through each property to identify OneToMany relationships
			foreach ($properties as $property) {
				if (isset($property['relationshipType']) && $property['relationshipType'] === 'OneToMany') {
					// Flag that we need constructor updates
					$hasNewOneToMany = true;
					
					// Track the OneToMany properties for constructor initialization
					$oneToManyProperties[] = $property;
				}
			}
			
			// Update or create a constructor if we have OneToMany collections that need initialization
			// Collection properties must be initialized to prevent null reference errors
			if ($hasNewOneToMany) {
				// The updateConstructor method handles both creating new constructors
				// and modifying existing ones as needed
				$updatedContent = $this->updateConstructor($content, $oneToManyProperties);
			} else {
				// If no OneToMany properties, we don't need to modify the constructor
				$updatedContent = $content;
			}
			
			// Add the new properties to the class
			// This inserts the property declarations in the appropriate location
			// Re-parse the class content after constructor updates to ensure correct insertion points
			$updatedContent = $this->insertProperties($this->parseClassContent($updatedContent), $properties);
			
			// Generate and add the getter and setter methods for the new properties
			// For OneToMany relationships, this also adds the collection adder/remover methods
			$updatedContent = $this->insertGettersAndSetters($updatedContent, $properties, $entityName);
			
			// Write the updated content back to the file
			// Return success/failure based on the write operation
			// The !== false check handles cases where zero bytes might be written (unlikely but possible)
			return file_put_contents($filePath, $updatedContent) !== false;
		}
		
		/**
		 * Parses the class content to identify sections
		 * @param string $content Entity file content
		 * @return array|false Class content sections or false on error
		 */
		protected function parseClassContent(string $content): false|array {
			// Find the class definition
			if (!preg_match('/class\s+(\w+)(?:\s+extends\s+\w+)?(?:\s+implements\s+[\w\s,]+)?\s*\{/s', $content, $classMatch, PREG_OFFSET_CAPTURE)) {
				return false;
			}
			
			// Find the last closing brace
			$classStartPos = (int)$classMatch[0][1] + strlen($classMatch[0][0]);
			$lastBracePos = strrpos($content, '}');
			
			if ($lastBracePos === false) {
				return false;
			}
			
			// Extract class body
			$classBody = substr($content, $classStartPos, $lastBracePos - $classStartPos);
			
			// Identify property section (ends at the first method declaration)
			// Methods start with: any visibility, then "function", then a name
			$methodPattern = '/\s*(public|protected|private)?\s+function\s+\w+/';
			
			// Split into properties and methods sections
			if (preg_match($methodPattern, $classBody, $methodMatch, PREG_OFFSET_CAPTURE)) {
				$firstMethodPos = $methodMatch[0][1];
				
				// Find the beginning of the method or its docblock
				$potentialDocBlockStart = strrpos(substr($classBody, 0, $firstMethodPos), '/**');
				
				if ($potentialDocBlockStart !== false && ($firstMethodPos - $potentialDocBlockStart) < 100) {
					// There's a docblock before this method, adjust firstMethodPos
					$firstMethodPos = $potentialDocBlockStart;
				}
				
				$propertiesSection = trim(substr($classBody, 0, $firstMethodPos));
				$methodsSection = trim(substr($classBody, $firstMethodPos));
			} else {
				// No methods found
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
		 * Insert properties into the class content
		 * @param array $classContent Parsed class content
		 * @param array $properties List of properties to add
		 * @return string Updated class content
		 */
		protected function insertProperties(array $classContent, array $properties): string {
			$propertyCode = $classContent['properties'];
			
			// Add each new property
			$newProperties = '';
			foreach ($properties as $property) {
				// Skip if property already exists
				$propertyName = $property['name'];
				if (preg_match('/\s*(protected|private|public)\s+.*\$' . $propertyName . '\s*;/i', $propertyCode)) {
					continue;
				}
				
				$docComment = isset($property['relationshipType'])
					? $this->generateRelationshipDocComment($property)
					: $this->generatePropertyDocComment($property);
				
				$propertyDefinition = $this->generatePropertyDefinition($property);
				
				$newProperties .= "\n\n\t" . $docComment . "\n\t" . $propertyDefinition;
			}
			
			// Add the new properties at the end of the properties section
			$updatedPropertyCode = $propertyCode . $newProperties;
			
			return $classContent['header'] . $updatedPropertyCode . "\n\n\t" . $classContent['methods'] . $classContent['footer'];
		}
		
		/**
		 * Insert getters and setters into the class content
		 * @param string $content Class content
		 * @param array $properties List of properties to add
		 * @param string $entityName Name of the entity
		 * @return string Updated class content
		 */
		protected function insertGettersAndSetters(string $content, array $properties, string $entityName): string {
			// Find the position of the last closing brace
			$lastBracePos = strrpos($content, '}');
			if ($lastBracePos === false) {
				return $content;
			}
			
			$methodsToAdd = '';
			
			// Generate getter and setter for each property
			foreach ($properties as $property) {
				// Skip getter/setter for OneToMany relationships
				$isOneToMany = isset($property['relationshipType']) && $property['relationshipType'] === 'OneToMany';
				
				// Skip if getter/setter already exists
				$getterName = 'get' . ucfirst($property['name']);
				$setterName = 'set' . ucfirst($property['name']);
				
				// Only generate getter if not OneToMany and doesn't already exist
				if (!$isOneToMany && !preg_match('/function\s+' . $getterName . '\s*\(/i', $content)) {
					$methodsToAdd .= $this->generateGetter($property);
				}
				
				// Only generate setter if not OneToMany and doesn't already exist
				if (!$isOneToMany && !preg_match('/function\s+' . $setterName . '\s*\(/i', $content)) {
					$methodsToAdd .= $this->generateSetter($property);
				}
				
				// For OneToMany relationships, add additional methods for collection management
				if ($isOneToMany) {
					$singularName = $this->getSingularName($property['name']);
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
			
			// Insert methods before the last brace
			return substr($content, 0, $lastBracePos) . $methodsToAdd . "\n}" . substr($content, $lastBracePos + 1);
		}
		
		/**
		 * Generate the content for a new entity
		 * @param string $entityName Name of the entity
		 * @param array $properties List of properties for the entity
		 * @return string Entity file content
		 */
		protected function generateEntityContent(string $entityName, array $properties): string {
			// Namespace
			$namespace = $this->configuration->getEntityNameSpace();
			$content = "<?php\n\n   namespace $namespace;\n";
			
			// Use statements
			$content .= "\n";
			$content .= "   use Quellabs\\ObjectQuel\\Annotations\\Orm\\Table;\n";
			$content .= "   use Quellabs\\ObjectQuel\\Annotations\\Orm\\Column;\n";
			$content .= "   use Quellabs\\ObjectQuel\\Annotations\\Orm\\PrimaryKeyStrategy;\n";
			$content .= "   use Quellabs\\ObjectQuel\\Annotations\\Orm\\OneToOne;\n";
			$content .= "   use Quellabs\\ObjectQuel\\Annotations\\Orm\\OneToMany;\n";
			$content .= "   use Quellabs\\ObjectQuel\\Annotations\\Orm\\ManyToOne;\n";
			$content .= "   use Quellabs\\ObjectQuel\\Collections\\Collection;\n";
			$content .= "   use Quellabs\\ObjectQuel\\Collections\\CollectionInterface;\n";
			
			// Convert entity name to table name
			$tableNamePlural = $this->pluralize($entityName);
			$tableName = $this->snakeCase($tableNamePlural);
			
			// Add class definitions
			$content .= "\n   /**\n    * @Orm\\Table(name=\"{$tableName}\")\n    */\n";
			$content .= "   class {$entityName}Entity {\n";
			
			// Add primary key property
			$content .= "\n      /**\n";
			$content .= "       * @Orm\\Column(name=\"id\", type=\"integer\", unsigned=true, primary_key=true)\n";
			$content .= "       * @Orm\\PrimaryKeyStrategy(strategy=\"identity\")\n";
			$content .= "       */\n";
			$content .= "      protected ?int \$id = null;\n";
			
			// Add constructor for OneToMany relationships initialization
			$hasOneToMany = false;
			foreach ($properties as $property) {
				if (isset($property['relationshipType']) && $property['relationshipType'] === 'OneToMany') {
					$hasOneToMany = true;
					break;
				}
			}
			
			// If we have OneToMany relationships, add a constructor to initialize collections
			if ($hasOneToMany) {
				$content .= "\n      /**\n       * Constructor to initialize collections\n       */\n";
				$content .= "      public function __construct() {\n";
				
				foreach ($properties as $property) {
					if (isset($property['relationshipType']) && $property['relationshipType'] === 'OneToMany') {
						$content .= "         \$this->{$property['name']} = new Collection();\n";
					}
				}
				
				$content .= "      }\n";
			}
			
			// Add properties
			foreach ($properties as $property) {
				if (isset($property['relationshipType'])) {
					$docComment = $this->generateRelationshipDocComment($property);
				} else {
					$docComment = $this->generatePropertyDocComment($property);
				}
				
				$propertyDefinition = $this->generatePropertyDefinition($property);
				
				$content .= "\n      " . str_replace("\n\t", "\n      ", $docComment) . "\n";
				$content .= "      " . $propertyDefinition . "\n";
			}
			
			// Add getter for primary id
			$content .= $this->generateGetter(['name' => 'id', 'type' => 'integer']);
			
			// Add getters and setters
			foreach ($properties as $property) {
				// Skip adding getter/setter for OneToMany relationships
				$isOneToMany = isset($property['relationshipType']) && $property['relationshipType'] === 'OneToMany';
				
				// Add getter and setter only if not a OneToMany relationship
				if (!$isOneToMany) {
					$content .= $this->generateGetter($property);
					$content .= $this->generateSetter($property);
				}
				
				// For OneToMany relationships, add additional methods
				if ($isOneToMany) {
					$content .= $this->generateCollectionAdder($property, $entityName);
					$content .= $this->generateCollectionRemover($property, $entityName);
				}
			}
			
			$content .= "   }\n";
			
			return $content;
		}
		
		/**
		 * Generate a property's PHPDoc comment
		 * @param array $property Property information
		 * @return string PHPDoc comment
		 */
		protected function generatePropertyDocComment(array $property): string {
			$nullable = $property['nullable'] ?? false;
			$type = $property['type'] ?? 'string';
			$snakeCaseName = $this->snakeCase($property['name']);
			
			$properties = [];
			
			// Add the name and type properties
			$properties[] = "name=\"{$snakeCaseName}\"";
			$properties[] = "type=\"{$type}\"";
			
			// Add optional properties if they exist
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
			return "/**\n       * @Orm\\Column({$propertiesString})\n       */";
		}
		
		/**
		 * Generate a relationship PHPDoc comment
		 * @param array $property Relationship property information
		 * @return string PHPDoc comment
		 */
		protected function generateRelationshipDocComment(array $property): string {
			$relationshipType = $property['relationshipType'];
			$targetEntity = $property['targetEntity'];
			$nullable = $property['nullable'] ?? false;
			
			$comment = "/**\n       * @Orm\\{$relationshipType}(targetEntity=\"{$targetEntity}Entity\"";
			
			// Add mappedBy attribute for OneToMany or bidirectional OneToOne
			if (!empty($property['mappedBy'])) {
				$comment .= " mappedBy=\"{$property['mappedBy']}\"";
			}
			
			// Add inversedBy attribute for ManyToOne
			if (!empty($property['inversedBy'])) {
				$comment .= " inversedBy=\"{$property['inversedBy']}\"";
			}
			
			// Add fetch="LAZY" for OneToMany relationships
			if ($relationshipType === 'OneToMany') {
				$comment .= " fetch=\"LAZY\"";
			}
			
			// Add nullable attribute if specified
			if ($nullable) {
				$comment .= " nullable=true";
			}
			
			// If we have a referenced column name that's not the default 'id',
			// we can store it as a custom attribute in the annotation
			if (isset($property['referencedColumnName']) && $property['referencedColumnName'] !== 'id') {
				$comment .= " referencedColumnName=\"{$property['referencedColumnName']}\"";
			}
			
			// Add a join column name if provided
			if (!empty($property['joinColumnName'])) {
				$comment .= " joinColumnName=\"{$property['joinColumnName']}\"";
			}
			
			$comment .= ")";
			
			// Add PHPDoc var type for collections
			if ($relationshipType === 'OneToMany') {
				$comment .= "\n       * @var \$" . $property['name'] . " CollectionInterface<" . $targetEntity . "Entity>";
			}
			
			$comment .= "\n       */";
			
			return $comment;
		}
		
		/**
		 * Generate a property definition
		 * @param array $property Property information
		 * @return string Property definition
		 */
		protected function generatePropertyDefinition(array $property): string {
			$nullable = $property['nullable'] ?? false;
			
			// Handle relationship types
			if (isset($property['relationshipType'])) {
				$type = $property['type'];
				$nullableIndicator = $nullable ? '?' : '';
				
				return "protected {$nullableIndicator}{$type} \${$property['name']};";
			}
			
			// Handle regular properties
			$type = $property['type'] ?? 'string';
			$phpType = $this->typeMapper->phinxTypeToPhpType($type);
			$nullableIndicator = $nullable ? '?' : '';
			
			return "protected {$nullableIndicator}{$phpType} \${$property['name']};";
		}
		
		
		/**
		 * Generate a getter method for a property
		 * @param array $property Property information
		 * @return string Getter method code
		 */
		protected function generateGetter(array $property): string {
			$propertyName = $property['name'];
			$methodName = 'get' . ucfirst($propertyName);
			
			// Handle relationship getter
			if (isset($property['relationshipType'])) {
				$type = $property['type'];
				$nullable = $property['nullable'] ?? false;
				$nullableIndicator = $nullable ? '?' : '';
				
				// Specially handle OneToMany collection getters
				if ($property['relationshipType'] === 'OneToMany') {
					$targetEntity = $property['targetEntity'] . 'Entity';
					
					return "\n      /**\n" .
						"       * @return CollectionInterface<{$targetEntity}>\n" .
						"       */\n" .
						"      public function {$methodName}(): CollectionInterface {\n" .
						"         return \$this->{$propertyName};\n" .
						"      }\n";
				}
				
				return "\n      /**\n" .
					"       * Get {$propertyName}\n" .
					"       * @return {$nullableIndicator}{$type}\n" .
					"       */\n" .
					"      public function {$methodName}(): {$nullableIndicator}{$type} {\n" .
					"         return \$this->{$propertyName};\n" .
					"      }\n";
			}
			
			// Handle regular property getter
			$nullable = $property['nullable'] ?? false;
			$type = $property['type'] ?? 'string';
			$phpType = $this->typeMapper->phinxTypeToPhpType($type);
			$nullableIndicator = $nullable ? '?' : '';
			
			return "\n      /**\n" .
				"       * Get {$propertyName}\n" .
				"       * @return {$nullableIndicator}{$phpType}\n" .
				"       */\n" .
				"      public function {$methodName}(): {$nullableIndicator}{$phpType} {\n" .
				"         return \$this->{$propertyName};\n" .
				"      }\n";
		}
		
		/**
		 * Generate a setter method for a property
		 * @param array $property Property information
		 * @return string Setter method code
		 */
		protected function generateSetter(array $property): string {
			$propertyName = $property['name'];
			$methodName = 'set' . ucfirst($propertyName);
			
			// Handle relationship setter (ManyToOne, OneToOne relationships)
			if (isset($property['relationshipType'])) {
				$type = $property['type'];
				$nullable = $property['nullable'] ?? false;
				$nullableIndicator = $nullable ? '?' : '';
				
				return "\n      /**\n" .
					"       * Set {$propertyName}\n" .
					"       * @param {$nullableIndicator}{$type} \${$propertyName}\n" .
					"       * @return \$this\n" .
					"       */\n" .
					"      public function {$methodName}({$nullableIndicator}{$type} \${$propertyName}): self {\n" .
					"         \$this->{$propertyName} = \${$propertyName};\n" .
					"         return \$this;\n" .
					"      }\n";
			}
			
			// Handle regular property setter
			$nullable = $property['nullable'] ?? false;
			$type = $property['type'] ?? 'string';
			$phpType = $this->typeMapper->phinxTypeToPhpType($type);
			$nullableIndicator = $nullable ? '?' : '';
			
			return "\n      /**\n" .
				"       * Set {$propertyName}\n" .
				"       * @param {$nullableIndicator}{$phpType} \${$propertyName}\n" .
				"       * @return \$this\n" .
				"       */\n" .
				"      public function {$methodName}({$nullableIndicator}{$phpType} \${$propertyName}): self {\n" .
				"         \$this->{$propertyName} = \${$propertyName};\n" .
				"         return \$this;\n" .
				"      }\n";
		}
		
		/**
		 * Generate a method to add an item to a collection (for OneToMany)
		 * @param array $property Collection property information
		 * @param string $entityName Current entity name (without suffix)
		 * @return string Method code
		 */
		protected function generateCollectionAdder(array $property, string $entityName): string {
			$collectionName = $property['name'];
			$singularName = $this->getSingularName($collectionName);
			$methodName = 'add' . ucfirst($singularName);
			$targetEntity = $property['targetEntity'] . 'Entity';
			
			$inverseSetter = '';
			if (!empty($property['mappedBy'])) {
				$setterMethod = 'set' . ucfirst($entityName);
				$inverseSetter = "\n         // Set the owning side of the relationship\n";
				$inverseSetter .= "         \${$singularName}->{$setterMethod}(\$this);";
			}
			
			return "\n      /**\n" .
				"       * Adds a relation between {$targetEntity} and {$targetEntity}\n" .
				"       * @param {$targetEntity} \${$singularName}\n" .
				"       * @return \$this\n" .
				"       */\n" .
				"      public function {$methodName}({$targetEntity} \${$singularName}): self {\n" .
				"         if (!\$this->{$collectionName}->contains(\${$singularName})) {\n" .
				"            \$this->{$collectionName}[] = \${$singularName};{$inverseSetter}\n" .
				"         }\n" .
				"         return \$this;\n" .
				"      }\n";
		}
		
		/**
		 * Generate a method to remove an item from a collection (for OneToMany)
		 * @param array $property Collection property information
		 * @param string $entityName Current entity name (without suffix)
		 * @return string Method code
		 */
		protected function generateCollectionRemover(array $property, string $entityName): string {
			$collectionName = $property['name'];
			$singularName = $this->getSingularName($collectionName);
			$methodName = 'remove' . ucfirst($singularName);
			$targetEntity = $property['targetEntity'] . 'Entity';
			
			$inverseRemover = '';
			
			if (!empty($property['mappedBy'])) {
				$setterMethod = 'set' . ucfirst($entityName);
				$inverseRemover = "\n            // Unset the owning side of the relationship\n";
				$inverseRemover .= "            \${$singularName}->{$setterMethod}(null);";
			}
			
			$targetEntityBase = substr($targetEntity, 0, -6);
			
			return "\n      /**\n" .
				"       * Removes a relation between {$targetEntity} and {$targetEntityBase}\n" .
				"       * @param {$targetEntity} \${$singularName}\n" .
				"       * @return \$this\n" .
				"       */\n" .
				"      public function {$methodName}({$targetEntity} \${$singularName}): self {\n" .
				"         if (\$this->{$collectionName}->remove(\${$singularName})) {\n" .
				"            {$inverseRemover}\n" .
				"         }\n" .
				"         \n" .
				"         return \$this;\n" .
				"      }\n";
		}
		
		/**
		 * Updates constructor to initialize new OneToMany collections
		 * This function serves as the main entry point for modifying entity classes
		 * to ensure proper initialization of OneToMany relationship collections
		 * @param string $content Entity file content - the full PHP class definition as a string
		 * @param array $oneToManyProperties OneToMany properties to initialize - array of property details
		 * @return string Updated content - the modified class content with proper collection initialization
		 */
		protected function updateConstructor(string $content, array $oneToManyProperties): string {
			// First, determine if a constructor already exists in the class
			// This check uses a regex pattern that matches constructors with any visibility
			// (public, private, protected) or with no explicit visibility modifier
			if ($this->constructorExists($content)) {
				// If a constructor exists, we need to modify it to include our collection initializations
				// without disrupting any existing code in the constructor
				// This approach preserves existing constructor logic while adding new initializations
				return $this->updateExistingConstructor($content, $oneToManyProperties);
			} else {
				// If no constructor exists, we need to create a new one from scratch
				// The new constructor will contain only the collection initializations
				// It will be placed in the appropriate position within the class structure
				return $this->addNewConstructor($content, $oneToManyProperties);
			}
			
			// Note: Both paths ensure that all OneToMany properties are properly initialized
			// to prevent "Attempting to call methods on a non-object" errors when the
			// entity is instantiated and collections are accessed before items are added
		}
		
		/**
		 * Checks if a constructor exists in the class
		 * This function determines whether an entity class already has a constructor method
		 * defined, to decide whether to add a new constructor or update an existing one
		 * @param string $content Entity file content - the full PHP class definition as a string
		 * @return bool True if constructor exists - indicates whether __construct method is present
		 */
		protected function constructorExists(string $content): bool {
			// Use regular expression to search for constructor method signature
			return preg_match('/\s+((?:public|private|protected)\s+)?function\s+__construct\s*\(/i', $content) === 1;
		}
		
		/**
		 * Locates the starting character position of a constructor method within class content
		 * This function scans the provided PHP code to find the exact position where
		 * the constructor method declaration begins. It supports finding constructors
		 * with any visibility modifier (public, protected, private) or no modifier.
		 * @param string $content The complete PHP file content containing the entity class
		 * @return int|null The character position where constructor starts (pointing to the whitespace
		 *                  before visibility or function keyword), or null if no constructor exists
		 */
		protected function getConstructorStartPos(string $content): ?int {
			// Attempt to locate constructor method using regex pattern
			if (!preg_match('/\s+((?:public|private|protected)\s+)?function\s+__construct\s*\(/i', $content, $constructorMatch, PREG_OFFSET_CAPTURE)) {
				return null;
			}
			
			// Return the precise character position where the constructor match begins
			// This corresponds to the whitespace before the method declaration
			return $constructorMatch[0][1];
		}
		
		/**
		 * Updates an existing constructor to initialize OneToMany collections
		 * This function modifies a class constructor that already exists in the entity
		 * by adding collection initializations for OneToMany relationships
		 * @param string $content Entity file content - the full PHP class definition as a string
		 * @param array $oneToManyProperties OneToMany properties to initialize - array of property details
		 * @return string Updated content - the modified class content with constructor updated
		 */
		protected function updateExistingConstructor(string $content, array $oneToManyProperties): string {
			// Extract the position where the constructor method signature was found
			$constructorStart = $this->getConstructorStartPos($content);
			
			// Find the position of the opening brace that starts the constructor body
			$openBracePos = strpos($content, '{', $constructorStart);
			
			// Safety check - if no opening brace found, return original content
			if ($openBracePos === false) {
				// Something wrong with the constructor format
				// This should rarely happen as the regex already matched a brace,
				// but provides a fallback for malformed code
				return $content;
			}
			
			// Find the matching closing brace that ends the constructor body
			// This uses our specialized brace-matching function to handle nested braces
			$constructorEnd = $this->findClosingBrace($content, $openBracePos);
			
			// Safety check - if no closing brace found, return original content
			if ($constructorEnd === null) {
				// Failed to find constructor end
				// Could happen with syntax errors or incomplete code
				return $content;
			}
			
			// Generate the code needed to initialize collections
			// The function checks which properties need initialization (avoiding duplicates)
			$initCode = $this->generateCollectionInitializations($content, $oneToManyProperties);
			
			// Only modify the constructor if we have new initializations to add
			if (!empty($initCode)) {
				// Insert the initialization code just before the constructor's closing brace
				// Maintaining proper indentation with a newline and tab
				return substr($content, 0, $constructorEnd) . $initCode . "\n\t" . substr($content, $constructorEnd);
			}
			
			// If no new initializations needed, return the original content unchanged
			return $content;
		}
		
		/**
		 * Finds the position of a closing brace that matches the opening brace at given position
		 * This function implements a brace-matching algorithm to locate the correct closing brace
		 * that corresponds to a specific opening brace, accounting for nested braces
		 * @param string $content Content to search in - the source code as a string
		 * @param int $openBracePos Position of the opening brace - the character index of '{'
		 * @return int|null Position of the closing brace or null if not found - returns character index of matching '}'
		 */
		protected function findClosingBrace(string $content, int $openBracePos): ?int {
			// Start searching from the character after the opening brace
			$offset = $openBracePos + 1;
			
			// Initialize brace level counter to track nesting depth
			// Starting at 1 because we've already encountered the opening brace
			$braceLevel = 1;
			
			// Continue searching until we reach the end of content or find the matching brace
			while ($offset < strlen($content) && $braceLevel > 0) {
				// Check current character
				if ($content[$offset] === '{') {
					// Found another opening brace, increase nesting level
					$braceLevel++;
				} elseif ($content[$offset] === '}') {
					// Found a closing brace, decrease nesting level
					$braceLevel--;
					
					// If we've returned to level 0, we've found our matching closing brace
					if ($braceLevel === 0) {
						// Return the position of the matching closing brace
						return $offset;
					}
				}
				
				// Move to next character
				$offset++;
			}
			
			// If we've reached the end of the content without finding a matching brace
			// or if braceLevel is still > 0, then return null to indicate no match found
			return null;
		}
		
		/**
		 * Generates code for collection initializations
		 * This function creates the PHP code statements needed to initialize Collection objects
		 * for entity OneToMany relationships, avoiding duplicate initializations
		 * @param string $content Entity file content - the full PHP class definition as a string
		 * @param array $oneToManyProperties OneToMany properties to initialize - array of property details
		 * @return string Code for initializing collections - PHP statements as a string, ready to be inserted
		 */
		protected function generateCollectionInitializations(string $content, array $oneToManyProperties): string {
			// Initialize empty string to store the collection initialization code
			$initCode = '';
			
			// Process each OneToMany property from the provided array
			foreach ($oneToManyProperties as $property) {
				// Extract the property name from the property details array
				$propertyName = $property['name'];
				
				// Check if this collection is already initialized in constructor.
				// Using regex to find any existing initialization for this specific property.
				// Format searched: $this->propertyName = new Collection()
				// preg_quote ensures special characters in property names are escaped properly
				if (!preg_match('/\$this->' . preg_quote($propertyName, '/') . '\s*=\s*new\s+Collection\(\)/', $content)) {
					// Only add initialization if not already present
					// Maintains proper indentation with tabs for code readability
					$initCode .= "\n\t\t\$this->{$propertyName} = new Collection();";
				}
			}
			
			// Return the complete initialization code block
			// If no new initializations needed, returns empty string
			return $initCode;
		}
		
		/**
		 * Adds a new constructor to initialize OneToMany collections
		 * This function generates a constructor method that initializes Collection objects
		 * for OneToMany relationship properties in an entity class
		 * @param string $content Entity file content - the full PHP class definition as a string
		 * @param array $oneToManyProperties OneToMany properties to initialize - array of property details
		 * @return string Updated content - the modified class content with constructor added
		 */
		protected function addNewConstructor(string $content, array $oneToManyProperties): string {
			// Create constructor method with proper PHPDoc comment block
			$constructorCode = "\n\t/**\n\t * Constructor to initialize collections\n\t */\n\tpublic function __construct() {";
			
			// Iterate through each OneToMany property and add initialization code
			foreach ($oneToManyProperties as $property) {
				$propertyName = $property['name'];
				// Initialize each collection property with a new Collection instance
				// This prevents "null" errors when adding items to the collection later
				$constructorCode .= "\n\t\t\$this->{$propertyName} = new Collection();";
			}
			
			// Close the constructor method
			$constructorCode .= "\n\t}\n";
			
			// Determine where to place the constructor in the class definition
			// Typically after properties but before other methods
			$insertPosition = $this->findConstructorInsertPosition($content);
			
			// Only insert if a valid position was found
			if ($insertPosition !== null) {
				// Splice the constructor into the content at the appropriate position
				// Add a newline before the constructor for clean formatting
				return substr($content, 0, $insertPosition) . "\n" . $constructorCode . substr($content, $insertPosition);
			}
			
			// Return original content if no suitable insertion position found
			return $content;
		}
		
		/**
		 * Finds the position to insert a new constructor in an entity file
		 * This function analyzes PHP class content to determine the optimal position
		 * for inserting a constructor method, following standard code organization practices
		 * @param string $content The complete PHP file content containing the entity class
		 * @return int|null Position (character index) to insert constructor or null if suitable position not found
		 */
		protected function findConstructorInsertPosition(string $content): ?int {
			// Search for the class declaration using a regex pattern.
			if (!preg_match('/class\s+[^{]+\{/i', $content, $classMatch, PREG_OFFSET_CAPTURE)) {
				return null;
			}
			
			// Find the position of the opening brace '{' of the class
			$classOpenBracePos = strpos($content, '{', $classMatch[0][1]);
			
			if ($classOpenBracePos === false) {
				return null;
			}
			
			// Search for all property declarations in the class
			// This regex matches:
			// 1. Properties with visibility modifiers (protected, private, public)
			// 2. Properties without visibility modifiers (directly starting with $)
			// The regex uses a non-capturing group (?:) with alternation to handle both cases
			preg_match_all('/(?:(protected|private|public)\s+|^\s*)\$[^;]+;/im', $content, $propertyMatches, PREG_OFFSET_CAPTURE);
			
			if (!empty($propertyMatches[0])) {
				// If properties are found, we'll insert the constructor after the last property
				$lastPropertyPos = $propertyMatches[0][count($propertyMatches[0]) - 1][1];
				return strpos($content, ';', $lastPropertyPos) + 1;
			}
			
			// If no property declarations were found, insert after class opening brace
			return $classOpenBracePos + 1;
		}
		
		/**
		 * Convert a string to snake case
		 * @url https://stackoverflow.com/questions/40514051/using-preg-replace-to-convert-camelcase-to-snake-case
		 * @param string $string
		 * @return string
		 */
		protected function snakeCase(string $string): string {
			return strtolower(preg_replace(['/([a-z\d])([A-Z])/', '/([^_])([A-Z][a-z])/'], '$1_$2', $string));
		}
		
		/**
		 * Attempts to get the singular form of a collection name using basic English pluralization rules
		 * This function applies simple rules to convert plural nouns to singular form
		 * @param string $pluralName The plural name to convert to singular form
		 * @return string The singular form of the name
		 */
		protected function getSingularName(string $pluralName): string {
			// Rule 1: Words ending in 'ies' typically come from singular words ending in 'y'
			// Examples: entities -> entity, categories -> category, properties -> property
			if (str_ends_with($pluralName, 'ies')) {
				// Remove 'ies' suffix and add 'y'
				return substr($pluralName, 0, -3) . 'y';
			}
			
			// Rule 2: Words ending in 's' but not 'ss' are typically regular plurals
			// Examples: cars -> car, books -> book
			// The 'ss' check avoids incorrectly transforming words like 'address' to 'addres'
			if (str_ends_with($pluralName, 's') && !str_ends_with($pluralName, 'ss')) {
				// Remove the trailing 's'
				return substr($pluralName, 0, -1);
			}
			
			// Rule 3: If no rules match, return the original name unchanged
			// This handles:
			// - Words that are already singular
			// - Irregular plurals not covered by these simple rules
			// - Uncountable nouns that don't have distinct singular/plural forms
			return $pluralName;
		}
		
		/**
		 * Pluralize a word
		 * @param string $word
		 * @return array|string|null
		 */
		private function pluralize(string $word): array|string|null {
			// Convert to lowercase for rule checking
			$lower = strtolower($word);
			
			// Irregular plurals
			$irregulars = [
				'child'   => 'children',
				'person'  => 'people',
				'man'     => 'men',
				'woman'   => 'women',
				'tooth'   => 'teeth',
				'foot'    => 'feet',
				'mouse'   => 'mice',
				'goose'   => 'geese',
				'ox'      => 'oxen',
				'sheep'   => 'sheep',
				'deer'    => 'deer',
				'fish'    => 'fish',
				'series'  => 'series',
				'species' => 'species',
			];
			
			if (isset($irregulars[$lower])) {
				return $irregulars[$lower];
			}
			
			// Words ending in 's', 'ss', 'sh', 'ch', 'x', 'z' -> add 'es'
			if (preg_match('/[sxz]$|[cs]h$/', $lower)) {
				return $word . 'es';
			}
			
			// Words ending in consonant + 'y' -> change 'y' to 'ies'
			if (preg_match('/[^aeiou]y$/', $lower)) {
				return substr($word, 0, -1) . 'ies';
			}
			
			// Words ending in 'f' or 'fe' -> change to 'ves'
			if (preg_match('/fe?$/', $lower)) {
				return preg_replace('/fe?$/', 'ves', $word);
			}
			
			// Words ending in consonant + 'o' -> add 'es'
			if (preg_match('/[^aeiou]o$/', $lower)) {
				return $word . 'es';
			}
			
			// Default: just add 's'
			return $word . 's';
		}
	}