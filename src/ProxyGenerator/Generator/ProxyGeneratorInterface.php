<?php
	
	namespace Quellabs\ObjectQuel\ProxyGenerator\Generator;
	
	interface ProxyGeneratorInterface {
		
		/**
		 * Returns the fully-qualified proxy class name for the given entity.
		 * @param string $entityClass Fully-qualified entity class name
		 * @return string Fully-qualified proxy class name
		 */
		public function getProxyClass(string $entityClass): string;
	}