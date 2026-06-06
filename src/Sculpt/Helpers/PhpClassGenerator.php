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
		 * Generates typed property declaration
		 * @param array $property Property metadata
		 * @phpstan-param PropertyDefinition $property Property metadata
		 * @return string Property declaration with type hint
		 */
		public function generatePropertyDefinition(array $property): string {
			// InverseOf collection properties must be public and non-nullable —
			// they are always initialized in the constructor to an empty Collection
			if (($property['collection'] ?? false) && isset($property['relationshipType'])) {
				return "public {$property['type']} \${$property['name']};";
			}
			
			// Returns '?' when the property is nullable, '' otherwise.
			$nullableIndicator = $this->buildNullableIndicator($property);
			
			// Relationship properties use the entity class name as their type hint
			if (isset($property['relationshipType'])) {
				return "protected {$nullableIndicator}{$property['type']} \${$property['name']};";
			}
			
			// Regular properties map the database column type to an equivalent PHP type
			$phpType = $this->resolvePhpType($property);
			return "protected {$nullableIndicator}{$phpType} \${$property['name']};";
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
			$propertiesString = implode(", ", $this->buildColumnAnnotationAttributes($property));
			
			$code = "/**\n";
			$code .= " * @Orm\\Column({$propertiesString})\n";
			$code .= " */";
			
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
			
			// Build the optional attribute list (relation, referencedColumn, fetch, localColumn)
			// and format the primary @Orm annotation line
			$optionsStr = $this->buildRelationshipAnnotationOptions($property);
			$comment = "/**\n * @Orm\\{$relationshipType}(targetEntity=\"{$targetEntity}Entity\"{$optionsStr})";
			
			// InverseOf collections get an additional @var hint so IDEs know the collection's generic type
			if ($relationshipType === 'InverseOf' && ($property['collection'] ?? false)) {
				$comment .= "\n * @var CollectionInterface<{$targetEntity}Entity>";
			}
			
			// Close the docblock
			$comment .= "\n */";
			
			return $comment;
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
		 * Generates getter method with proper type hints and docblock
		 * @param array $property Property metadata
		 * @phpstan-param PropertyDefinition $property Property metadata
		 * @return string Complete getter method
		 */
		public function generateGetter(array $property): string {
			$propertyName = $property['name'];
			$methodName = 'get' . ucfirst($propertyName);
			
			// InverseOf collection returns a typed CollectionInterface instead of a single entity
			if (isset($property['relationshipType']) && ($property['collection'] ?? false)) {
				$targetEntity = $property['targetEntity'] . 'Entity';
				$docComment = $this->buildDocComment("Gets the {$propertyName} collection", ["@return CollectionInterface<{$targetEntity}>"]);
				return $this->buildSimpleMethod($docComment, "public function {$methodName}(): CollectionInterface", "\treturn \$this->{$propertyName};");
			}
			
			// Returns '?' when the property is nullable, '' otherwise.
			$nullableIndicator = $this->buildNullableIndicator($property);
			
			// Relationship getter: OneToOne / ManyToOne return a nullable entity reference
			if (isset($property['relationshipType'])) {
				$type = $property['type'];
				$docComment = $this->buildDocComment("Gets the {$propertyName} relationship", ["@return {$nullableIndicator}{$type}"]);
				return $this->buildSimpleMethod($docComment, "public function {$methodName}(): {$nullableIndicator}{$type}", "\treturn \$this->{$propertyName};");
			}
			
			// Plain column getter
			$phpType = $this->resolvePhpType($property);
			$docComment = $this->buildDocComment("Gets the {$propertyName} value", ["@return {$nullableIndicator}{$phpType}"]);
			return $this->buildSimpleMethod($docComment, "public function {$methodName}(): {$nullableIndicator}{$phpType}", "\treturn \$this->{$propertyName};");
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
			$nullableIndicator = $this->buildNullableIndicator($property);
			
			// Relationship setters need bidirectional sync logic
			if (isset($property['relationshipType'])) {
				$type = $property['type'];
				
				$docComment = $this->buildDocComment("Sets the {$propertyName} relationship", [
					"@param {$nullableIndicator}{$type} \${$propertyName} The related entity",
					"@return \$this",
				]);
				
				$body = $this->buildRelationshipSetterBody($property);
				
				return "\n{$docComment}\npublic function {$methodName}({$nullableIndicator}{$type} \${$propertyName}): self {\n{$body}\n\treturn \$this;\n}\n";
			}
			
			// Plain column setter — no sync logic needed
			$phpType = $this->resolvePhpType($property);
			
			$docComment = $this->buildDocComment("Sets the {$propertyName} value", [
				"@param {$nullableIndicator}{$phpType} \${$propertyName} New value to set",
				"@return \$this",
			]);
			
			$body = "\t\$this->{$propertyName} = \${$propertyName};\n\treturn \$this;";
			
			return "\n{$docComment}\npublic function {$methodName}({$nullableIndicator}{$phpType} \${$propertyName}): self {\n{$body}\n}\n";
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
			
			// If relation is set this is the inverse side — sync the owning side's reference
			// so both ends of the relationship stay consistent after the add
			$inverseSetter = '';
			
			if (!empty($property['relation'])) {
				$setterMethod = 'set' . ucfirst($property['relation']);
				$inverseSetter = "\n\t\t// Assign this entity on the owning side so the FK is set correctly\n";
				$inverseSetter .= "\t\t\${$singularName}->{$setterMethod}(\$this);";
			}
			
			$docComment = $this->buildDocComment("Adds an entity to the {$collectionName} collection", [
				"@param {$targetEntity} \${$singularName} Entity to add",
				"@return \$this",
			]);
			
			$code = "\n{$docComment}\n";
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
			
			$docComment = $this->buildDocComment("Removes an entity from the {$collectionName} collection", [
				"@param {$targetEntity} \${$singularName} Entity to remove",
				"@return \$this",
			]);
			
			$code = "\n{$docComment}\n";
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
		 * Builds the ordered list of key=value attribute strings for an @Orm\Column annotation.
		 * @param array $property
		 * @phpstan-param PropertyDefinition $property
		 * @return string[]
		 */
		private function buildColumnAnnotationAttributes(array $property): array {
			$nullable = $property['nullable'] ?? false;
			
			// Column name follows snake_case convention regardless of the PHP property name
			$attributes = [
				"name=\"" . StringInflector::snakeCase($property['name']) . "\"",
				"type=\"{$property['type']}\"",
			];
			
			// Enum columns reference the backing PHP enum class
			if (!empty($property['enumType'])) {
				$attributes[] = "enumType=" . ltrim($property['enumType'], "\\") . "::class";
			}
			
			// Optional column attributes — only emit attributes that were explicitly provided
			if (isset($property['limit']) && is_numeric($property['limit'])) {
				$attributes[] = "limit={$property['limit']}";
			}
			
			if (isset($property['unsigned'])) {
				$attributes[] = "unsigned=" . ($property['unsigned'] ? "true" : "false");
			}
			
			if (isset($property['precision'])) {
				$attributes[] = "precision={$property['precision']}";
			}
			
			if (isset($property['scale'])) {
				$attributes[] = "scale={$property['scale']}";
			}
			
			if ($nullable) {
				$attributes[] = "nullable=true";
			}
			
			return $attributes;
		}
		
		/**
		 * Builds the options string (', key="value", …') for an @Orm\Relationship annotation.
		 * Returns an empty string when there are no options.
		 * @param array $property
		 * @phpstan-param RelationProperty $property
		 * @return string
		 */
		private function buildRelationshipAnnotationOptions(array $property): string {
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
			if ($property['relationshipType'] === 'InverseOf') {
				$options[] = "fetch=\"LAZY\"";
			}
			
			// The owning side is the one without relation — it holds the foreign key column
			if (empty($property['relation']) && !empty($property['localColumn'])) {
				// The column in the current table that stores the foreign key value
				$options[] = "localColumn=\"{$property['localColumn']}\"";
			}
			
			return !empty($options) ? ', ' . implode(', ', $options) : '';
		}
		
		/**
		 * Builds a /** ... *\/ docblock from a description line and an array of @tag lines.
		 * @param string $description First line of the docblock (without @)
		 * @param string[] $tags Lines like "@return string", "@param Foo $bar …"
		 * @return string Complete docblock, no trailing newline
		 */
		private function buildDocComment(string $description, array $tags): string {
			$lines = ["/**", " * {$description}"];
			
			foreach ($tags as $tag) {
				$lines[] = " * {$tag}";
			}
			
			$lines[] = " */";
			
			return implode("\n", $lines);
		}
		
		/**
		 * Wraps a docblock, method signature, and single-expression body into a complete method snippet.
		 * Caller is responsible for a body that already contains the correct indentation.
		 * @param string $docComment Result of buildDocComment()
		 * @param string $signature  "public function foo(Bar $bar): Baz"
		 * @param string $body       Indented statement(s); no surrounding braces
		 * @return string Complete method snippet with leading blank line
		 */
		private function buildSimpleMethod(string $docComment, string $signature, string $body): string {
			return "\n{$docComment}\n{$signature} {\n{$body}\n}\n";
		}
		
		/**
		 * Returns '?' when the property is nullable, '' otherwise.
		 * @param array $property Property metadata
		 * @phpstan-param PropertyDefinition $property
		 * @return string
		 */
		private function buildNullableIndicator(array $property): string {
			return ($property['nullable'] ?? false) ? '?' : '';
		}
		
		/**
		 * Builds the body of a relationship setter (identity guard + optional ManyToOne sync).
		 * Does not include the trailing "return $this;" — the caller appends that.
		 * @param array $property
		 * @phpstan-param RelationProperty $property
		 * @return string Indented body lines, no surrounding braces
		 */
		private function buildRelationshipSetterBody(array $property): string {
			$propertyName = $property['name'];
			
			// Identity check short-circuits the setter when the value hasn't actually changed,
			// which prevents infinite loops in bidirectional sync chains
			$body = "\t// Prevent redundant updates\n";
			$body .= "\tif (\$this->{$propertyName} === \${$propertyName}) {\n";
			$body .= "\t\treturn \$this;\n";
			$body .= "\t}\n";
			
			if ($property['relationshipType'] !== 'ManyToOne' || empty($property['referencedColumn'])) {
				$body .= "\n\t// Set new property\n";
				$body .= "\t\$this->{$propertyName} = \${$propertyName};";
				return $body;
			}
			
			$singularName = StringInflector::singularize($property['referencedColumn']);
			$removerMethod = 'remove' . ucfirst($singularName);
			$adderMethod = 'add' . ucfirst($singularName);
			
			// Before reassigning, remove this entity from the previous parent's collection
			// so the old parent's inverse side stays consistent
			$body .= "\n\t// Remove from previous parent's collection\n";
			$body .= "\t\$this->{$propertyName}?->{$removerMethod}(\$this);\n";
			$body .= "\n\t// Set new property\n";
			$body .= "\t\$this->{$propertyName} = \${$propertyName};\n";
			
			// Add this entity to the new parent's collection to keep the inverse side in sync
			$body .= "\t\${$propertyName}?->{$adderMethod}(\$this);";
			
			return $body;
		}
		
		/**
		 * Resolves the PHP type string for a non-relationship property.
		 * Accepts only BaseProperty|EnumProperty so PHPStan can verify that
		 * enumType is present whenever type === 'enum'.
		 * @param array $property
		 * @phpstan-param BaseProperty|EnumProperty $property
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