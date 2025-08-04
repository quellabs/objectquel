<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	use Quellabs\ObjectQuel\EntityStore;
	
	/**
	 * Generator for WakaPAC component abstractions based on ObjectQuel entities.
	 * This class creates JavaScript abstractions for entities that can be used
	 * with the WakaPAC framework for reactive data binding and component management.
	 */
	class PacComponentGenerator {
		
		/** @var string The fully qualified entity class name */
		private string $entityName;
		
		/** @var Configuration ObjectQuel configuration instance */
		private Configuration $configuration;
		
		/** @var EntityStore|null Cached entity store instance */
		private ?EntityStore $entityStore = null;
		
		/**
		 * Constructor
		 *
		 * @param string $entityName Fully qualified entity class name
		 * @param Configuration $configuration ObjectQuel configuration
		 */
		public function __construct(string $entityName, Configuration $configuration) {
			$this->entityName = $entityName;
			$this->configuration = $configuration;
		}
		
		/**
		 * Creates the JavaScript WakaPAC abstraction code for the entity
		 *
		 * Generates a complete JavaScript object with:
		 * - Properties mapped from entity columns with appropriate defaults
		 * - Utility methods for resetting and change detection
		 * - Factory function for creating instances with custom data
		 *
		 * @return string The generated JavaScript code
		 * @throws \Exception
		 */
		public function create(): string {
			// Get entity metadata from the store
			$entityStore = $this->getEntityStore();
			
			// Throw error if the entity does not exist
			if (!$entityStore->exists($this->entityName)) {
				throw new \Exception("Entity {$this->entityName} does not exist");
			}
			
			// Extract the base class name from the fully qualified name
			if (str_contains($this->entityName, "\\")) {
				$baseName = substr($this->entityName, strrpos($this->entityName, '\\') + 1);
			} else {
				$baseName = $this->entityName;
			}
			
			// Remove "Entity" suffix if present for cleaner naming
			if (str_ends_with($baseName, "Entity")) {
				$baseName = substr($baseName, 0, strlen($baseName) - 6);
			}
			
			// Get entity metadata
			$columns = $entityStore->getColumnMap($this->entityName);
			$identifiers = $entityStore->getIdentifierKeys($this->entityName);
			$columnAnnotations = $entityStore->getAnnotations($this->entityName, Column::class);
			
			// Arrays to build the JavaScript code components
			$properties = [];  // Property declarations with default values
			$reset = [];       // Reset method statements
			$changes = [];     // Change detection statements
			
			// Process each column to generate JavaScript properties
			foreach($columns as $property => $column) {
				$hasDefault = $columnAnnotations[$property]->hasDefault();
				$defaultValue = $columnAnnotations[$property]->getDefault();
				
				// Identifier columns and columns without defaults get null
				if (in_array($property, $identifiers) || !$hasDefault) {
					$properties[] = "{$property}: null";
					$reset[] = "this.{$property} = null;";
				} else {
					// Use the column's default value, properly formatted for JavaScript
					$properties[] = "{$property}: " . TypeMapper::formatValue($defaultValue);
					$reset[] = "this.{$property} = " . TypeMapper::formatValue($defaultValue) . ";";
				}
				
				// Generate change detection logic for this property
				$changes[] = "if (this.{$property} !== this._originalData.{$property}) return true;";
			}
			
			// Add relationship properties (one-to-many relationships as arrays)
			foreach($this->extractManyToOneRelationShips() as $property) {
				$properties[] = "{$property}: []";
				$reset[] = "this.{$property} = [];";
			}
			
			// Make basename lowercase
			$baseNameLower = strtolower($baseName);
			
			// Generate the complete JavaScript abstraction code
			return sprintf(trim("
// Auto-generated WakaPAC abstraction for {$this->entityName}
// Generated on " . date('Y-m-d H:i:s') . "
// Usage: wakaPAC('#my-{$baseNameLower}', {$baseName}Abstraction);

const {$baseName}Abstraction = {
%s,

    computed: {
        // Add computed properties here
    },

    // Utility Methods
    reset() {
        // Reset all properties to their default values
%s
    },
    
    hasChanges() {
        // Check if any properties have changed from original data
        if (!this._originalData) return true;
%s
        return false;
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
				// Format properties with proper indentation
				implode(",\n", array_map(function($e) { return str_repeat(" ", 4) . $e; }, $properties)),
			
				// Format reset statements with proper indentation
				implode("\n", array_map(function($e) { return str_repeat(" ", 8) . $e; }, $reset)),
			
				// Format change detection statements with proper indentation
				implode("\n", array_map(function($e) { return str_repeat(" ", 8) . $e; }, $changes))
			);
		}
		
		/**
		 * Extracts one-to-many relationship properties from the entity
		 * @return array Array of property names that represent one-to-many relationships
		 */
		private function extractManyToOneRelationShips(): array {
			// Get one-to-many dependency metadata from the entity store
			$oneToManyDependencies = $this->getEntityStore()->getOneToManyDependencies($this->entityName);
			
			$result = [];
			foreach ($oneToManyDependencies as $property => $annotation) {
				$result[] = $property;
			}
			
			return $result;
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