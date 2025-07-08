<?php
	
	namespace Quellabs\ObjectQuel\ProxyGenerator;
	
	use Quellabs\ObjectQuel\EntityManager;
	
	interface ProxyInterface {
	
		public function __construct(EntityManager $entityManager);
		public function isInitialized(): bool;
		public function setInitialized(): void;

	}