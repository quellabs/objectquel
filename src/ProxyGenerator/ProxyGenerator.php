<?php
	
	namespace Quellabs\ObjectQuel\ProxyGenerator;
	
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ProxyGenerator\Generator\FileProxyGenerator;
	use Quellabs\ObjectQuel\ProxyGenerator\Generator\ProxyCodeGenerator;
	use Quellabs\ObjectQuel\ProxyGenerator\Generator\ProxyGeneratorInterface;
	use Quellabs\ObjectQuel\ProxyGenerator\Generator\RuntimeProxyGenerator;
	
	/**
	 * Orchestrates proxy class resolution.
	 * Delegates to FileProxyGenerator when a proxy directory is configured,
	 * or RuntimeProxyGenerator for eval-based on-the-fly generation.
	 */
	class ProxyGenerator {
		
		/** Shared code generator for building proxy source. */
		private ProxyCodeGenerator $codeGenerator;
		
		/** Active proxy strategy — file-based or runtime depending on configuration. */
		private ProxyGeneratorInterface $generator;
		
		/**
		 * ProxyGenerator constructor.
		 * When a proxy directory is configured, proxies are written to disk and served
		 * from there. When no proxy directory is configured, proxies are generated
		 * at runtime via eval() and never persisted.
		 * @param EntityStore $entityStore
		 * @param Configuration $configuration
		 */
		public function __construct(EntityStore $entityStore, Configuration $configuration) {
			// Null means no proxy directory was configured — runtime generation will be used.
			$proxyPath = $configuration->getProxyDir() ?: null;
			$proxyNamespace = $entityStore->getProxyNamespace();
			
			// Shared source generator used by both strategies.
			$this->codeGenerator = new ProxyCodeGenerator($entityStore);
			
			if ($proxyPath !== null) {
				// A proxy directory is configured: generate proxy files on disk and serve
				// them from there. Stale proxies are refreshed automatically on boot.
				$this->generator = new FileProxyGenerator(
					$entityStore,
					$this->codeGenerator,
					$configuration->getEntityPaths(),
					$proxyPath,
					$proxyNamespace
				);
			} else {
				// No proxy directory: generate proxies on-the-fly via eval() and cache
				// them in memory for the duration of the request.
				$this->generator = new RuntimeProxyGenerator($this->codeGenerator, $proxyNamespace);
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
			return $this->generator->getProxyClass($entityClass);
		}
		
		/**
		 * Returns the filesystem path of the proxy file for the given entity.
		 * Only meaningful when a proxy directory is configured.
		 * @param string $targetEntity Fully-qualified entity class name
		 * @return string Absolute path to the proxy file
		 * @throws \LogicException When called without a file-based proxy strategy active
		 */
		public function getProxyFilePath(string $targetEntity): string {
			if (!$this->generator instanceof FileProxyGenerator) {
				throw new \LogicException('getProxyFilePath() requires a configured proxy directory.');
			}
			
			return $this->generator->getProxyFilePath($targetEntity);
		}
	}