<?php
	
	namespace Quellabs\ObjectQuel\ProxyGenerator;
	
	use Quellabs\ObjectQuel\EntityManager;
	
	/**
	 * Interface for lazy-loading proxy objects that wrap ORM entities.
	 */
	interface ProxyInterface {
		
		/**
		 * Constructor
		 * @param EntityManager $entityManager Used to load the entity's data on first access.
		 * @param \Closure $initializer Called by doInitialize() to load entity data into this proxy.
		 */
		public function __construct(EntityManager $entityManager, \Closure $initializer);
		
		/**
		 * Returns whether the proxy has been hydrated with entity data.
		 * @return bool True if the underlying entity has been loaded from the database.
		 */
		public function isInitialized(): bool;
		
		/**
		 * Marks the proxy as initialized, preventing redundant database queries
		 * on subsequent property accesses.
		 * @return void
		 */
		public function setInitialized(): void;
		
	}