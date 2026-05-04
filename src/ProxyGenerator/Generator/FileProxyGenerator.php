<?php
	
	namespace Quellabs\ObjectQuel\ProxyGenerator\Generator;
	
	use Quellabs\ObjectQuel\EntityStore;
	
	/**
	 * Generates and maintains proxy files on disk.
	 * Scans entity paths, detects stale proxies, and writes refreshed files
	 * with per-entity locking to handle concurrent PHP-FPM workers safely.
	 */
	class FileProxyGenerator implements ProxyGeneratorInterface {
		
		/** The entity store, used to check entity registration and normalize names. */
		private EntityStore $entityStore;
		
		/** Shared code generator for building proxy source. */
		private ProxyCodeGenerator $codeGenerator;
		
		/**
		 * List of filesystem paths to scan for entity source files.
		 * @var string[]
		 */
		private array $servicesPaths;
		
		/** Absolute path to the directory where proxy files are written. */
		private string $proxyPath;
		
		/** Namespace to use for generated proxy classes. */
		private string $proxyNamespace;
		
		/**
		 * @param EntityStore $entityStore
		 * @param ProxyCodeGenerator $codeGenerator
		 * @param string[] $servicesPaths
		 * @param string $proxyPath
		 * @param string $proxyNamespace
		 */
		public function __construct(
			EntityStore        $entityStore,
			ProxyCodeGenerator $codeGenerator,
			array              $servicesPaths,
			string             $proxyPath,
			string             $proxyNamespace
		) {
			$this->entityStore = $entityStore;
			$this->codeGenerator = $codeGenerator;
			$this->servicesPaths = $servicesPaths;
			$this->proxyPath = $proxyPath;
			$this->proxyNamespace = $proxyNamespace;
			
			$this->createProxyPathIfNotPresent();
			$this->initializeProxies();
		}
		
		/**
		 * Returns the filesystem path of the proxy file for the given entity.
		 * @param string $targetEntity Fully-qualified entity class name
		 * @return string Absolute path to the proxy file
		 */
		public function getProxyFilePath(string $targetEntity): string {
			$normalizedEntity = $this->entityStore->normalizeEntityName($targetEntity);
			$shortClassName = $this->codeGenerator->getClassNameWithoutNamespace($normalizedEntity);
			return $this->proxyPath . DIRECTORY_SEPARATOR . $shortClassName . '.php';
		}
		
		/**
		 * Returns the fully-qualified proxy class name for the given entity.
		 * @param string $entityClass Fully-qualified entity class name
		 * @return string Fully-qualified proxy class name
		 */
		public function getProxyClass(string $entityClass): string {
			return $this->proxyNamespace . '\\' . $this->codeGenerator->getClassNameWithoutNamespace($entityClass);
		}
		
		/**
		 * Create the proxy directory if it is missing.
		 * Throws a RuntimeException if the directory cannot be created.
		 * @return void
		 */
		private function createProxyPathIfNotPresent(): void {
			if (is_dir($this->proxyPath)) {
				return;
			}
			
			if (!mkdir($this->proxyPath, 0755, true) && !is_dir($this->proxyPath)) {
				throw new \RuntimeException("Cannot create proxy directory: {$this->proxyPath}");
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
					
					// Use the filepath to construct an entity namespace
					$entityName = $this->constructEntityName($entityFilePath);
					
					// resolveEntityClass throws an exception when the entity does not lead to an actual object
					$entityName = $this->entityStore->resolveEntityClass($entityName);
					
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
						error_log("FileProxyGenerator: Could not create lock file for entity: {$fileName}");
						continue;
					}
					
					try {
						if (flock($lockHandle, LOCK_EX)) {
							// Double-check after acquiring the lock — another process may have
							// already regenerated the proxy while we were waiting.
							// @phpstan-ignore if.alwaysTrue
							if ($this->isOutdated($entityFilePath)) {
								$proxyFilePath = $this->proxyPath . DIRECTORY_SEPARATOR . $fileName;
								$proxyContents = $this->codeGenerator->makeProxy($entityName, $this->proxyNamespace);
								
								if (file_put_contents($proxyFilePath, $proxyContents) === false) {
									error_log("FileProxyGenerator: Failed to write proxy file: {$proxyFilePath}");
								}
							}
							
							flock($lockHandle, LOCK_UN);
						} else {
							error_log("FileProxyGenerator: Could not acquire exclusive lock for entity: {$fileName}");
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
			$fileContents = file_get_contents($entityFilePath);
			
			if ($fileContents === false) {
				return basename($entityFilePath, '.php');
			}
			
			$namespace = $this->extractNamespaceFromFile($fileContents);
			$className = $this->extractClassNameFromFile($fileContents);
			
			if ($namespace !== null && $className !== null) {
				return $namespace . '\\' . $className;
			}
			
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
	}