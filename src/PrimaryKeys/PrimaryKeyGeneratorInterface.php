<?php
	
	namespace Quellabs\ObjectQuel\PrimaryKeys;
	
	use Quellabs\ObjectQuel\EntityManager;
	
	/**
	 * Interface for primary key generation strategies
	 */
	interface PrimaryKeyGeneratorInterface {
	
		/**
		 * Generate a primary key for the given entity
		 * @param EntityManager $em The EntityManager instance, providing access to necessary services and metadata
		 * @param object $entity The entity object for which to generate a primary key
		 * @return mixed The generated primary key value, or null if the database should handle key generation
		 */
		public function generate(EntityManager $em, object $entity): mixed;
	}