<?php
	
	namespace Quellabs\ObjectQuel\ProxyGenerator\Generator;
	
	use Quellabs\ObjectQuel\EntityStore;
	
	/**
	 * Generates proxy classes at runtime via eval() for environments where
	 * no proxy directory is configured.
	 */
	class RuntimeProxyGenerator implements ProxyGeneratorInterface {
		
		/** The entity store, used to check entity registration and normalize names. */
		private EntityStore $entityStore;
		
		/** Shared code generator for building proxy source. */
		private ProxyCodeGenerator $codeGenerator;
		
		/** Namespace to use for generated proxy classes. */
		private string $proxyNamespace;
		
		/**
		 * Cache of entity class name → proxy class name for proxies generated via eval().
		 * @var array<string, string>
		 */
		private array $runtimeProxies = [];
		
		/**
		 * @param EntityStore $entityStore
		 * @param ProxyCodeGenerator $codeGenerator
		 * @param string $proxyNamespace
		 */
		public function __construct(EntityStore $entityStore, ProxyCodeGenerator $codeGenerator, string $proxyNamespace) {
			$this->codeGenerator = $codeGenerator;
			$this->entityStore = $entityStore;
			$this->proxyNamespace = $proxyNamespace;
		}
		
		/**
		 * Returns the fully-qualified proxy class name for the given entity,
		 * generating it on first access via eval().
		 * @param string $entityClass Fully-qualified entity class name
		 * @return string Fully-qualified proxy class name
		 */
		public function getProxyClass(string $entityClass): string {
			if (isset($this->runtimeProxies[$entityClass])) {
				return $this->runtimeProxies[$entityClass];
			}
			
			return $this->generateRuntimeProxy($entityClass);
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
			$className = $this->codeGenerator->getClassNameWithoutNamespace($entityClass);
			
			// resolveEntityClass throws an exception when the entity does not lead to an actual object
			$entityName = $this->entityStore->resolveEntityClass($entityClass);
			
			// Build the proxy
			$uniqueId = uniqid('', true);
			$proxyShortName = $className . '_' . $uniqueId;
			$proxyFqcn = $this->proxyNamespace . '\\' . $proxyShortName;
			$proxySource = $this->codeGenerator->makeProxy($entityName, $this->proxyNamespace, $proxyShortName);
			
			// Strip the opening <?php tag before passing to eval()
			eval(preg_replace('/^\s*<\?php\s*/i', '', $proxySource, 1));

			// Add proxy to runtime list
			$this->runtimeProxies[$entityClass] = $proxyFqcn;
			return $proxyFqcn;
		}
	}