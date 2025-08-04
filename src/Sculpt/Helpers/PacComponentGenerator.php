<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\Support\StringInflector;
	
	/**
	 * This class creates JavaScript abstractions for entities that can be used
	 * with the WakaPAC framework for reactive data binding and component management.
	 */
	class PacComponentGenerator {
		
		/** @var string The fully qualified entity class name (e.g., 'App\\Entity\\User') */
		private string $entityName;
		
		/** @var Configuration ObjectQuel configuration instance for database and entity mapping */
		private Configuration $configuration;
		
		/** @var EntityStore|null Cached entity store instance for metadata retrieval */
		private ?EntityStore $entityStore = null;
		
		/**
		 * Constructor
		 * @param string $entityName Fully qualified entity class name (e.g., 'App\\Entity\\User')
		 * @param Configuration $configuration ObjectQuel configuration containing database and mapping settings
		 * @throws \InvalidArgumentException If entityName is empty or configuration is invalid
		 */
		public function __construct(string $entityName, Configuration $configuration) {
			if (empty($entityName)) {
				throw new \InvalidArgumentException('Entity name cannot be empty');
			}
			
			$this->entityName = $entityName;
			$this->configuration = $configuration;
		}
		
		/**
		 * Creates the complete JavaScript WakaPAC abstraction code for the entity
		 * @return string The complete generated JavaScript code as a string
		 * @throws \Exception If the entity does not exist in the entity store
		 * @throws \RuntimeException If entity metadata cannot be retrieved
		 */
		public function create(): string {
			$entityStore = $this->getEntityStore();
			
			if (!$entityStore->exists($this->entityName)) {
				throw new \Exception("Entity {$this->entityName} does not exist");
			}
			
			$baseName = $this->extractBaseName();
			$entityData = $this->prepareEntityData($entityStore);
			$codeComponents = $this->generateCodeComponents($entityData);
			
			return $this->buildJavaScriptCode($baseName, $codeComponents);
		}
		
		/**
		 * Extracts the base class name from the fully qualified entity name
		 * @return string The clean base name suitable for JavaScript class naming
		 */
		private function extractBaseName(): string {
			if (str_contains($this->entityName, "\\")) {
				$baseName = substr($this->entityName, strrpos($this->entityName, '\\') + 1);
			} else {
				$baseName = $this->entityName;
			}
			
			// Remove "Entity" suffix if present for cleaner naming
			if (str_ends_with($baseName, "Entity")) {
				$baseName = substr($baseName, 0, strlen($baseName) - 6);
			}
			
			return $baseName;
		}
		
		/**
		 * Prepares all entity metadata needed for JavaScript code generation
		 * @param EntityStore $entityStore The entity store instance for metadata retrieval
		 * @return array Associative array containing all entity metadata:
		 *               - 'columns': array of property → column mappings
		 *               - 'identifiers': array of primary key column names
		 *               - 'columnAnnotations': array of Column annotation objects
		 *               - 'relationships': array of one-to-many relationship property names
		 */
		private function prepareEntityData(EntityStore $entityStore): array {
			return [
				'columns'           => $entityStore->getColumnMap($this->entityName),
				'identifiers'       => $entityStore->getIdentifierKeys($this->entityName),
				'columnAnnotations' => $entityStore->getAnnotations($this->entityName, Column::class),
				'relationships'     => $this->extractManyToOneRelationShips()
			];
		}
		
		/**
		 * Generates all JavaScript code components from entity metadata
		 * @param array $entityData Entity metadata from prepareEntityData()
		 * @return array Associative array containing code components
		 */
		private function generateCodeComponents(array $entityData): array {
			$properties = [];
			$reset = [];
			$changes = [];
			$assignAfterLoad = [];
			
			$lastKey = array_key_last($entityData['columns']);
			
			// Process columns
			foreach ($entityData['columns'] as $property => $column) {
				$hasDefault = $entityData['columnAnnotations'][$property]->hasDefault();
				$defaultValue = $entityData['columnAnnotations'][$property]->getDefault();
				
				$assignAfterLoad[] = "this.{$property} = data.{$property};";
				
				if (in_array($property, $entityData['identifiers']) || !$hasDefault) {
					$properties[] = "{$property}: null";
					$reset[] = "this.{$property} = null;";
				} else {
					$formattedValue = TypeMapper::formatValue($defaultValue);
					$properties[] = "{$property}: " . $formattedValue;
					$reset[] = "this.{$property} = " . $formattedValue . ";";
				}
				
				if ($lastKey == $property) {
					$changes[] = "return (this.{$property} !== this._originalData.{$property});";
				} else {
					$changes[] = "if (this.{$property} !== this._originalData.{$property}) return true;";
				}
			}
			
			// Add relationship properties
			foreach ($entityData['relationships'] as $property) {
				$properties[] = "{$property}: []";
				$reset[] = "this.{$property} = [];";
			}
			
			return [
				'properties'      => $properties,
				'reset'           => $reset,
				'changes'         => $changes,
				'toFormData'      => $this->generateToFormDataStatements($entityData['columns'], $entityData['columnAnnotations']),
				'assignAfterLoad' => $assignAfterLoad
			];
		}
		
		/**
		 * Builds the complete JavaScript abstraction code from all components
		 * @param string $baseName The clean entity name (e.g., 'User', 'Product')
		 * @param array $components All generated code components from generateCodeComponents()
		 * @return string The complete, formatted JavaScript abstraction code
		 */
		private function buildJavaScriptCode(string $baseName, array $components): string {
			$baseNameLower = strtolower($baseName);
			$baseNamePlural = StringInflector::pluralize($baseNameLower);
			
			return sprintf(trim("
// Auto-generated WakaPAC abstraction for {$this->entityName}
// Generated on " . date('Y-m-d H:i:s') . "
// Usage: wakaPAC('#my-{$baseNameLower}', {$baseName}Abstraction);

const {$baseName}Abstraction = {
%s,

    // Base url for ajax load
    _baseUrl: '/{$baseNamePlural}',

	// Computed variables
    computed: {
        // Add computed properties here
    },

    reset() {
        // Reset all properties to their default values
%s
    },
    
    hasChanges() {
        // Check if any properties have changed from original data
        if (!this._originalData) return true;
%s
    },

    toFormData() {
        const formData = new FormData();
        
%s
        return formData;
    },

    load(id) {
        return this.control(`\${this._baseUrl}/\${id}`, {
            method: 'GET',
            onSuccess: (data) => {
%s

                // Store original data for change tracking
                this._originalData = { ...data };
            },
            onError: (error) => {
                console.error('Failed to load {$baseName}:', error);
            }
        });
    },
    
    save() {
        const url = this.id ? `\${this._baseUrl}/\${this.id}` : this._baseUrl;
        const method = this.id ? 'PUT' : 'POST';
        
        return this.control(url, {
            method,
            data: this.toFormData(),
            onSuccess: (data) => {
%s

                // Update original data for change tracking
                this._originalData = { ...data };
            },
            onError: (error) => {
                console.error('Failed to save {$baseName}:', error);
            }
        });
    },
    
	delete() {
	    if (!this.id) {
	        throw new Error('Cannot delete entity without ID');
        }
	       
	    return this.control(`\${this._baseUrl}/\${this.id}`, {
	        method: 'DELETE',
	        onSuccess: (data) => {
                // Optionally reset the object after successful deletion
                this.reset();
                this._originalData = null;
            },
            onError: (error) => {
                console.error('Failed to delete {$baseName}:', error);
            }
	    });
	},
}

// Helper to merge with custom data
const create{$baseName}Abstraction = (customData = {}) => {
    const abstraction = {
        ...{$baseName}Abstraction,
        ...customData,
        
        // Merge computed properties
        computed: {
            ...{$baseName}Abstraction.computed,
            ...(customData.computed || {})
        }
    };
    
    // Store original data for change tracking
    if (customData && Object.keys(customData).length > 0) {
        abstraction._originalData = { ...customData };
    }
    
    return abstraction;
};

// Export for module systems (CommonJS)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { {$baseName}Abstraction, create{$baseName}Abstraction };
}
		"),
				implode(",\n", array_map(fn($e) => str_repeat(" ", 4) . $e, $components['properties'])),
				implode("\n", array_map(fn($e) => str_repeat(" ", 8) . $e, $components['reset'])),
				implode("\n", array_map(fn($e) => str_repeat(" ", 8) . $e, $components['changes'])),
				implode("\n", array_map(fn($e) => str_repeat(" ", 8) . $e, $components['toFormData'])),
				implode("\n", array_map(fn($e) => str_repeat(" ", 16) . $e, $components['assignAfterLoad'])),
				implode("\n", array_map(fn($e) => str_repeat(" ", 16) . $e, $components['assignAfterLoad'])),
			);
		}
		
		/**
		 * Extracts one-to-many relationship properties from the entity
		 * @return array Array of property names that represent one-to-many relationships
		 */
		private function extractManyToOneRelationShips(): array {
			$oneToManyDependencies = $this->getEntityStore()->getOneToManyDependencies($this->entityName);
			
			$result = [];
			foreach ($oneToManyDependencies as $property => $annotation) {
				$result[] = $property;
			}
			
			return $result;
		}
		
		/**
		 * Creates JavaScript code that converts entity properties into FormData
		 * for HTTP POST/PUT requests. Skips the 'id' field and handles different
		 * column types appropriately.
		 * @param array $columns Column mappings from entity metadata
		 * @param array $columnAnnotations Column annotation objects with type information
		 * @return array Array of JavaScript statements for FormData serialization
		 */
		private function generateToFormDataStatements(array $columns, array $columnAnnotations): array {
			$formDataLines = [];
			
			foreach ($columns as $property => $column) {
				if ($property === 'id') {
					continue;
				}
				
				$annotation = $columnAnnotations[$property] ?? null;
				$columnType = $annotation ? $annotation->getType() : 'string';
				
				$formDataLines = array_merge($formDataLines, $this->generateFormDataForType($property, $columnType));
			}
			
			return $formDataLines;
		}
		
		/**
		 * Generates FormData statements for a specific property type
		 * @param string $property
		 * @param string $columnType
		 * @return string[]
		 */
		private function generateFormDataForType(string $property, string $columnType): array {
			$baseCondition = "if (this.{$property} !== undefined && this.{$property} !== null) {";
			$closeCondition = "}\n";
			
			switch (strtolower($columnType)) {
				case 'boolean':
				case 'bool':
					return [
						$baseCondition,
						"    formData.append('{$property}', this.{$property} ? '1' : '0');",
						$closeCondition
					];
				
				case 'array':
				case 'json':
					return [
						$baseCondition,
						"    formData.append('{$property}', JSON.stringify(this.{$property}));",
						$closeCondition
					];
				
				case 'file':
				case 'blob':
					return [
						"if (this.{$property} instanceof File || this.{$property} instanceof Blob) {",
						"    formData.append('{$property}', this.{$property});",
						"} else if (this.{$property} !== undefined && this.{$property} !== null) {",
						"    formData.append('{$property}', this.{$property});",
						$closeCondition
					];
				
				case 'datetime':
				case 'date':
					return [
						$baseCondition,
						"    const dateValue = this.{$property} instanceof Date ? this.{$property}.toISOString() : this.{$property};",
						"    formData.append('{$property}', dateValue);",
						$closeCondition
					];
				
				default:
					return [
						$baseCondition,
						"    formData.append('{$property}', String(this.{$property}));",
						$closeCondition
					];
			}
		}
		
		/**
		 * Returns the EntityStore instance, creating it if necessary
		 * @return EntityStore The entity store instance
		 */
		private function getEntityStore(): EntityStore {
			if ($this->entityStore === null) {
				$this->entityStore = new EntityStore($this->configuration);
			}
			
			return $this->entityStore;
		}
	}