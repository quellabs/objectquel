<?php
	
	namespace Quellabs\ObjectQuel\ProxyGenerator\Generator;
	
	/**
	 * Generates proxy classes at runtime via eval() for environments where
	 * no proxy directory is configured.
	 */
	class RuntimeProxyGenerator implements ProxyGeneratorInterface {
		
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
		 * @param ProxyCodeGenerator $codeGenerator
		 * @param string $proxyNamespace
		 */
		public function __construct(ProxyCodeGenerator $codeGenerator, string $proxyNamespace) {
			$this->codeGenerator = $codeGenerator;
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
			$uniqueId = uniqid('', true);
			$proxyShortName = $className . '_' . $uniqueId;
			$proxyFqcn = $this->proxyNamespace . '\\' . $proxyShortName;
			$proxySource = $this->codeGenerator->makeProxy($entityClass, $this->proxyNamespace, $proxyShortName);
			
			// Strip the opening <?php tag before passing to eval()
			$evalSource = preg_replace('/^\s*<\?php\s*/i', '', $proxySource, 1);
			
			eval($evalSource);
			
			$this->runtimeProxies[$entityClass] = $proxyFqcn;
			return $proxyFqcn;
		}
	}