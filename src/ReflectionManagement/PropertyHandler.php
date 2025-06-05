<?php
	
	namespace Quellabs\ObjectQuel\ReflectionManagement;
	
	/**
	 * Lists, gets and sets object properties through reflection
	 * Class PropertyHandler
	 * @package Services\AnnotationsReader
	 */
	class PropertyHandler {
		
		private array $reflection_classes = [];
		private array $reflection_properties = [];
		
		/**
		 * Returns true if the property exists, false if not
		 * @param string|object $objectOrClass
		 * @param string $propertyName
		 * @return bool
		 */
		public function exists(string|object $objectOrClass, string $propertyName): bool {
			try {
				$reflection = $this->getReflectionClass($objectOrClass);
				return $reflection->hasProperty($propertyName);
			} catch (\ReflectionException $e) {
				return false;
			}
		}
		
		/**
		 * Gets a property value from an object using reflection
		 * @param string|object $object The object instance to get the property from
		 * @param string $propertyName The name of the property to retrieve
		 * @return mixed The property value if accessible, null if uninitialized, false on error
		 */
		public function get(string|object $object, string $propertyName): mixed {
			try {
				// Get the reflection property object for the given property name
				$reflection = $this->getReflectionProperty($object, $propertyName);
				
				// Critical check: Determine if the property has been initialized
				// This prevents the "must not be accessed before initialization" Error
				// that occurs with typed properties in PHP 7.4+
				if (!$reflection->isInitialized($object)) {
					// Return type-compatible default value for uninitialized typed properties
					$type = $reflection->getType();
					
					if ($type instanceof \ReflectionNamedType && !$type->allowsNull()) {
						return match ($type->getName()) {
							'string' => '',
							'int' => 0,
							'float' => 0.0,
							'bool' => false,
							'array' => [],
							default => null
						};
					}
					
					return null;
				}
				
				// Property is initialized, so it's safe to access its value
				return $reflection->getValue($object);
			} catch (\ReflectionException $e) {
				// Property doesn't exist or isn't accessible
				// In a production system, you might want to log this exception
				return false;
			}
		}
		
		/**
		 * Sets a property value
		 * @param string|object $object
		 * @param string $propertyName
		 * @param mixed $value
		 * @return bool
		 */
		public function set(string|object $object, string $propertyName, mixed $value): bool {
			try {
				$reflection = $this->getReflectionProperty($object, $propertyName);
				$reflection->setValue($object, $value);
				return true;
			} catch (\ReflectionException $e) {
				return false;
			}
		}
		
		/**
		 * Retrieves a ReflectionClass instance for the specified class.
		 * @param string|object $class The object or the name of the class to reflect.
		 * @return \ReflectionClass A ReflectionClass instance.
		 * @throws \ReflectionException
		 */
		private function getReflectionClass(string|object $class): \ReflectionClass {
			// Determine the class name from the object or directly use the provided class name
			$className = is_object($class) ? get_class($class) : $class;
			
			// Check if the ReflectionClass already exists in cache
			if (!array_key_exists($className, $this->reflection_classes)) {
				// Create a new ReflectionClass and cache it
				$this->reflection_classes[$className] = new \ReflectionClass($className);
			}
			
			// Return the cached or newly created ReflectionClass
			return $this->reflection_classes[$className];
		}

		/**
		 * Retrieves a ReflectionProperty instance for the specified property of a class.
		 * @param string|object $class The object or the name of the class to get the property from.
		 * @param string $propertyName The name of the property to reflect.
		 * @return \ReflectionProperty A ReflectionProperty instance.
		 */
		private function getReflectionProperty(string|object $class, string $propertyName): \ReflectionProperty {
			// Determine the class name from the object or directly use the provided class name
			$className = is_object($class) ? get_class($class) : $class;
			
			// Create a key based on the class name and property name
			$key = "{$className}:{$propertyName}";
			
			// Check if the ReflectionProperty already exists in cache
			if (!array_key_exists($key, $this->reflection_properties)) {
				// Create a new ReflectionProperty and make it accessible
				$this->reflection_properties[$key] = $this->findPropertyInHierarchy($className, $propertyName);
				$this->reflection_properties[$key]->setAccessible(true);
			}
			
			// Return the cached or newly created ReflectionProperty
			return $this->reflection_properties[$key];
		}
		
		/**
		 * Retrieves the correct ReflectionProperty for a given property name in the class hierarchy.
		 * @param string|object $class The class name or object to inspect.
		 * @param string $propertyName The name of the property to search for.
		 * @return \ReflectionProperty|null The ReflectionProperty object if found, or null otherwise.
		 */
		private function findPropertyInHierarchy(string|object $class, string $propertyName): ?\ReflectionProperty {
			try {
				// Initialize ReflectionClass for the given class name or object
				$reflectionClass = $this->getReflectionClass($class);
				
				// Loop through the class hierarchy until the property is found or until there are no more parent classes
				do {
					// Check if the current class in the hierarchy has the property
					if ($reflectionClass->hasProperty($propertyName)) {
						// If property exists, return the ReflectionProperty object
						return $reflectionClass->getProperty($propertyName);
					}
					
					// Move to the parent class for the next iteration
					$reflectionClass = $reflectionClass->getParentClass();
				} while ($reflectionClass !== false);  // Continue as long as there is a parent class
			} catch (\ReflectionException $e) {
			}
			
			// Return null if the property is not found in any class in the hierarchy
			return null;
		}
	}