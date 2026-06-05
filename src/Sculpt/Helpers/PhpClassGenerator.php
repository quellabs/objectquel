<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\Support\StringInflector;
	use Quellabs\ObjectQuel\Sculpt\SculptTypes;
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	
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
	class PhpClassGenerator {
		
		/**
		 * Generates getter method with proper type hints and docblock
		 * @param array $property Property metadata
		 * @phpstan-param PropertyDefinition $property Property metadata
		 * @return string Complete getter method
		 */
		public function generateGetter(array $property): string {
			$propertyName = $property['name'];
			$methodName = 'get' . ucfirst($propertyName);
			
			// Relationship getters need the entity type rather than a primitive
			if (isset($property['relationshipType'])) {
				$type = $property['type'];
				$nullable = $property['nullable'] ?? false;
				$nullableIndicator = $nullable ? '?' : '';
				
				// InverseOf collection returns a typed CollectionInterface instead of a single entity
				if ($property['collection'] ?? false) {
					$targetEntity = $property['targetEntity'] . 'Entity';
					
					$code = "\n";
					$code .= "/**\n";
					$code .= " * Gets the {$propertyName} collection\n";
					$code .= " * @return CollectionInterface<{$targetEntity}>\n";
					$code .= " */\n";
					$code .= "public function {$methodName}(): CollectionInterface {\n";
					$code .= "\treturn \$this->{$propertyName};\n";
					$code .= "}\n";
					
					return $code;
				}
				
				// OneToOne / ManyToOne return a nullable entity reference
				$code = "\n";
				$code .= "/**\n";
				$code .= " * Gets the {$propertyName} relationship\n";
				$code .= " * @return {$nullableIndicator}{$type}\n";
				$code .= " */\n";
				$code .= "public function {$methodName}(): {$nullableIndicator}{$type} {\n";
				$code .= "\treturn \$this->{$propertyName};\n";
				$code .= "}\n";
				
				return $code;
			}
			
			// Plain column getter
			$nullable = $property['nullable'] ?? false;
			$nullableIndicator = $nullable ? '?' : '';
			$phpType = $this->resolvePhpType($property);
			
			$code = "\n";
			$code .= "/**\n";
			$code .= " * Gets the {$propertyName} value\n";
			$code .= " * @return {$nullableIndicator}{$phpType}\n";
			$code .= " */\n";
			$code .= "public function {$methodName}(): {$nullableIndicator}{$phpType} {\n";
			$code .= "\treturn \$this->{$propertyName};\n";
			$code .= "}\n";
			
			return $code;
		}
		
		/**
		 * Generates setter method with fluent interface and bidirectional sync
		 * @param array $property Property metadata
		 * @phpstan-param PropertyDefinition $property Property metadata
		 * @return string Complete setter method
		 */
		public function generateSetter(array $property): string {
			$propertyName = $property['name'];
			$methodName = 'set' . ucfirst($propertyName);
			
			// Relationship setters need bidirectional sync logic
			if (isset($property['relationshipType'])) {
				$type = $property['type'];
				$nullable = $property['nullable'] ?? false;
				$nullableIndicator = $nullable ? '?' : '';
				
				// Identity check short-circuits the setter when the value hasn't actually changed,
				// which prevents infinite loops in bidirectional sync chains
				$setterBody = "\t// Prevent redundant updates\n";
				$setterBody .= "\tif (\$this->{$propertyName} === \${$propertyName}) {\n";
				$setterBody .= "\t\treturn \$this;\n";
				$setterBody .= "\t}\n";
				
				// Before reassigning, remove this entity from the previous parent's collection
				// so the old parent's inverse side stays consistent
				if ($property['relationshipType'] === 'ManyToOne' && !empty($property['referencedColumn'])) {
					$singularName = StringInflector::singularize($property['referencedColumn']);
					$removerMethod = 'remove' . ucfirst($singularName);
					
					$setterBody .= "\n";
					$setterBody .= "\t// Remove from previous parent's collection\n";
					$setterBody .= "\t\$this->{$propertyName}?->{$removerMethod}(\$this);\n";
				}
				
				$setterBody .= "\n";
				$setterBody .= "\t// Set new property\n";
				$setterBody .= "\t\$this->{$propertyName} = \${$propertyName};\n";
				
				// Add this entity to the new parent's collection to keep the inverse side in sync
				if ($property['relationshipType'] === 'ManyToOne' && !empty($property['referencedColumn'])) {
					$singularName = StringInflector::singularize($property['referencedColumn']);
					$adderMethod = 'add' . ucfirst($singularName);
					
					$setterBody .= "\t\${$propertyName}?->{$adderMethod}(\$this);";
				}
				
				$code = "\n";
				$code .= "/**\n";
				$code .= " * Sets the {$propertyName} relationship\n";
				$code .= " * @param {$nullableIndicator}{$type} \${$propertyName} The related entity\n";
				$code .= " * @return \$this\n";
				$code .= " */\n";
				$code .= "public function {$methodName}({$nullableIndicator}{$type} \${$propertyName}): self {\n";
				$code .= $setterBody;
				$code .= "\n";
				$code .= "\treturn \$this;\n";
				$code .= "}\n";
				
				return $code;
			}
			
			// Plain column setter — no sync logic needed
			$nullable = $property['nullable'] ?? false;
			$nullableIndicator = $nullable ? '?' : '';
			$phpType = $this->resolvePhpType($property);
			
			$code = "\n";
			$code .= "/**\n";
			$code .= " * Sets the {$propertyName} value\n";
			$code .= " * @param {$nullableIndicator}{$phpType} \${$propertyName} New value to set\n";
			$code .= " * @return \$this\n";
			$code .= " */\n";
			$code .= "public function {$methodName}({$nullableIndicator}{$phpType} \${$propertyName}): self {\n";
			$code .= "\t\$this->{$propertyName} = \${$propertyName};\n";
			$code .= "\treturn \$this;\n";
			$code .= "}\n";
			
			return $code;
		}
		
		/**
		 * Generates method to add item to InverseOf collection
		 * Checks for duplicates and syncs the inverse side of bidirectional relationships.
		 * @phpstan-param RelationProperty $property
		 * @param array $property Collection property metadata
		 * @param string $entityName Current entity name
		 * @return string Complete adder method
		 */
		public function generateCollectionAdder(array $property, string $entityName): string {
			$collectionName = $property['name'];
			$singularName = StringInflector::singularize($collectionName);
			$methodName = 'add' . ucfirst($singularName);
			$targetEntity = $property['targetEntity'] . 'Entity';
			
			$inverseSetter = '';
			
			// If relation is set this is the inverse side — sync the owning side's reference
			// so both ends of the relationship stay consistent after the add
			if (!empty($property['relation'])) {
				$setterMethod = 'set' . ucfirst($property['relation']);
				$inverseSetter = "\n\t\t// Assign this entity on the owning side so the FK is set correctly\n";
				$inverseSetter .= "\t\t\${$singularName}->{$setterMethod}(\$this);";
			}
			
			$code = "\n";
			$code .= "/**\n";
			$code .= " * Adds an entity to the {$collectionName} collection\n";
			$code .= " * @param {$targetEntity} \${$singularName} Entity to add\n";
			$code .= " * @return \$this\n";
			$code .= " */\n";
			$code .= "public function {$methodName}({$targetEntity} \${$singularName}): self {\n";
			$code .= "\tif (!\$this->{$collectionName}->contains(\${$singularName})) {\n";
			$code .= "\t\t\$this->{$collectionName}[] = \${$singularName};{$inverseSetter}\n";
			$code .= "\t}\n";
			$code .= "\treturn \$this;\n";
			$code .= "}\n";
			
			return $code;
		}
		
		/**
		 * Generates method to remove item from InverseOf collection
		 * @phpstan-param RelationProperty $property
		 * @param array $property Collection property metadata
		 * @param string $entityName Current entity name
		 * @return string Complete remover method
		 */
		public function generateCollectionRemover(array $property, string $entityName): string {
			$collectionName = $property['name'];
			$singularName = StringInflector::singularize($collectionName);
			$methodName = 'remove' . ucfirst($singularName);
			$targetEntity = $property['targetEntity'] . 'Entity';
			
			// Only null out the inverse side when it still points at this entity —
			// avoids clobbering a reference that was already reassigned elsewhere
			$inverseRemover = '';
			
			if (!empty($property['relation'])) {
				$viaField = $property['relation'];
				$getterMethod = 'get' . ucfirst($viaField);
				$setterMethod = 'set' . ucfirst($viaField);
				
				$inverseRemover = "\t\t// Unset inverse side if it still references this entity\n";
				$inverseRemover .= "\t\tif (\${$singularName}->{$getterMethod}() === \$this) {\n";
				$inverseRemover .= "\t\t\t\${$singularName}->{$setterMethod}(null);\n";
				$inverseRemover .= "\t\t}";
			}
			
			$code = "\n";
			$code .= "/**\n";
			$code .= " * Removes an entity from the {$collectionName} collection\n";
			$code .= " * @param {$targetEntity} \${$singularName} Entity to remove\n";
			$code .= " * @return \$this\n";
			$code .= " */\n";
			$code .= "public function {$methodName}({$targetEntity} \${$singularName}): self {\n";
			$code .= "\tif (\$this->{$collectionName}->remove(\${$singularName})) {\n";
			$code .= "\t\t{$inverseRemover}\n";
			$code .= "\t}\n";
			$code .= "\n";
			$code .= "\treturn \$this;\n";
			$code .= "}\n";
			
			return $code;
		}
		
		/**
		 * Generates ORM relationship annotation docblock
		 * @phpstan-param RelationProperty $property
		 * @param array $property Relationship metadata (targetEntity, relation, referencedColumn, etc.)
		 * @return string PHPDoc comment with relationship annotation
		 */
		public function generateRelationshipDocComment(array $property): string {
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
			$comment = "/**\n * @Orm\\{$relationshipType}(targetEntity=\"{$targetEntity}Entity\"{$optionsStr})";
			
			// InverseOf collections get an additional @var hint so IDEs know the collection's generic type
			if ($relationshipType === 'InverseOf' && ($property['collection'] ?? false)) {
				$comment .= "\n * @var CollectionInterface<{$targetEntity}Entity>";
			}
			
			$comment .= "\n */";
			
			return $comment;
		}
		
		/**
		 * Generates the $id property snippet for the auto-increment primary key.
		 * Returns a canonical tab-indented snippet; PhpClassEditor applies the
		 * file-level indentation when inserting.
		 * @return string Snippet: docblock + property declaration
		 */
		public function generateIdProperty(): string {
			$code = "\n";
			$code .= "/**\n";
			$code .= " * @Orm\\Column(name=\"id\", type=\"integer\", unsigned=true, primary_key=true)\n";
			$code .= " * @Orm\\PrimaryKeyStrategy(strategy=\"identity\")\n";
			$code .= " */\n";
			$code .= "protected ?int \$id = null;";
			
			return $code;
		}
		
		/**
		 * Generates ORM Column annotation docblock for regular properties
		 * @param array $property Property metadata (name, type, nullable, limit, precision, etc.)
		 * @phpstan-param PropertyDefinition $property Property metadata (name, type, nullable, limit, precision, etc.)
		 * @return string PHPDoc comment with @Orm\Column annotation
		 */
		public function generatePropertyDocComment(array $property): string {
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
			
			return "/**\n"
				. " * @Orm\\Column({$propertiesString})\n"
				. " */";
		}
		
		/**
		 * Generates typed property declaration
		 * @param array $property Property metadata
		 * @phpstan-param PropertyDefinition $property Property metadata
		 * @return string Property declaration with type hint
		 */
		public function generatePropertyDefinition(array $property): string {
			$nullable = $property['nullable'] ?? false;
			$nullableIndicator = $nullable ? '?' : '';
			
			// Relationship properties use the entity class name as their type hint
			if (isset($property['relationshipType'])) {
				$type = $property['type'];
				
				// InverseOf collection properties must be public and non-nullable —
				// they are always initialized in the constructor to an empty Collection
				if ($property['collection'] ?? false) {
					return "public {$type} \${$property['name']};";
				}
				
				return "protected {$nullableIndicator}{$type} \${$property['name']};";
			}
			
			// Regular properties map the database column type to an equivalent PHP type
			$phpType = $this->resolvePhpType($property);
			return "protected {$nullableIndicator}{$phpType} \${$property['name']};";
		}
		
		/**
		 * Generate a new constructor and populate it with collections
		 * @param array<int, PropertyDefinition> $inverseOfProperties Collections to initialize
		 * @return string
		 */
		public function generateConstructor(array $inverseOfProperties): string {
			$indent = PhpClassEditor::INDENT;
			
			$snippet = "\n";
			$snippet .= "/**\n";
			$snippet .= " * Constructor to initialize collections\n";
			$snippet .= "*/\n";
			$snippet .= "public function __construct() {\n";
			
			foreach ($inverseOfProperties as $property) {
				$snippet .= "{$indent}\$this->{$property['name']} = new Collection();\n";
			}
			
			$snippet .= "}\n";
			$snippet .= "\n";
			return $snippet;
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
		
	}