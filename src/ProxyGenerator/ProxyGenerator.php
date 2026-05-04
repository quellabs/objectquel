<?php
	
	namespace Quellabs\ObjectQuel\ProxyGenerator;
	
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\ProxyGenerator\Generator\FileProxyGenerator;
	use Quellabs\ObjectQuel\ProxyGenerator\Generator\ProxyCodeGenerator;
	use Quellabs\ObjectQuel\ProxyGenerator\Generator\RuntimeProxyGenerator;
	
	/**
	 * Orchestrates proxy class resolution.
	 * Delegates to FileProxyGenerator when a proxy directory is configured,
	 * or RuntimeProxyGenerator for eval-based on-the-fly generation.
	 */
	class ProxyGenerator {
		
		/** Shared code generator for building proxy source. */
		private ProxyCodeGenerator $codeGenerator;
		
		/** File-based proxy strategy, or null when no proxy directory is configured. */
		private ?FileProxyGenerator $fileProxyGenerator;
		
		/** Runtime eval-based proxy strategy, or null when a proxy directory is configured. */
		private ?RuntimeProxyGenerator $runtimeProxyGenerator;
		
		/**
		 * ProxyGenerator constructor
		 * @param EntityStore $entityStore
		 * @param Configuration $configuration
		 */
		public function __construct(EntityStore $entityStore, Configuration $configuration) {
			$proxyPath = $configuration->getProxyDir() ?: null;
			
			if ($proxyPath === null) {
				throw new \InvalidArgumentException('Proxy path must be configured.');
			}
			
			$proxyNamespace = $entityStore->getProxyNamespace();
			$servicesPaths = $configuration->getEntityPaths();
			
			$this->codeGenerator = new ProxyCodeGenerator($entityStore);
			
			if (!empty($servicesPaths)) {
				$this->fileProxyGenerator = new FileProxyGenerator(
					$entityStore,
					$this->codeGenerator,
					$servicesPaths,
					$proxyPath,
					$proxyNamespace
				);
				$this->runtimeProxyGenerator = null;
			} else {
				$this->fileProxyGenerator = null;
				$this->runtimeProxyGenerator = new RuntimeProxyGenerator($this->codeGenerator, $proxyNamespace);
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
			if ($this->fileProxyGenerator !== null) {
				return $this->fileProxyGenerator->getProxyClass($entityClass);
			}
			
			return $this->runtimeProxyGenerator->getProxyClass($entityClass);
		}
		
		/**
		 * Returns the filesystem path of the proxy file for the given entity.
		 * Only meaningful when a proxy directory is configured.
		 * @param string $targetEntity Fully-qualified entity class name
		 * @return string Absolute path to the proxy file
		 * @throws \LogicException When called without a file-based proxy strategy active
		 */
		public function getProxyFilePath(string $targetEntity): string {
			if ($this->fileProxyGenerator === null) {
				throw new \LogicException('getProxyFilePath() requires a configured proxy directory.');
			}
			
			return $this->fileProxyGenerator->getProxyFilePath($targetEntity);
		}
	}