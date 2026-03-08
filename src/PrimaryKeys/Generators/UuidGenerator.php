<?php
	
	namespace Quellabs\ObjectQuel\PrimaryKeys\Generators;
	
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\PrimaryKeys\PrimaryKeyGeneratorInterface;
	use Quellabs\Support\Tools;
	
	/**
	 * Generates UUID v7 primary keys
	 */
	class UuidGenerator implements PrimaryKeyGeneratorInterface {
		
		/**
		 * Generate a UUID v7 primary key
		 * @param EntityManager $em The EntityManager instance (unused)
		 * @param object $entity The entity object (unused)
		 * @return string A UUID v7 string (e.g., "018f6e3a-7b2c-7000-8000-5e1234567890")
		 * @throws \Exception
		 */
		public function generate(EntityManager $em, object $entity): string {
			return Tools::createUUIDv7();
		}
		
	}