<?php
	
	namespace Quellabs\ObjectQuel\ReflectionManagement;
	
	use ReflectionClass;
	
	class ReflectionHandler {
		
		private array $reflection_classes;
		private array $file_cache;
		
		/**
		 * ReflectionHandler constructor
		 */
		public function __construct() {
			$this->reflection_classes = [];
			$this->file_cache = [];
		}
		
		/**
		 * Retrieves the content of a file and stores it in a cache.
		 * If the file is already in the cache, the cached version is returned.
		 * @param string $filename The name of the file to be read.
		 * @return array An array of lines from the file.
		 */
		protected function getCachedFile(string $filename): array {
			if (!isset($this->file_cache[$filename])) {
				$this->file_cache[$filename] = file($filename);
			}
			
			return $this->file_cache[$filename];
		}
		
		/**
		 * Retrieves a ReflectionClass instance for the specified class.
		 * @param mixed $class The object or the name of the class to reflect.
		 * @return ReflectionClass A ReflectionClass instance.
		 * @throws \ReflectionException
		 */
		protected function getReflectionClass(mixed $class): ReflectionClass {
			// Determine the class name from the object or directly use the provided class name
			$className = is_object($class) ? get_class($class) : $class;
			
			// Check if the ReflectionClass already exists in cache
			if (!array_key_exists($className, $this->reflection_classes)) {
				// Create a new ReflectionClass and cache it
				$this->reflection_classes[$className] = new ReflectionClass($className);
			}
			
			// Return the cached or newly created ReflectionClass
			return $this->reflection_classes[$className];
		}
		
		/**
		 * Retrieves the name of the parent class for a given class.
		 * @param mixed $class The class name or object to inspect.
		 * @return string|null The name of the parent class as a string, or null if it doesn't exist or an error occurs.
		 */
		public function getParent(mixed $class): ?string {
			try {
				// Initialize ReflectionClass for the specified class name or object.
				$reflectionClass = $this->getReflectionClass($class);
				
				// Get the ReflectionClass of the parent class.
				$parentClass = $reflectionClass->getParentClass();
				
				// Check if the parent class exists.
				if ($parentClass === false) {
					return null;
				}
				
				// If the parent class exists, return the name.
				return $parentClass->getName();
			} catch (\ReflectionException $e) {
				// Return null if an error occurs, such as when the class cannot be found.
				return null;
			}
		}
		
		/**
		 * Retrieves the file path where a specific class is defined.
		 * @param mixed $class The name of the class whose file path we want to look up.
		 * @return string|null The full path to the file where the class is defined, or null if the class is not found.
		 */
		public function getFilename(mixed $class): ?string {
			try {
				// Initialize ReflectionClass for the specified class name.
				$reflectionClass = $this->getReflectionClass($class);
				
				// Get the file path where the class is defined.
				return $reflectionClass->getFileName();
			} catch (\ReflectionException $e) {
				// Return null if an error occurs (e.g., class not found).
				return null;
			}
		}
		
		/**
		 * Fetch the namespace of a given class.
		 * @param mixed $class The fully qualified class name.
		 * @return string|null The namespace name if it exists, otherwise null
		 */
		public function getNamespace(mixed $class): ?string {
			try {
				// Initialize ReflectionClass for the given class name.
				$reflectionClass = $this->getReflectionClass($class);
				
				// Check if the class is actually defined within a namespace.
				if (!$reflectionClass->inNamespace()) {
					return null;
				}
				
				// Return the namespace name.
				return $reflectionClass->getNamespaceName();
			} catch (\ReflectionException $e) {
				return null;
			}
		}
		
		/**
		 * Returns the DocComment for a given class if it exists.
		 * @param mixed $class The class name to fetch the DocComment for.
		 * @return string The DocComment as a string, or an empty string if not found or an error occurs.
		 */
		public function getDocComment(mixed $class): string {
			try {
				// Initialize ReflectionClass for the given class name
				$reflectionClass = $this->getReflectionClass($class);
				
				// Fetch the DocComment from the ReflectionClass
				$docComment = $reflectionClass->getDocComment();
				
				// Return the DocComment if it exists, otherwise return an empty string
				return ($docComment !== false) ? $docComment : "";
			} catch (\ReflectionException $e) {
				return "";
			}
		}

		/**
		 * Returns an array containing the names of all interfaces implemented by a given class.
		 * @param mixed $class The class name to inspect.
		 * @return array An array containing the names of all implemented interfaces, or an empty array if not found or an error occurs.
		 */
		public function getInterfaces(mixed $class): array {
			try {
				// Initialize ReflectionClass for the given class name
				$reflectionClass = $this->getReflectionClass($class);
				
				// Fetch and return the implemented interfaces using ReflectionClass
				return $reflectionClass->getInterfaceNames();
			} catch (\ReflectionException $e) {
				return [];
			}
		}
		
		/**
		 * Get the names of all properties of a given class.
		 * @param mixed $class The class name to inspect.
		 * @param bool $onlyCurrentClass Only list the properties in the current class, not of parents
		 * @return array An array containing the names of all properties.
		 */
		public function getProperties(mixed $class, bool $onlyCurrentClass=false): array {
			try {
				// Initialize ReflectionClass for the given class name
				$reflectionClass = $this->getReflectionClass($class);
				
				// Get all properties of the class
				$properties = $reflectionClass->getProperties();
				
				// Filter out the properties of parents
				if ($onlyCurrentClass) {
					// Store the reflection class name
					$reflectionClassName = $reflectionClass->getName();

					// Perform the filter action
					$properties = array_filter(
						$properties,
						function ($property) use ($reflectionClassName) {
							return $property->getDeclaringClass()->getName() === $reflectionClassName;
						}
					);
				}
				
				// Loop through each property and store its name in the result array
				$result = [];

				foreach ($properties as $property) {
					$result[] = $property->getName();
				}
				
				return $result;
			} catch (\ReflectionException $e) {
				// Return an empty array if a ReflectionException occurs
				return [];
			}
		}
		
		/**
		 * Returns the type of the specified property in a given class.
		 * @param mixed $class The class name to inspect.
		 * @param string $property The property name to fetch the type for.
		 * @return string|null The type of the property, or null if not found or an error occurs.
		 */
		public function getPropertyType(mixed $class, string $property): ?string {
			try {
				// Initialize ReflectionClass for the given class name
				$reflectionClass = $this->getReflectionClass($class);
				
				// Fetch the ReflectionProperty object for the given property name
				$property = $reflectionClass->getProperty($property);
				
				// Get the type of the property, if any
				$typeClass = $property->getType();
				
				// Check if the type is null before proceeding
				if ($typeClass === null) {
					return null;
				}
				
				// If the type is not null, get its name and return
				return $typeClass->getName();
			} catch (\ReflectionException $e) {
				return null;
			}
		}
		
		/**
		 * Returns the visibility (public, protected, or private) of a specified property in a given class.
		 * @param mixed $class The class name to inspect.
		 * @param string $property The property name to fetch the visibility for.
		 * @return string|null The visibility of the property ("public", "protected", or "private"), or null if not found or an error occurs.
		 */
		public function getPropertyVisibility(mixed $class, string $property): ?string {
			try {
				// Initialize ReflectionClass for the given class name
				$reflectionClass = $this->getReflectionClass($class);
				
				// Fetch the ReflectionProperty object for the given property name
				$property = $reflectionClass->getProperty($property);
				
				// Determine and return the visibility of the property
				if ($property->isPrivate()) {
					return "private";
				} elseif ($property->isProtected()) {
					return "protected";
				} else {
					return "public";
				}
			} catch (\ReflectionException $e) {
				return null;
			}
		}
		
		/**
		 * Returns the doc comment of the given property
		 * @param mixed $class The class name to inspect.
		 * @param string $property The property name to fetch the return type for.
		 * @return string The doc comments of the property, or an empty string if there's none
		 */
		public function getPropertyDocComment(mixed $class, string $property): string {
			try {
				// Initialize ReflectionClass for the given class name
				$reflectionClass = $this->getReflectionClass($class);
				
				// Fetch the ReflectionMethod object for the given method name
				$property = $reflectionClass->getProperty($property);
				
				// Get the doc comment
				$docComment = $property->getDocComment();
				
				// return the DocComment
				return ($docComment !== false) ? $docComment : '';
			} catch (\ReflectionException $e) {
				return '';
			}
		}
		
		/**
		 * Returns an array containing the names of all methods of a given class.
		 * @param mixed $class The class name to inspect.
		 * @param bool $onlyCurrentClass Only list the methods in the current class, not of parents
		 * @return array An array containing the names of all methods.
		 */
		public function getMethods(mixed $class, bool $onlyCurrentClass=false): array {
			try {
				// Initialize ReflectionClass for the given class name
				$reflectionClass = $this->getReflectionClass($class);
				
				// Store the reflection class name
				$reflectionClassName = $reflectionClass->getName();
				
				// Declare an array to store the method names
				$result = [];
				
				// Get all methods of the class
				$methods = $reflectionClass->getMethods();
				
				// Filter out the properties of parents
				if ($onlyCurrentClass) {
					$methods = array_filter(
						$methods,
						function ($property) use ($reflectionClassName) {
							return $property->getDeclaringClass()->getName() === $reflectionClassName;
						}
					);
				}
				
				// Loop through each method and store its name in the result array
				foreach ($methods as $method) {
					$result[] = $method->getName();
				}
				
				// Return the array of method names
				return $result;
			} catch (\ReflectionException $e) {
				return [];
			}
		}
		
		/**
		 * Returns the return type of the specified method in a given class.
		 * @param mixed $class The class name to inspect.
		 * @param string $method The method name to fetch the return type for.
		 * @return string The return type of the method
		 */
		public function getMethodReturnType(mixed $class, string $method): string {
			try {
				// Initialize ReflectionClass for the given class name
				$reflectionClass = $this->getReflectionClass($class);
				
				// Fetch the ReflectionMethod object for the given method name
				$method = $reflectionClass->getMethod($method);
				
				// Return null if the method does not have a return type
				if (!$method->hasReturnType()) {
					return "";
				}
				
				// Get and return the return type of the method
				$returnTypeClass = $method->getReturnType();
				
				if ($returnTypeClass instanceof \ReflectionUnionType) {
					return implode("|", array_map(function($type) { return $type->getName(); }, $returnTypeClass->getTypes()));
				}
				
				return $returnTypeClass->getName();
			} catch (\ReflectionException $e) {
				return "";
			}
		}
		
		/**
		 * Returns if the return method type is nullable
		 * @param mixed $class The class name to inspect.
		 * @param string $method The method name to fetch the return type for.
		 * @return bool True if the return type is nullable, false if not
		 */
		public function methodReturnTypeIsNullable(mixed $class, string $method): bool {
			try {
				// Initialize ReflectionClass for the given class name
				$reflectionClass = $this->getReflectionClass($class);
				
				// Fetch the ReflectionMethod object for the given method name
				$method = $reflectionClass->getMethod($method);
				
				// Return null if the method does not have a return type
				if (!$method->hasReturnType()) {
					return false;
				}
				
				// Get and return the return type of the method
				return $method->getReturnType()->allowsNull();
			} catch (\ReflectionException $e) {
				return false;
			}
		}

		/**
		 * Returns the visibility (public, protected, or private) of a specified method in a given class.
		 * @param mixed $class The class name to inspect.
		 * @param string $method The method name to fetch the visibility for.
		 * @return string|null The visibility of the method ("public", "protected", or "private"), or null if not found or an error occurs.
		 */
		public function getMethodVisibility(mixed $class, string $method): ?string {
			try {
				// Initialize ReflectionClass for the given class name
				$reflectionClass = $this->getReflectionClass($class);
				
				// Fetch the ReflectionMethod object for the given method name
				$method = $reflectionClass->getMethod($method);
				
				// Determine and return the visibility of the method
				if ($method->isPrivate()) {
					return "private";
				} elseif ($method->isProtected()) {
					return "protected";
				} else {
					return "public";
				}
			} catch (\ReflectionException $e) {
				return null;
			}
		}
		
		/**
		 * Returns the doc comment of the given method
		 * @param mixed $class The class name to inspect.
		 * @param string $method The method name to fetch the return type for.
		 * @return string The doc comments of the methods, or an empty string if there are none
		 */
		public function getMethodDocComment(mixed $class, string $method): string {
			try {
				// Initialize ReflectionClass for the given class name
				$reflectionClass = $this->getReflectionClass($class);
				
				// Fetch the ReflectionMethod object for the given method name
				$method = $reflectionClass->getMethod($method);
				
				// Get the doc comment
				$docComment = $method->getDocComment();
				
				// return the DocComment
				return ($docComment !== false) ? $docComment : '';
			} catch (\ReflectionException $e) {
				return '';
			}
		}
		
		/**
		 * Determines whether a specific method returns a reference.
		 * @param mixed $class The class name to inspect.
		 * @param string $method The method name to check.
		 * @return bool True if the method returns a reference, false otherwise.
		 */
		public function methodReturnsReference(mixed $class, string $method): bool {
			try {
				// Initialize ReflectionClass for the given class name
				$reflectionClass = $this->getReflectionClass($class);
				
				// Fetch the ReflectionMethod object for the given method name
				$method = $reflectionClass->getMethod($method);
				
				// Use ReflectionMethod to check if the method returns a reference
				return $method->returnsReference();
			} catch (\ReflectionException $e) {
				// Return false if a ReflectionException occurs
				return false;
			}
		}

		/**
		 * Returns an array containing the parameters of a specified method in a given class.
		 * @param mixed $class The class name to inspect.
		 * @param string $method The method name to fetch parameters for.
		 * @return array An array containing details about each parameter (name, type, nullability, default value).
		 */
		public function getMethodParameters(mixed $class, string $method): array {
			try {
				// Initialize ReflectionClass for the given class name
				$reflectionClass = $this->getReflectionClass($class);
				
				// Fetch the ReflectionMethod object for the given method name
				$methodClass = $reflectionClass->getMethod($method);
				
				// Fetch the parameters of the method
				$parameterClass = $methodClass->getParameters();
				
				// Declare an array to store the parameters' details
				$result = [];
				
				// Loop through each parameter and store its details in the result array
				foreach ($parameterClass as $parameter) {
					$type = $parameter->getType();
					$typeName = $type !== null ? $type->getName() : "";
					$isDefaultValueAvailable = $parameter->isDefaultValueAvailable();
					
					$result[] = [
						'index'               => $parameter->getPosition(),
						'name'                => $parameter->getName(),
						'type'                => $typeName,
						'nullable'            => $parameter->allowsNull(),
						'has_default'         => $isDefaultValueAvailable,
						'default'             => $isDefaultValueAvailable ? $parameter->getDefaultValue() : null,
						'passed_by_reference' => $parameter->isPassedByReference(),
					];
				}
				
				// Return the array containing the parameters' details
				return $result;
			} catch (\ReflectionException $e) {
				return [];
			}
		}
		
		/**
		 * Retrieves the body of a specified method from a given class.
		 * This function uses the Reflection API to find the filename and the start and end lines of the method.
		 * It then reads the source code of the file and extracts the method body.
		 * The function also accounts for different coding styles for placing braces.
		 * @param mixed $class  The name of the class or an instance of the class.
		 * @param string $method  The name of the method whose body should be retrieved.
		 * @return string  The body of the specified method as a string.
		 */
		public function getMethodBody(mixed $class, string $method): string {
			try {
				// Create a ReflectionClass object of the specified class
				$reflectionClass = new \ReflectionClass($class);
				
				// Get the ReflectionMethod object for the specified method
				$methodClass = $reflectionClass->getMethod($method);
				
				// Determine the filename and start and end lines of the method
				$fileName = $methodClass->getFileName();
				$startLine = $methodClass->getStartLine() - 1; // Include the function definition
				$endLine = $methodClass->getEndLine();
				
				// Read the file into an array of lines
				$lines = $this->getCachedFile($fileName);
				
				// Get only the lines that form the method body
				$bodyLines = array_slice($lines, $startLine, $endLine - $startLine);
				
				// Find the first opening brace in the first line
				$firstLine = $bodyLines[0];
				$startPos = strpos($firstLine, '{');
				
				// Find the last closing brace in the last line
				$lastLine = end($bodyLines);
				$endPos = strrpos($lastLine, '}');
				
				// Adjust the first and last line to remove the opening '{' and closing '}' respectively
				$bodyLines[0] = substr($firstLine, $startPos + 1);
				$bodyLines[count($bodyLines) - 1] = substr($lastLine, 0, $endPos);
				
				// Combine the adjusted lines into a single string and return it as the method body
				return implode("", $bodyLines);
			} catch (\ReflectionException $e) {
				// Return an empty string if an exception occurs
				return "";
			}
		}
		
		/**
		 * Removes PHP comments from a given string.
		 * This function removes all types of PHP comments from the provided string.
		 * @param string $code The string from which comments should be removed.
		 * @return string The string without PHP comments.
		 */
		public function removePHPComments(string $code): string {
			// Remove /** */ and /* */ block comments
			$code = preg_replace('!/\*.*?\*/!s', '', $code);
			
			// Remove // line comments
			return preg_replace('!//.*?$!m', '', $code);
		}
		
		/**
		 * Returns true if the class has a constructor, false if not.
		 * @param mixed $class
		 * @return bool
		 */
		public function hasConstructor(mixed $class): bool {
			return in_array("__construct", $this->getMethods($class));
		}
	}