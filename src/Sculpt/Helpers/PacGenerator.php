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
	class PacGenerator {
		
		/** @var string The fully qualified entity class name (e.g., 'App\\Entity\\User') */
		protected string $entityName;
		
		/** @var EntityStore Entity store instance for metadata retrieval */
		protected EntityStore $entityStore;
		
		/**
		 * Constructor
		 * @param string $entityName Fully qualified entity class name (e.g., 'App\\Entity\\User')
		 * @param EntityStore $entityStore
		 */
		public function __construct(string $entityName, EntityStore $entityStore) {
			$this->entityName = $entityName;
			$this->entityStore = $entityStore;
		}
		
		/**
		 * Extracts the base class name from the fully qualified entity name
		 * @return string The clean base name suitable for JavaScript class naming
		 */
		protected function extractBaseName(): string {
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
		protected function prepareEntityData(EntityStore $entityStore): array {
			return [
				'columns'           => $entityStore->getColumnMap($this->entityName),
				'identifiers'       => $entityStore->getIdentifierKeys($this->entityName),
				'columnAnnotations' => $entityStore->getAnnotations($this->entityName, Column::class),
				'relationships'     => $this->extractManyToOneRelationShips()
			];
		}

		/**
		 * Extracts one-to-many relationship properties from the entity
		 * @return array Array of property names that represent one-to-many relationships
		 */
		protected function extractManyToOneRelationShips(): array {
			$oneToManyDependencies = $this->entityStore->getOneToManyDependencies($this->entityName);
			
			$result = [];
			foreach ($oneToManyDependencies as $property => $annotation) {
				$result[] = $property;
			}
			
			return $result;
		}
	}