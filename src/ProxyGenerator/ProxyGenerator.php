<?php
	
	namespace Quellabs\ObjectQuel\ProxyGenerator;
	
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ReflectionManagement\ReflectionHandler;
	
	class ProxyGenerator {
		
		/** The entity store, used to look up entity metadata such as identifier keys. */
		private EntityStore $entityStore;
		
		/** Reflection handler for inspecting entity methods, parameters, and return types. */
		private ReflectionHandler $reflectionHandler;
		
		/** List of filesystem paths to scan for entity source files. */
		private array $servicesPaths;
		
		/** Absolute path to the directory where proxy files are written, or false if not configured. */
		private string|false $proxyPath;
		
		/** Namespace to use for generated proxy classes, or false if not configured. */
		private string|false $proxyNamespace;
		
		/** Cache of entity class name → proxy class name for proxies generated at runtime via eval(). */
		private array $runtimeProxies = [];
		
		/**
		 * Primitive scalar and pseudo-types that do not require a leading backslash.
		 */
		private const array SCALAR_TYPES = [
			"int", "float", "bool", "string", "array", "object", "resource", "null",
			"callable", "iterable", "mixed", "false", "true", "void", "static", "never",
		];
		
		/**
		 * ProxyGenerator constructor
		 * @param EntityStore $entityStore
		 * @param Configuration $configuration
		 */
		public function __construct(EntityStore $entityStore, Configuration $configuration) {
			$this->entityStore = $entityStore;
			$this->reflectionHandler = $entityStore->getReflectionHandler();
			$this->servicesPaths = $configuration->getEntityPaths();
			$this->proxyPath = $configuration->getProxyDir() ?? false;
			$this->proxyNamespace = $entityStore->getProxyNamespace();
			
			// Only initialize proxies if servicesPaths and proxyPath are set
			if (!empty($this->servicesPaths) && $this->proxyPath !== false && $this->proxyPath !== '') {
				$this->createProxyPathIfNotPresent();
				$this->initializeProxies();
			}
		}
		
		/**
		 * Returns the fully-qualified proxy class name for the given entity.
		 * Uses the file-based proxy when a proxy directory is configured, or
		 * generates an in-memory proxy on-the-fly otherwise.
		 * @param string $entityClass Fully-qualified entity class name
		 * @return string Fully-qualified proxy class name
		 */
		public function getProxyClass(string $entityClass): string {
			if ($this->proxyPath !== false && $this->proxyPath !== '') {
				return $this->proxyNamespace . '\\' . $this->getClassNameWithoutNamespace($entityClass);
			}
			
			if (isset($this->runtimeProxies[$entityClass])) {
				return $this->runtimeProxies[$entityClass];
			}
			
			return $this->generateRuntimeProxy($entityClass);
		}
		
		/**
		 * Returns the filesystem path of the proxy file for the given entity.
		 * Only meaningful when a proxy directory is configured.
		 * @param string $targetEntity Fully-qualified entity class name
		 * @return string Absolute path to the proxy file
		 */
		public function getProxyFilePath(string $targetEntity): string {
			$normalizedEntity = $this->entityStore->normalizeEntityName($targetEntity);
			$shortClassName   = $this->getClassNameWithoutNamespace($normalizedEntity);
			return $this->proxyPath . DIRECTORY_SEPARATOR . $shortClassName . '.php';
		}
		
		/**
		 * Create the proxy directory if it is missing.
		 * Throws a RuntimeException if the directory cannot be created.
		 * @return void
		 */
		private function createProxyPathIfNotPresent(): void {
			if (!is_dir($this->proxyPath)) {
				if (!mkdir($this->proxyPath, 0755, true) && !is_dir($this->proxyPath)) {
					throw new \RuntimeException("Cannot create proxy directory: {$this->proxyPath}");
				}
			}
		}
		
		/**
		 * Scans all entity paths, generates or refreshes proxy files for any entity
		 * whose proxy is missing or older than the source file.
		 * @return void
		 */
		private function initializeProxies(): void {
			foreach ($this->servicesPaths as $servicesPath) {
				// Skip paths that don't exist — the configuration may list directories
				// that are valid in production but absent in other environments.
				if (!is_dir($servicesPath)) {
					continue;
				}
				
				$entityFiles = scandir($servicesPath);
				
				foreach ($entityFiles as $fileName) {
					// Ignore directories, dot-files, and anything that isn't a PHP source file.
					if (!$this->isPHPFile($fileName)) {
						continue;
					}
					
					// Derive the FQCN from the file's namespace and class declarations.
					$entityFilePath = $servicesPath . DIRECTORY_SEPARATOR . $fileName;
					$entityName = $this->constructEntityName($entityFilePath);
					
					// Only generate proxies for classes the entity store actually knows about.
					// Plain PHP files in the entity directory that aren't mapped entities are skipped.
					if (!$this->entityStore->exists($entityName)) {
						continue;
					}
					
					// Skip files whose proxy is already up to date, avoiding unnecessary I/O.
					if (!$this->isOutdated($entityFilePath)) {
						continue;
					}
					
					// Use a per-entity lock file to serialise proxy generation across
					// concurrent processes (e.g. multiple PHP-FPM workers on first boot).
					$lockFile = $this->proxyPath . DIRECTORY_SEPARATOR . $fileName . '.lock';
					$lockHandle = fopen($lockFile, 'c+');
					
					if ($lockHandle === false) {
						error_log("ProxyGenerator: Could not create lock file for entity: {$fileName}");
						continue;
					}
					
					try {
						if (flock($lockHandle, LOCK_EX)) {
							// Double-check after acquiring the lock — another process may have
							// already regenerated the proxy while we were waiting.
							// @phpstan-ignore if.alwaysTrue
							if ($this->isOutdated($entityFilePath)) {
								$proxyFilePath = $this->proxyPath . DIRECTORY_SEPARATOR . $fileName;
								$proxyContents = $this->makeProxy($entityName);
								
								if (file_put_contents($proxyFilePath, $proxyContents) === false) {
									error_log("ProxyGenerator: Failed to write proxy file: {$proxyFilePath}");
								}
							}
							
							flock($lockHandle, LOCK_UN);
						} else {
							error_log("ProxyGenerator: Could not acquire exclusive lock for entity: {$fileName}");
						}
					} finally {
						// Always release the file handle and remove the lock file,
						// even if an exception is thrown during proxy generation.
						fclose($lockHandle);
						@unlink($lockFile);
					}
				}
			}
		}
		
		/**
		 * Returns true if $fileName has a .php extension.
		 * @param string $fileName
		 * @return bool
		 */
		private function isPHPFile(string $fileName): bool {
			return pathinfo($fileName, PATHINFO_EXTENSION) === 'php';
		}
		
		/**
		 * Returns true if the proxy file for the given entity source file is missing
		 * or older than the source file.
		 * @param string $entityFilePath Full path to the entity source file
		 * @return bool
		 */
		private function isOutdated(string $entityFilePath): bool {
			$proxyFilePath = $this->proxyPath . DIRECTORY_SEPARATOR . basename($entityFilePath);
			return !file_exists($proxyFilePath) || filemtime($entityFilePath) > filemtime($proxyFilePath);
		}
		
		/**
		 * Derives the fully-qualified class name from a PHP source file by reading its
		 * namespace and class declarations. Falls back to the bare filename on failure.
		 * @param string $entityFilePath Full path to the entity source file
		 * @return string
		 */
		private function constructEntityName(string $entityFilePath): string {
			// Read the source file so we can parse its namespace and class declarations.
			$fileContents = file_get_contents($entityFilePath);
			
			// If the file is unreadable, fall back to the filename as a best-effort class name.
			if ($fileContents === false) {
				return basename($entityFilePath, '.php');
			}
			
			// Both parts are required for a valid FQCN; a class without a namespace
			// declaration is not a valid entity in this codebase.
			$namespace = $this->extractNamespaceFromFile($fileContents);
			$className = $this->extractClassNameFromFile($fileContents);
			
			if ($namespace !== null && $className !== null) {
				return $namespace . '\\' . $className;
			}
			
			// Fallback for files that are missing a namespace or class declaration.
			return basename($entityFilePath, '.php');
		}
		
		/**
		 * Extracts the namespace declaration from PHP file contents.
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
		 * Extracts the first class name declared in the PHP file contents.
		 * @param string $fileContent
		 * @return string|null
		 */
		private function extractClassNameFromFile(string $fileContent): ?string {
			if (preg_match('/class\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*(?:extends|implements|\{)/', $fileContent, $matches)) {
				return trim($matches[1]);
			}
			
			return null;
		}
		
		/**
		 * Strips leading whitespace from every line of a doc comment so it renders
		 * flush with the target indentation in the generated file, regardless of
		 * how it was indented in the original source.
		 * @param string $docComment
		 * @return string
		 */
		private function normalizeDocComment(string $docComment, string $indent = ''): string {
			if ($docComment === '') {
				return '';
			}

			// Strip all leading whitespace from each line individually, then re-apply
			// the target indent uniformly. This handles mixed tabs/spaces in source files.
			$lines = explode("\n", $docComment);

			$normalized = array_map(function (string $line) use ($indent): string {
				$stripped = ltrim($line);
				return $stripped !== '' ? $indent . $stripped : '';
			}, $lines);

			return implode("\n", $normalized);
		}

		/**
		 * Returns the Heredoc template used to render a file-based proxy class.
		 * The autoloader is responsible for loading the parent entity class;
		 * no include_once is emitted.
		 * @param string $namespace   Target proxy namespace
		 * @param string $docComment  Doc-comment from the entity class (may be empty)
		 * @param string $shortName   Class name without namespace
		 * @param string $fqcn        Fully-qualified entity class name
		 * @param string $methods     Generated proxy method body
		 * @return string
		 */
		private function renderTemplate(
			string $namespace,
			string $docComment,
			string $shortName,
			string $fqcn,
			string $methods
		): string {
			// Normalise the class doc comment: strip per-line leading whitespace so it sits
			// flush with the class declaration regardless of the source file's indentation.
			$normalizedDocComment = $this->normalizeDocComment($docComment, '');

			// The backslash before the FQCN must sit outside the interpolation block;
			// otherwise PHP treats \{ as an escape sequence and emits it literally.
			$extends = '\\' . $fqcn;

			return trim(<<<PHP
<?php

namespace {$namespace};

{$normalizedDocComment}
class {$shortName} extends {$extends} implements \Quellabs\ObjectQuel\ProxyGenerator\ProxyInterface {
{$methods}
}
PHP);
		}
		
		/**
		 * Returns the class name without its namespace prefix.
		 * @param string $classNameWithNamespace
		 * @return string
		 */
		private function getClassNameWithoutNamespace(string $classNameWithNamespace): string {
			$pos = strrpos($classNameWithNamespace, '\\');
			return $pos === false ? $classNameWithNamespace : substr($classNameWithNamespace, $pos + 1);
		}
		
		/**
		 * Converts a reflected type string to a valid PHP type hint, handling union
		 * types (int|string), intersection types (Countable&Iterator), nullable
		 * shorthand (?T), and class names that require a leading backslash.
		 *
		 * @param string $type     The raw type string from reflection
		 * @param bool   $nullable Whether the type is nullable (adds ? prefix for simple types)
		 * @return string
		 */
		private function typeToString(string $type, bool $nullable): string {
			if ($type === '') {
				return '';
			}
			
			// Union or intersection types — qualify each individual segment that needs it,
			// but never add a nullable ? prefix (the type already expresses nullability
			// explicitly if it contains "null").
			if (str_contains($type, '|') || str_contains($type, '&')) {
				$separator = str_contains($type, '|') ? '|' : '&';
				$parts = explode($separator, $type);
				
				$qualifiedParts = array_map(function (string $part) {
					$trimmed = trim($part);
					
					// Preserve leading backslash when already fully qualified
					if (str_starts_with($trimmed, '\\')) {
						return $trimmed;
					}
					
					return in_array($trimmed, self::SCALAR_TYPES, true) ? $trimmed : "\\{$trimmed}";
				}, $parts);
				
				return implode($separator, $qualifiedParts);
			}
			
			// mixed and void cannot be nullable
			if (in_array($type, ['mixed', 'void', 'never'], true)) {
				return $type;
			}
			
			// self, static, parent — pass through without a leading backslash
			if (in_array($type, ['self', 'static', 'parent'], true)) {
				return $nullable ? "?{$type}" : $type;
			}
			
			// Preserve an already-fully-qualified class name
			if (str_starts_with($type, '\\')) {
				return $nullable ? "?{$type}" : $type;
			}
			
			$result = in_array($type, self::SCALAR_TYPES, true) ? $type : "\\{$type}";
			return $nullable ? "?{$result}" : $result;
		}
		
		/**
		 * Builds the constructor parameter string for forwarding arguments to the
		 * parent entity constructor.
		 * @param string $entity Fully-qualified entity class name
		 * @return array{declaration: string, passthrough: string}
		 */
		private function buildParentConstructorArgs(string $entity): array {
			if (!$this->reflectionHandler->hasConstructor($entity)) {
				return ['declaration' => '', 'passthrough' => ''];
			}
			
			$parameters = $this->reflectionHandler->getMethodParameters($entity, '__construct');
			$declaration = [];
			$passthrough = [];
			
			foreach ($parameters as $parameter) {
				$parameterType = $this->typeToString($parameter['type'], $parameter['nullable']);
				
				if (!$parameter['has_default']) {
					$declaration[] = ltrim("{$parameterType} \${$parameter['name']}");
				} elseif ($parameter['default'] === null) {
					$declaration[] = ltrim("{$parameterType} \${$parameter['name']} = null");
				} elseif ($parameter['type'] === 'string') {
					$escaped = addslashes($parameter['default']);
					$declaration[] = ltrim("{$parameterType} \${$parameter['name']} = '{$escaped}'");
				} else {
					$declaration[] = ltrim("{$parameterType} \${$parameter['name']} = {$parameter['default']}");
				}
				
				$passthrough[] = "\${$parameter['name']}";
			}
			
			return [
				'declaration' => implode(', ', $declaration),
				'passthrough' => implode(', ', $passthrough),
			];
		}
		
		/**
		 * Generates the PHP source for all proxy methods of the given entity.
		 * @param string $entity Fully-qualified entity class name
		 * @return string
		 */
		private function makeProxyMethods(string $entity): string {
			$result = [];
			
			$identifierKeys = $this->entityStore->getIdentifierKeys($entity);
			$identifierKeysGetterMethod = 'get' . ucfirst($identifierKeys[0]);
			
			['declaration' => $ctorDeclaration, 'passthrough' => $ctorPassthrough] =
				$this->buildParentConstructorArgs($entity);

			// Only forward args to parent when the entity actually declares a constructor.
			// When there are no parameters, omit the call entirely rather than emitting
			// a bare parent::__construct() that may not exist on the entity.
			if ($ctorPassthrough !== '') {
				$parentCtorCall = "parent::__construct({$ctorPassthrough});";
			} elseif ($this->reflectionHandler->hasConstructor($entity)) {
				$parentCtorCall = "parent::__construct();";
			} else {
				$parentCtorCall = "";
			}

			// Append entity constructor args after $entityManager only when present,
			// avoiding a trailing comma in the signature when the entity has none.
			$ctorExtraArgs = $ctorDeclaration !== '' ? ", {$ctorDeclaration}" : '';

			// The backslash before the FQCN must sit outside the interpolation block;
			// otherwise PHP treats \{ as an escape sequence and emits it literally.
			$entityClass = '\\' . $entity;

			$result[] = <<<PHP

    /**
     * The EntityManager instance used to lazy-load entity data from the database.
     * @var \Quellabs\ObjectQuel\EntityManager
     */
    protected \Quellabs\ObjectQuel\EntityManager \$entityManager;

    /**
     * Whether the proxy has been initialized with actual entity data.
     * @var bool
     */
    protected bool \$initialized;

    /**
     * Creates an uninitialised proxy. Entity data is loaded on first access.
     * @param \Quellabs\ObjectQuel\EntityManager \$entityManager
     */
    public function __construct(\Quellabs\ObjectQuel\EntityManager \$entityManager{$ctorExtraArgs}) {
        \$this->entityManager = \$entityManager;
        \$this->initialized   = false;
        {$parentCtorCall}
    }

    /**
     * Loads the full entity from the database on first access.
     * @return void
     */
    protected function doInitialize(): void {
        \$this->entityManager->find({$entityClass}::class, \$this->{$identifierKeysGetterMethod}());
        \$this->setInitialized();
    }

    /**
     * Returns true when the proxy has been populated with entity data.
     * @return bool
     */
    public function isInitialized(): bool {
        return \$this->initialized;
    }

    /**
     * Marks the proxy as initialised.
     * @return void
     */
    public function setInitialized(): void {
        \$this->initialized = true;
    }
PHP;
			
			foreach ($this->reflectionHandler->getMethods($entity) as $method) {
				// Skip the constructor and the primary-key getter (called before initialization)
				if (in_array($method, ['__construct', $identifierKeysGetterMethod], true)) {
					continue;
				}
				
				// Private methods are not accessible from the proxy subclass, so skip them.
				$visibility = $this->reflectionHandler->getMethodVisibility($entity, $method);
				
				if ($visibility === 'private') {
					continue;
				}
				
				// Gather everything needed to reconstruct the method signature in the proxy.
				$returnType         = $this->reflectionHandler->getMethodReturnType($entity, $method);
				$returnTypeNullable = $this->reflectionHandler->methodReturnTypeIsNullable($entity, $method);
				$docComment         = $this->normalizeDocComment($this->reflectionHandler->getMethodDocComment($entity, $method), '    ');
				$parameters         = $this->reflectionHandler->getMethodParameters($entity, $method);
				
				$parameterList  = [];  // typed declarations, e.g. "string $name = 'foo'"
				$parameterNames = [];  // bare names for the parent:: call, e.g. "$name"
				
				foreach ($parameters as $parameter) {
					$parameterType    = $this->typeToString($parameter['type'], $parameter['nullable']);
					$parameterNames[] = "\${$parameter['name']}";
					
					// Build the typed declaration, handling the three possible default-value forms:
					// no default, explicit null, string literal (needs escaping), or scalar/expression.
					if (!$parameter['has_default']) {
						$parameterList[] = ltrim("{$parameterType} \${$parameter['name']}");
					} elseif ($parameter['default'] === null) {
						$parameterList[] = ltrim("{$parameterType} \${$parameter['name']} = null");
					} elseif ($parameter['type'] === 'string') {
						$escaped = addslashes($parameter['default']);
						$parameterList[] = ltrim("{$parameterType} \${$parameter['name']} = '{$escaped}'");
					} else {
						$parameterList[] = ltrim("{$parameterType} \${$parameter['name']} = {$parameter['default']}");
					}
				}
				
				$parameterString      = implode(', ', $parameterList);
				$parameterNamesString = implode(', ', $parameterNames);
				$returnTypeString     = $this->typeToString($returnType, $returnTypeNullable);
				$returnTypeHint       = $returnTypeString !== '' ? ": {$returnTypeString}" : '';
				
				// Void and never must not have a return statement
				$returnsValue = $returnTypeString !== '' && !in_array($returnTypeString, ['void', 'never'], true);
				$returnStatement = $returnsValue ? 'return ' : '';
				
				// Add function
				$result[] = <<<PHP

{$docComment}
    {$visibility} function {$method}({$parameterString}){$returnTypeHint} {
        \$this->doInitialize();
        {$returnStatement}parent::{$method}({$parameterNamesString});
    }
PHP;
			}
			
			return implode("\n", $result);
		}
		
		/**
		 * Generates the complete proxy class source for a given entity.
		 * @param string $entity Fully-qualified entity class name
		 * @param string|null $overrideNamespace Use a different namespace (for runtime proxies)
		 * @param string|null $overrideClassName Use a different class name (for runtime proxies)
		 * @return string
		 */
		private function makeProxy(string $entity, ?string $overrideNamespace = null, ?string $overrideClassName = null): string {
			$namespace = $overrideNamespace ?? $this->proxyNamespace;
			$shortName = $overrideClassName ?? $this->getClassNameWithoutNamespace($entity);
			$docComment = $this->reflectionHandler->getDocComment($entity);
			
			return $this->renderTemplate(
				$namespace,
				$docComment,
				$shortName,
				$entity,
				$this->makeProxyMethods($entity)
			);
		}
		
		/**
		 * Generates a unique runtime proxy class via eval() for the given entity
		 * and registers it so subsequent calls return the same class name.
		 * @param string $entityClass Fully-qualified entity class name
		 * @return string Fully-qualified runtime proxy class name
		 */
		private function generateRuntimeProxy(string $entityClass): string {
			// Build the proxy source directly, using the target namespace and unique name.
			// This avoids the brittle regex post-processing that the old runtime path required.
			$className     = $this->getClassNameWithoutNamespace($entityClass);
			$uniqueId      = uniqid('', true);
			$proxyShortName = $className . '_' . $uniqueId;
			$proxyFqcn     = $this->proxyNamespace . '\\' . $proxyShortName;
			$proxySource = $this->makeProxy($entityClass, $this->proxyNamespace, $proxyShortName);
			
			// Strip the opening <?php tag
			$evalSource = preg_replace('/^\s*<\?php\s*/i', '', $proxySource, 1);
			
			// Execute through eval()
			eval($evalSource);
			
			// Store proxy
			$this->runtimeProxies[$entityClass] = $proxyFqcn;
			return $proxyFqcn;
		}
	}