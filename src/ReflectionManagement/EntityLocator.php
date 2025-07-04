<?php
	
	namespace Quellabs\ObjectQuel\ReflectionManagement;
	
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Exception\ParserException;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Configuration;
	
	/**
	 * Responsible for locating and loading entity classes
	 */
	class EntityLocator {
		
		/**
		 * @var Configuration
		 */
		private Configuration $configuration;
		
		/**
		 * @var AnnotationReader
		 */
		private AnnotationReader $annotationReader;
		
		/**
		 * @var array Discovered entity classes
		 */
		private array $entityClasses = [];
		
		/**
		 * Initializes the entity locator with the provided configuration
		 * and annotation reader. If no annotation reader is provided,
		 * a new one is created with settings derived from the configuration.
		 * @param Configuration $configuration
		 * @param AnnotationReader|null $annotationReader
		 */
		public function __construct(Configuration $configuration, ?AnnotationReader $annotationReader = null) {
			// Store the configuration for later use when discovering entities
			$this->configuration = $configuration;
			
			// Create a new configuration for the annotation reader
			$annotationReaderConfiguration = new \Quellabs\AnnotationReader\Configuration();
			$annotationReaderConfiguration->setUseAnnotationCache($configuration->useMetadataCache());
			$annotationReaderConfiguration->setAnnotationCachePath($configuration->getMetadataCachePath());
			
			// If no annotation reader was provided, create one with our configuration
			// Otherwise, use the provided reader instance
			$this->annotationReader = $annotationReader ?? new AnnotationReader($annotationReaderConfiguration);
		}
		
		/**
		 * Discover all entity classes in configured paths, including subdirectories
		 * @return array List of discovered entity class names
		 */
		public function discoverEntities(): array {
			// Return cached entities
			if (!empty($this->entityClasses)) {
				return $this->entityClasses;
			}
			
			// Get the service path from configuration
			foreach($this->configuration->getEntityPaths() as $entityPath) {
				// Make absolute
				$entityDirectory = realpath($entityPath);
				
				// Skip directory if it does not exist
				if (!is_dir($entityDirectory) || !is_readable($entityDirectory)) {
					continue;
				}
				
				// Process the root directory and all subdirectories recursively
				$this->processDirectory($entityDirectory, $this->entityClasses);
			}
			
			// Return the list
			return $this->entityClasses;
		}
		
		/**
		 * Recursively process a directory and its subdirectories for entity files
		 * @param string $directory The directory path to process
		 */
		private function processDirectory(string $directory, array &$result): void {
			// Get all PHP files in the current directory
			$entityFiles = glob($directory . DIRECTORY_SEPARATOR . "*.php");
			
			// Process each entity file in the current directory
			foreach ($entityFiles as $filePath) {
				// Get the fully qualified class name from the file
				$entityName = $this->extractEntityNameFromFile($filePath);
				
				// Skip if we couldn't determine the entity name
				if ($entityName === null) {
					continue;
				}
				
				// Check if it's a valid entity class
				if ($this->isEntity($entityName)) {
					$result[] = $entityName;
				}
			}
			
			// Get all subdirectories
			$subdirectories = glob($directory . DIRECTORY_SEPARATOR . "*", GLOB_ONLYDIR);
			
			// Process each subdirectory recursively
			foreach ($subdirectories as $subdirectory) {
				$this->processDirectory($subdirectory, $result);
			}
		}
		
		/**
		 * Extracts the fully qualified class name from a PHP file by reading its content
		 * @param string $filePath The full path to the PHP file
		 * @return string|null The fully qualified class name, or null if not found
		 */
		private function extractEntityNameFromFile(string $filePath): ?string {
			// Read the file contents
			$contents = file_get_contents($filePath);
			
			// If no content found, return null
			if ($contents === false) {
				return null;
			}
			
			// Extract the namespace
			if (preg_match('/namespace\s+([^;]+);/s', $contents, $namespaceMatches)) {
				$namespace = $namespaceMatches[1];
			} else {
				$namespace = '';
			}
			
			// Extract the class name
			if (preg_match('/class\s+(\w+)/s', $contents, $classMatches)) {
				$className = $classMatches[1];
				return $namespace . '\\' . $className;
			}
			
			// If no class found, use filename as class name (without .php extension)
			$fileName = basename($filePath);
			$className = substr($fileName, 0, strpos($fileName, '.php'));
			return $namespace . '\\' . $className;
		}
		
		/**
		 * Checks if the class is an ORM entity
		 * @param string $entityName
		 * @return bool
		 */
		private function isEntity(string $entityName): bool {
			try {
				$annotations = $this->annotationReader->getClassAnnotations($entityName, Table::class);
				return !$annotations->isEmpty();
			} catch (ParserException $e) {
				return false;
			}
		}
	}