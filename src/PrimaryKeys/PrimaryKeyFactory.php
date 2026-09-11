<?php
	
	namespace Quellabs\ObjectQuel\PrimaryKeys;

	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\OrmException;

	/**
	 * Factory class responsible for creating primary key generators
	 * and generating primary key values based on the requested strategy.
	 */
	class PrimaryKeyFactory {

		/**
		 * Generates a primary key value using the specified generator type.
		 * $type comes straight from an @Orm\PrimaryKeyStrategy annotation
		 * value, unvalidated at mapping time — an unresolvable one is a
		 * configuration error, so it's reported here (the one place that
		 * actually knows why) rather than by callers inferring failure from
		 * a null return, which would also be indistinguishable from
		 * IdentityGenerator's legitimate null.
		 * @param EntityManager $em    The entity manager instance for database operations
		 * @param object $entity       The entity object requiring a primary key
		 * @param string $type         The type of primary key generator to use (e.g., 'uuid', 'sequence', 'identity')
		 * @return mixed               The generated primary key value, or null only for the 'identity' strategy
		 * @throws OrmException If $type has no matching generator class, or that class doesn't implement PrimaryKeyGeneratorInterface
		 */
		public function generate(EntityManager $em, object $entity, string $type): mixed {
			// Construct the fully qualified class name for the requested generator
			// by converting the first letter of type to uppercase and appending "Generator"
			$className = "\\Quellabs\\ObjectQuel\\PrimaryKeys\\Generators\\" . ucfirst($type) . "Generator";

			// Check if the generator class exists in the application
			if (!class_exists($className)) {
				throw new OrmException("Unknown primary key strategy '{$type}': no generator class '{$className}' exists");
			}

			// Check that the class implements PrimaryKeyGeneratorInterface
			if (!is_subclass_of($className, PrimaryKeyGeneratorInterface::class)) {
				throw new OrmException("Primary key generator '{$className}' does not implement " . PrimaryKeyGeneratorInterface::class);
			}

			// Instantiate the generator class
			$generator = new $className();

			// Generate the primary key value
			// Pass the entity manager and entity to the generator for context-aware key generation
			$value = $generator->generate($em, $entity);

			// Only IdentityGenerator is allowed to return null (the database
			// assigns identity columns itself) — a null from any other
			// generator means it's misbehaving, so fail loudly here rather
			// than let callers silently write a null primary key.
			if ($value === null && $type !== 'identity') {
				throw new OrmException("Primary key generator '{$className}' returned null for strategy '{$type}'");
			}

			return $value;
		}
	}