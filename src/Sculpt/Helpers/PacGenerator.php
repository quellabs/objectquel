<?php
	
	namespace Quellabs\ObjectQuel\Sculpt\Helpers;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\EntityResolutionException;
	use Quellabs\ObjectQuel\Metadata\EntityMetadataRecord;
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
		
		/** @var EntityMetadataRecord Metadata for entity */
		private EntityMetadataRecord $metadata;
		
		/**
		 * PacGenerator Constructor
		 * @param string $entityName Fully qualified entity class name (e.g., 'App\\Entity\\User')
		 * @param EntityStore $entityStore
		 * @throws EntityResolutionException
		 */
		public function __construct(string $entityName, EntityStore $entityStore) {
			$this->entityName = $entityName;
			$this->entityStore = $entityStore;
			$this->metadata = $entityStore->getMetadata($this->entityName);
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
		 * @return array{
		 *     columns: array<string, string>,
		 *     identifiers: array<int, string>,
		 *     columnAnnotations: array<string, array<int, Column>>,
		 *     relationships: array<int, string>
		 * }
		 */
		protected function prepareEntityData(): array {
			return [
				'columns'           => $this->metadata->columnMap,
				'identifiers'       => $this->metadata->identifierKeys,
				'columnAnnotations' => $this->metadata->getAnnotationsOfType(Column::class),
				'relationships'     => array_keys($this->metadata->getOneToManyDependencies())
			];
		}
	}