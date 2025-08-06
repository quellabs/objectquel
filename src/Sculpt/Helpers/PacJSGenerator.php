<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	use Quellabs\Support\StringInflector;
	
	/**
	 * This class creates JavaScript abstractions for entities that can be used
	 * with the WakaPAC framework for reactive data binding and component management.
	 * Modified to work with JSON:API standard.
	 */
	class PacJSGenerator extends PacGenerator {
		
		/**
		 * Creates the complete JavaScript WakaPAC abstraction code for the entity
		 * @return string The complete generated JavaScript code as a string
		 * @throws \Exception If the entity does not exist in the entity store
		 */
		public function create(): string {
			$baseName = $this->extractBaseName();
			$entityData = $this->prepareEntityData($this->entityStore);
			$codeComponents = $this->generateCodeComponents($entityData);
			
			return $this->buildJavaScriptCode($baseName, $codeComponents);
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
			$assignAfterSave = [];
			$lastKey = array_key_last($entityData['columns']);
			
			// Process columns
			foreach ($entityData['columns'] as $property => $column) {
				$hasDefault = $entityData['columnAnnotations'][$property]->hasDefault();
				$defaultValue = $entityData['columnAnnotations'][$property]->getDefault();
				
				// Handle ID separately since it's at data.id level in JSON:API
				if ($property === 'id') {
					$assignAfterLoad[] = "this.{$property} = data.data.id;";
					$assignAfterSave[] = "if (typeof data.data.id !== 'undefined') { this.{$property} = data.data.id; }";
				} else {
					$assignAfterLoad[] = "this.{$property} = data.data.attributes.{$property} ?? null;";
					$assignAfterSave[] = "if (typeof data.data.attributes.{$property} !== 'undefined') { this.{$property} = data.data.attributes.{$property}; }";
				}
				
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
				'toJsonApiData'   => $this->generateToJsonApiStatements($entityData['columns'], $entityData['columnAnnotations']),
				'assignAfterLoad' => $assignAfterLoad,
				'assignAfterSave' => $assignAfterSave,
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
// Auto-generated WakaPAC abstraction for {$this->entityName} (JSON:API)
// Generated on " . date('Y-m-d H:i:s') . "
// Usage: wakaPAC('#my-{$baseNameLower}', {$baseName}Abstraction);

const {$baseName}Abstraction = {
%s,

    /**
     * Base url for ajax load
     */
    _baseUrl: '/{$baseNamePlural}',

	/**
	 * Resource type for JSON:API
	 */
    _resourceType: '{$baseName}',

	/**
	 * Add computed properties here
	 */
    computed: {
    },

	/**
	 * Reset all properties to their default values
	 */
    reset() {
%s
    },
    
    /**
     * Check if any properties have changed from original data
     */
    hasChanges() {
        if (!this._originalData) return true;
%s
    },

	/**
	 * Converts the model instance to JSON API format
	 * @returns {Object} JSON API compliant data object with type, attributes, and optional id
	 */
	toJsonApiData() {
	    const jsonApiData = {
	        data: {
	            type: this._resourceType,
	            attributes: {}
	        }
	    };
	    
	    // Add ID if it exists (for updates)
	    if (this.id) {
	        jsonApiData.data.id = String(this.id);
	    }
	    
	%s
	    
	    return jsonApiData;
	},

	/**
	 * Load the entity from the server
	 */
    load(id) {
        return this.control(`\${this._baseUrl}/\${id}`, {
            method: 'GET',
            headers: {
                'Accept': 'application/vnd.api+json',
                'Content-Type': 'application/vnd.api+json',
                'X-Requested-With': 'WakaPAC',
                'X-PAC-Version': '1.0'
            },
            onSuccess: (data) => {
                // Validate JSON:API response structure
                if (!data.data || !data.data.type || !data.data.id) {
                    console.error('Invalid JSON:API response structure');
                    return;
                }
                
                if (data.data.type !== this._resourceType) {
                    console.error(`Resource type mismatch: expected '\${this._resourceType}', got '\${data.data.type}'`);
                    return;
                }

%s

                // Store original data for change tracking
                this._originalData = this._extractAttributesForTracking(data);
            },
            onError: (error) => {
                this._handleJsonApiError(error, 'Failed to load {$baseName}');
            }
        });
    },
    
    /**
     * Stores the entity data on the server
     */
    save() {
        const url = this.id ? `\${this._baseUrl}/\${this.id}` : this._baseUrl;
        const method = this.id ? 'PUT' : 'POST';
        const jsonApiData = this.toJsonApiData();
        
        return this.control(url, {
            method,
            headers: {
                'Accept': 'application/vnd.api+json',
                'Content-Type': 'application/vnd.api+json',
                'X-Requested-With': 'WakaPAC',
                'X-PAC-Version': '1.0'
            },
            data: JSON.stringify(jsonApiData),
            onSuccess: (data) => {
                // Validate JSON:API response structure
                if (!data.data || !data.data.type || !data.data.id) {
                    console.error('Invalid JSON:API response structure');
                    return;
                }

%s

                // Update original data for change tracking
                this._originalData = this._extractAttributesForTracking(data);
            },
            onError: (error) => {
                this._handleJsonApiError(error, 'Failed to save {$baseName}');
            }
        });
    },
    
    /**
     * Deletes the entity from the server
     */
	delete() {
	    if (!this.id) {
	        throw new Error('Cannot delete entity without ID');
        }
	       
	    return this.control(`\${this._baseUrl}/\${this.id}`, {
	        method: 'DELETE',
	        headers: {
                'Accept': 'application/vnd.api+json',
                'X-Requested-With': 'WakaPAC',
                'X-PAC-Version': '1.0'
            },
	        onSuccess: (data) => {
                // JSON:API DELETE should return 204 No Content (empty response)
                this.reset();
                this._originalData = null;
            },
            onError: (error) => {
                this._handleJsonApiError(error, 'Failed to delete {$baseName}');
            }
	    });
	},
	
	/**
	 * Helper method to extract attributes for change tracking
	 */
	_extractAttributesForTracking(jsonApiResponse) {
	    const tracking = {};
	    if (jsonApiResponse.data && jsonApiResponse.data.attributes) {
	        Object.assign(tracking, jsonApiResponse.data.attributes);
	    }
	    if (jsonApiResponse.data && jsonApiResponse.data.id) {
	        tracking.id = jsonApiResponse.data.id;
	    }
	    return tracking;
	},
	
	/**
	 * Helper method to handle JSON:API errors
	 */
	_handleJsonApiError(error, defaultMessage) {
	    if (error.errors && Array.isArray(error.errors)) {
	        error.errors.forEach(err => {
	            console.error(`{$baseName} Error [\${err.status || 'Unknown'}]: \${err.title || defaultMessage}`, err.detail || '');
	        });
	    } else {
	        console.error(defaultMessage + ':', error);
	    }
	}
}

/**
 * Helper to merge with custom data
 */
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

/**
 * Export for module systems (CommonJS)
 */
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { {$baseName}Abstraction, create{$baseName}Abstraction };
}
		"),
				implode(",\n", array_map(fn($e) => str_repeat(" ", 4) . $e, $components['properties'])),
				implode("\n", array_map(fn($e) => str_repeat(" ", 8) . $e, $components['reset'])),
				implode("\n", array_map(fn($e) => str_repeat(" ", 8) . $e, $components['changes'])),
				implode("\n", array_map(fn($e) => str_repeat(" ", 8) . $e, $components['toJsonApiData'])),
				implode("\n", array_map(fn($e) => str_repeat(" ", 16) . $e, $components['assignAfterLoad'])),
				implode("\n", array_map(fn($e) => str_repeat(" ", 16) . $e, $components['assignAfterSave'])),
			);
		}
		
		/**
		 * Creates JavaScript code that converts entity properties into JSON:API format
		 * for HTTP POST/PUT requests. Skips the 'id' field as it's handled separately.
		 * @param array $columns Column mappings from entity metadata
		 * @param array $columnAnnotations Column annotation objects with type information
		 * @return array Array of JavaScript statements for JSON:API serialization
		 */
		private function generateToJsonApiStatements(array $columns, array $columnAnnotations): array {
			$jsonApiLines = [];
			
			foreach ($columns as $property => $column) {
				if ($property === 'id') {
					continue;
				}
				
				$annotation = $columnAnnotations[$property] ?? null;
				$columnType = $annotation ? $annotation->getType() : 'string';
				
				$jsonApiLines = array_merge($jsonApiLines, $this->generateJsonApiForType($property, $columnType));
			}
			
			return $jsonApiLines;
		}
		
		/**
		 * Generates JSON:API attribute statements for a specific property type
		 * @param string $property
		 * @param string $columnType
		 * @return string[]
		 */
		private function generateJsonApiForType(string $property, string $columnType): array {
			$baseCondition = "if (this.{$property} !== undefined && this.{$property} !== null) {";
			$closeCondition = "}";
			
			switch (strtolower($columnType)) {
				case 'boolean':
				case 'bool':
					return [
						$baseCondition,
						"    jsonApiData.data.attributes.{$property} = Boolean(this.{$property});",
						$closeCondition
					];
				
				case 'array':
				case 'json':
					return [
						$baseCondition,
						"    jsonApiData.data.attributes.{$property} = Array.isArray(this.{$property}) ? this.{$property} : JSON.parse(this.{$property});",
						$closeCondition
					];
				
				case 'datetime':
				case 'date':
					return [
						$baseCondition,
						"    jsonApiData.data.attributes.{$property} = this.{$property} instanceof Date ? this.{$property}.toISOString() : this.{$property};",
						$closeCondition
					];
				
				case 'integer':
				case 'int':
					return [
						$baseCondition,
						"    jsonApiData.data.attributes.{$property} = parseInt(this.{$property}, 10);",
						$closeCondition
					];
				
				case 'float':
				case 'decimal':
				case 'double':
					return [
						$baseCondition,
						"    jsonApiData.data.attributes.{$property} = parseFloat(this.{$property});",
						$closeCondition
					];
				
				default:
					return [
						$baseCondition,
						"    jsonApiData.data.attributes.{$property} = String(this.{$property});",
						$closeCondition
					];
			}
		}
	}