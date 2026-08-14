<?php
	
	namespace Quellabs\ObjectQuel\ReflectionManagement;
	
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
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
		 * @var class-string[] Discovered entity classes
		 */
		private array $entityClasses = [];
		
		/**
		 * Initializes the entity locator with the provided configuration
		 * and annotation reader. If no annotation reader is provided,
		 * a new one is created with settings derived from the configuration.
		 * @param Configuration $configuration
		 * @param AnnotationReader $annotationReader
		 */
		public function __construct(Configuration $configuration, AnnotationReader $annotationReader) {
			$this->configuration = $configuration;
			$this->annotationReader = $annotationReader;
		}
		
		/**
		 * Discover all entity classes in configured paths, including subdirectories
		 * @return class-string[] List of discovered entity class names
		 * @throws AnnotationReaderException
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
				
				// Skip when the realpath could not be determined
				if ($entityDirectory === false) {
					continue;
				}
				
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
		 * @param class-string[] $result
		 * @throws AnnotationReaderException
		 */
		private function processDirectory(string $directory, array &$result): void {
			// Get all PHP files in the current directory
			$entityFiles = glob($directory . DIRECTORY_SEPARATOR . "*.php") ?: [];
			
			// Process each entity file in the current directory
			foreach ($entityFiles as $filePath) {
				// Get the fully qualified class name from the file
				$entityName = $this->extractEntityNameFromFile($filePath);
				
				// Skip if we couldn't determine the entity name
				if ($entityName === null) {
					continue;
				}
				
				// Skip if this is not a valid class
				if (!class_exists($entityName)) {
					continue;
				}
				
				// Check if it's a valid entity class
				if ($this->isEntity($entityName)) {
					$result[] = $entityName;
				}
			}
			
			// Get all subdirectories
			$subdirectories = glob($directory . DIRECTORY_SEPARATOR . "*", GLOB_ONLYDIR) ?: [];
			
			// Process each subdirectory recursively
			foreach ($subdirectories as $subdirectory) {
				$this->processDirectory($subdirectory, $result);
			}
		}
		
		/**
		 * Extracts the fully qualified class name from a PHP file by tokenizing its content.
		 * Uses PHP's own tokenizer (rather than a regex over the raw source) so that
		 * the word "class" appearing inside a docblock or comment can never be
		 * mistaken for the actual class declaration.
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

			// Tokenize the source. Comments/docblocks become T_COMMENT/T_DOC_COMMENT
			// tokens, so their text can never be picked up as a namespace or class name.
			$tokens = token_get_all($contents);
			$tokenCount = count($tokens);
			$namespace = '';
			$className = null;

			for ($i = 0; $i < $tokenCount; $i++) {
				$token = $tokens[$i];

				if (!is_array($token)) {
					continue;
				}

				$id = $token[0];

				// Namespace declaration: concatenate every non-whitespace token
				// until the terminating ';' or '{'
				if ($id === T_NAMESPACE) {
					$namespace = '';

					for ($j = $i + 1; $j < $tokenCount; $j++) {
						$next = $tokens[$j];

						if ($next === ';' || $next === '{') {
							break;
						}

						if (is_array($next) && $next[0] !== T_WHITESPACE) {
							$namespace .= $next[1];
						}
					}

					continue;
				}

				// Class declaration: take the identifier that immediately follows
				// the "class" keyword, but skip `Foo::class` and `new class { ... }`
				if ($id === T_CLASS && $className === null) {
					$previous = $this->previousNonWhitespaceToken($tokens, $i);

					if (
						is_array($previous) &&
						in_array($previous[0], [T_DOUBLE_COLON, T_NEW], true)
					) {
						continue;
					}

					for ($j = $i + 1; $j < $tokenCount; $j++) {
						$next = $tokens[$j];

						if (is_array($next) && $next[0] === T_WHITESPACE) {
							continue;
						}

						if (is_array($next) && $next[0] === T_STRING) {
							$className = $next[1];
						}

						break;
					}
				}
			}

			if ($className !== null) {
				return $namespace . '\\' . $className;
			}

			// If no class found, use filename as class name (without .php extension)
			$fileName = basename($filePath);
			$pos = strpos($fileName, '.php');

			if ($pos === false) {
				return $namespace . '\\' . $fileName;
			}

			$className = substr($fileName, 0, $pos);
			return $namespace . '\\' . $className;
		}

		/**
		 * Returns the nearest preceding token that isn't whitespace
		 * @param list<array{0: int, 1: string, 2: int}|string> $tokens Full token list from token_get_all()
		 * @param int $index Index to search backwards from (exclusive)
		 * @return array{0: int, 1: string, 2: int}|string|null
		 */
		private function previousNonWhitespaceToken(array $tokens, int $index): array|string|null {
			for ($i = $index - 1; $i >= 0; $i--) {
				if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
					continue;
				}

				return $tokens[$i];
			}

			return null;
		}
		
		/**
		 * Checks if the class is an ORM entity
		 * @param class-string $entityName
		 * @return bool
		 * @throws AnnotationReaderException
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