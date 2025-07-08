<?php
	
	namespace Quellabs\ObjectQuel\PrimaryKeys;
	
	use Quellabs\ObjectQuel\EntityManager;
	
	/**
	 * Factory class responsible for creating primary key generators
	 * and generating primary key values based on the requested strategy.
	 */
	class PrimaryKeyFactory {
		
		/**
		 * Generates a primary key value using the specified generator type.
		 * @param EntityManager $em    The entity manager instance for database operations
		 * @param object $entity       The entity object requiring a primary key
		 * @param string $type         The type of primary key generator to use (e.g., 'uuid', 'increment')
		 * @return mixed|null          The generated primary key value or null if generator not found
		 */
		public function generate(EntityManager $em, object $entity, string $type): mixed {
			// Construct the fully qualified class name for the requested generator
			// by converting the first letter of type to uppercase and appending "Generator"
			$className = "\\Quellabs\\ObjectQuel\\PrimaryKeys\\Generators\\" . ucfirst($type) . "Generator";
			
			// Check if the generator class exists in the application
			if (!class_exists($className)) {
				// Return null if the requested generator type doesn't exist
				return null;
			}
			
			// Instantiate the generator class
			$generator = new $className();
			
			// Generate and return the primary key value
			// Pass the entity manager and entity to the generator for context-aware key generation
			return $generator->generate($em, $entity);
		}
	}