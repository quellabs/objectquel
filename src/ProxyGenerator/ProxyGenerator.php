<?php
	
	namespace Quellabs\ObjectQuel\ProxyGenerator;
	
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Exception\ParserException;
	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ReflectionManagement\ReflectionHandler;
	
	class ProxyGenerator {
		
		protected EntityStore $entityStore;
		protected ReflectionHandler $reflectionHandler;
		protected AnnotationReader $annotationReader;
		protected array $servicesPaths;
		protected string|false $proxyPath;
		protected string|false $proxyNamespace;
		protected array $types;
		protected array $runtimeProxies = [];
		
		/**
		 * ProxyGenerator constructor
		 * @param EntityStore $entityStore
		 * @param Configuration $configuration
		 */
		public function __construct(EntityStore $entityStore, Configuration $configuration) {
			$this->entityStore = $entityStore;
			$this->reflectionHandler = $entityStore->getReflectionHandler();
			$this->annotationReader = $entityStore->getAnnotationReader();
			$this->servicesPaths = $configuration->getEntityPaths();
			$this->proxyPath = $configuration->getProxyDir() ?? "";
			$this->proxyNamespace = $entityStore->getProxyNamespace();
			
			$this->types = [
				"int", "float", "bool", "string", "array", "object", "resource", "null",
				"callable", "iterable", "mixed", "false", "void", "static"
			];
			
			// Only initialize proxies if servicesPaths and proxyPath are set
			if (!empty($this->servicesPaths) && !empty($this->proxyPath)) {
				$this->createProxyPathIfNotPresent();
				$this->initializeProxies();
			}
		}
		
		/**
		 * Create the proxy dir if it's missing
		 * @return void
		 */
		private function createProxyPathIfNotPresent(): void {
			if (!is_dir($this->proxyPath)) {
				mkdir($this->proxyPath, 0777, true);
			}
		}
		
		/**
		 * This function initializes all entities in the "Entity" directory by scanning for entity files
		 * and generating/updating their corresponding proxy files when necessary.
		 * Proxies are used for lazy loading and performance optimization of entity objects.
		 * @return void
		 */
		private function initializeProxies(): void {
			foreach ($this->servicesPaths as $servicesPath) {
				// Ensure the service path exists
				if (!is_dir($servicesPath)) {
					continue;
				}
				
				// Scan the services directory to get all files that might contain entities
				$entityFiles = scandir($servicesPath);
				
				// Iterate through each file in the directory to process potential entity files
				foreach ($entityFiles as $fileName) {
					// Filter out non-PHP files (like directories, text files, etc.)
					// Only process .php files as they are the only ones that can contain PHP entities
					if (!$this->isPHPFile($fileName)) {
						continue;
					}
					
					// Get the full path to the entity file
					$entityFilePath = $servicesPath . DIRECTORY_SEPARATOR . $fileName;
					
					// Extract the entity name from the file and check if it's a valid entity class
					$entityName = $this->constructEntityName($entityFilePath);
					
					// Skip if the entity does not exist
					if (!$this->entityStore->exists($entityName)) {
						continue;
					}
					
					// Check if the proxy file is outdated compared to the source entity file
					// Proxies need to be regenerated when the original entity has been modified
					if (!$this->isOutdated($entityFilePath)) {
						continue;
					}
					
					// Create a lock file to prevent race conditions in multi-threaded/multi-process environments
					// This ensures that only one process generates the proxy at a time
					$lockFile = $this->proxyPath . DIRECTORY_SEPARATOR . $fileName . '.lock';
					$lockHandle = fopen($lockFile, 'c+');
					
					// If we can't create the lock file, log the error and skip this entity
					// This prevents the process from hanging or corrupting proxy files
					if ($lockHandle === false) {
						error_log("Could not create lock file for entity: {$fileName}");
						continue;
					}
					
					try {
						// Acquire an exclusive lock to ensure only this process modifies the proxy
						if (flock($lockHandle, LOCK_EX)) {
							// Double-check if the file is still outdated after acquiring the lock
							// Another process might have already updated it while we were waiting
							if ($this->isOutdated($entityFilePath)) {
								// Generate the full path for the proxy file
								$proxyFilePath = $this->proxyPath . DIRECTORY_SEPARATOR . $fileName;
								
								// Generate the proxy code content for this specific entity
								$proxyContents = $this->makeProxy($entityName);
								
								// Write the generated proxy content to the file system
								file_put_contents($proxyFilePath, $proxyContents);
							}
							
							// Release the exclusive lock so other processes can proceed
							flock($lockHandle, LOCK_UN);
						}
					} finally {
						// Always clean up resources, even if an exception occurs
						// Close the file handle to free system resources
						fclose($lockHandle);
						
						// Remove the lock file (@ suppresses warnings if file doesn't exist)
						// This cleanup ensures no stale lock files remain in the system
						@unlink($lockFile);
					}
				}
			}
		}
		
		/**
		 * Checks if the specified file is a PHP file.
		 * @param string $fileName Name of the file.
		 * @return bool True if it's a PHP file, otherwise false.
		 */
		private function isPHPFile(string $fileName): bool {
			$fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
			return ($fileExtension === 'php');
		}
		
		/**
		 * Returns true if the file is outdated, false if not
		 * @param string $entityFilePath Full path to the entity file
		 * @return bool
		 */
		private function isOutdated(string $entityFilePath): bool {
			$fileName = basename($entityFilePath);
			$proxyFilePath = $this->proxyPath . DIRECTORY_SEPARATOR . $fileName;
			
			return !file_exists($proxyFilePath) || filemtime($entityFilePath) > filemtime($proxyFilePath);
		}
		
		/**
		 * Constructs the full entity name from the file path
		 * @param string $entityFilePath Full path to the entity file
		 * @return string The fully qualified class name
		 */
		private function constructEntityName(string $entityFilePath): string {
			// Try to determine the actual namespace and class name from the file
			$fileContents = file_get_contents($entityFilePath);
			$namespace = $this->extractNamespaceFromFile($fileContents);
			$className = $this->extractClassNameFromFile($fileContents);
			
			if ($namespace && $className) {
				return $namespace . '\\' . $className;
			}
			
			// Fallback: use filename without extension
			return basename($entityFilePath, '.php');
		}
		
		/**
		 * Extract namespace from PHP file content
		 * @param string $fileContent
		 * @return string|null
		 */
		private function extractNamespaceFromFile(string $fileContent): ?string {
			if (preg_match('/namespace\s+([^;]+);/', $fileContent, $matches)) {
				return trim($matches[1]);
			}
			
			return null;
		}
		
		/**
		 * Extract class name from PHP file content
		 * @param string $fileContent
		 * @return string|null
		 */
		private function extractClassNameFromFile(string $fileContent): ?string {
			if (preg_match('/class\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*(?:extends|implements|{)/', $fileContent, $matches)) {
				return trim($matches[1]);
			}
			
			return null;
		}
		
		/**
		 * Returns the proxy template
		 * @return string
		 */
		protected function getTemplate(): string {
			return "
<?php
	namespace {$this->proxyNamespace};
	
	include_once('%s');
	
	%s
	class %s extends \%s implements \Quellabs\ObjectQuel\ProxyGenerator\ProxyInterface {
		%s
	}
";
		}
		
		/**
		 * Retrieves the class name of a given entity, without the namespace.
		 * @param string $classNameWithNamespace The entity from which we want to retrieve the class name.
		 * @return string The class name without the namespace.
		 */
		protected function getClassNameWithoutNamespace(string $classNameWithNamespace): string {
			return ltrim(strrchr($classNameWithNamespace, '\\'), '\\');
		}
		
		/**
		 * Convert a type to its string representation.
		 * @param string $type The type to convert.
		 * @param bool $nullable Indicates if the type can be null.
		 * @return string The string representation of the type.
		 */
		protected function typeToString(string $type, bool $nullable): string {
			// Return empty string for empty type
			if ($type === '') {
				return '';
			}
			
			// Special case for 'mixed' type - cannot be nullable
			if ($type === 'mixed') {
				return 'mixed';
			}
			
			// Special case for 'self' type
			if ($type === 'self') {
				return 'self';
			}
			
			// Determine if type needs namespace prefix
			$result = in_array($type, $this->types) ? $type : "\\{$type}";
			
			// Add nullable prefix if needed
			return $nullable ? "?{$result}" : $result;
		}
		
		/**
		 * Creates a string representation of the methods of a given entity,
		 * including their types, visibility and documentation comments.
		 * @param mixed $entity The entity whose properties are retrieved.
		 * @return string A concatenated string that describes the properties of the entity.
		 */
		protected function makeProxyMethods(mixed $entity): string {
			$result = [];
			
			// Get identifier keys
			$identifierKeys = $this->entityStore->getIdentifierKeys($entity);
			$identifierKeysGetterMethod = 'get' . ucfirst($identifierKeys[0]);
			$hasConstructor = $this->reflectionHandler->hasConstructor($entity);
			$constructorParentCode = $hasConstructor ? "parent::__construct();" : "";
			
			// Add the constructor and the lazy load function
			$result[] = "
			    /**
			     * The EntityManager instance used to lazy-load entity data from the database
			     * @var \\Quellabs\\ObjectQuel\\EntityManager
			     */
			    private \\Quellabs\\ObjectQuel\\EntityManager \$entityManager;
			    
			    /**
			     * Flag indicating whether the proxy has been initialized with actual entity data
			     * @var bool
			     */
			    private bool \$initialized;
			    
			    /**
			     * The proxy is created in an uninitialized state and will only load the actual
			     * entity data from the database when first accessed via doInitialize().
			     * @param \\Quellabs\\ObjectQuel\\EntityManager \$entityManager The EntityManager instance for database operations
			     */
			    public function __construct(\\Quellabs\\ObjectQuel\\EntityManager \$entityManager) {
			       \$this->entityManager = \$entityManager;
			       \$this->initialized = false;
			       {$constructorParentCode}
			    }
			    
			    /**
			     * This method is called internally when the proxy needs to access entity properties
			     * or methods for the first time. It uses the EntityManager to fetch the complete
			     * entity data based on the entity's identifier and marks the proxy as initialized.
			     * @return void
			     */
			    protected function doInitialize(): void {
			       \$this->entityManager->find(\\{$entity}::class, \$this->{$identifierKeysGetterMethod}());
			       \$this->setInitialized();
			    }
			
			    /**
			     * Checks if the proxy has been initialized with actual entity data
			     * @return bool True if the entity data has been loaded from the database, false otherwise
			     */
			    public function isInitialized(): bool {
			       return \$this->initialized;
			    }
			
			    /**
			     * Marks the proxy as initialized
			     * @return void
			     */
			    public function setInitialized(): void {
			       \$this->initialized = true;
			    }
			";
			
			// Loop through all methods of the given object to generate proxy methods.
			foreach ($this->reflectionHandler->getMethods($entity) as $method) {
				// Skip the constructor and primary key getter
				if (in_array($method, ["__construct", $identifierKeysGetterMethod])) {
					continue;
				}
				
				// Skip private functions
				$visibility = $this->reflectionHandler->getMethodVisibility($entity, $method);
				
				if ($visibility === "private") {
					continue;
				}
				
				// Obtain important information about the method via reflection.
				$returnType = $this->reflectionHandler->getMethodReturnType($entity, $method);
				$returnTypeNullable = $this->reflectionHandler->methodReturnTypeIsNullable($entity, $method);
				$docComment = $this->reflectionHandler->getMethodDocComment($entity, $method);
				
				// Initialize an array to build the parameter list.
				$parameterList = [];
				$parameters = $this->reflectionHandler->getMethodParameters($entity, $method);
				
				// Loop through the parameters and build the list.
				foreach ($parameters as $parameter) {
					$parameterType = $this->typeToString($parameter["type"], $parameter["nullable"]);
					
					if (!$parameter["has_default"]) {
						$parameterList[] = "{$parameterType} \${$parameter["name"]}";
					} elseif ($parameter["default"] === null) {
						$parameterList[] = "{$parameterType} \${$parameter["name"]}=NULL";
					} elseif ($parameterType == "string") {
						$parameterList[] = "{$parameterType} \${$parameter["name"]}='{$parameter["default"]}'";
					} else {
						$parameterList[] = "{$parameterType} \${$parameter["name"]}={$parameter["default"]}";
					}
				}
				
				// Create the final parameter list and parameter name list.
				$parameterString = implode(",", $parameterList);
				$parameterNamesString = implode(",", array_map(function ($e) { return "\${$e}"; }, array_column($parameters, "name")));
				$returnTypeString = $this->typeToString($returnType, $returnTypeNullable);
				$returnTypeString = !empty($returnTypeString) ? ": {$returnTypeString}" : "";
				
				// Functions that return void don't have a return statement. Otherwise everything crashes.
				if (str_contains($returnTypeString, "void")) {
					$returnStatement = "";
				} else {
					$returnStatement = "return ";
				}
				
				// Add the proxy method to the results list.
				$result[] = "
					{$docComment}
					{$visibility} function {$method}({$parameterString}){$returnTypeString} {
						\$this->doInitialize();
						{$returnStatement}parent::{$method}({$parameterNamesString});
					}
		        ";
			}
			
			// Combine all generated proxy methods into one string and return it.
			return implode("\n", $result);
		}
		
		/**
		 * Create the contents of the proxy file for the given entity
		 * @param $entity
		 * @return string
		 */
		private function makeProxy($entity): string {
			$class = is_object($entity) ? get_class($entity) : $entity;
			
			return trim(sprintf(
				$this->getTemplate(),
				$this->reflectionHandler->getFilename($class),
				$this->reflectionHandler->getDocComment($class),
				$this->getClassNameWithoutNamespace($class),
				$class,
				$this->makeProxyMethods($class),
			));
		}
		
		/**
		 * Generate or retrieve a proxy class for the given entity
		 * @param string $entityClass The fully qualified class name of the entity
		 * @return string The fully qualified class name of the proxy
		 */
		public function getProxyClass(string $entityClass): string {
			// If a proxy path is set, return the path-based proxy class name
			if ($this->proxyPath !== false) {
				$className = $this->getClassNameWithoutNamespace($entityClass);
				return $this->proxyNamespace . '\\' . $className;
			}
			
			// If we've already generated this proxy at runtime, return its class name
			if (isset($this->runtimeProxies[$entityClass])) {
				return $this->runtimeProxies[$entityClass];
			}
			
			// Generate proxy class at runtime
			return $this->generateRuntimeProxy($entityClass);
		}
		
		/**
		 * Generates a runtime proxy class for the given entity and returns its class name
		 * @param string $entityClass
		 * @return string The fully qualified class name of the generated proxy
		 */
		protected function generateRuntimeProxy(string $entityClass): string {
			// Generate a unique class name for the runtime proxy
			$className = $this->getClassNameWithoutNamespace($entityClass);
			$uniqueId = uniqid();
			$proxyClassName = $this->proxyNamespace . '\\' . $className . '_' . $uniqueId;
			
			// Generate the proxy class code
			$proxyContents = $this->makeProxy($entityClass);
			
			// Modify the namespace in the proxy content to match the runtime namespace
			$proxyContents = preg_replace(
				'/namespace\s+([^;]+);/',
				'namespace ' . $this->proxyNamespace . ';',
				$proxyContents
			);
			
			// Modify the class name to include the unique identifier
			$proxyContents = preg_replace(
				'/class\s+' . $className . '\s+extends/',
				'class ' . $className . '_' . $uniqueId . ' extends',
				$proxyContents
			);
			
			// Use eval to define the proxy class at runtime
			eval('?>' . $proxyContents);
			
			// Store the generated proxy class name
			$this->runtimeProxies[$entityClass] = $proxyClassName;
			
			return $proxyClassName;
		}
	}