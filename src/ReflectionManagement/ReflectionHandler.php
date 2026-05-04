<?php
	
	namespace Quellabs\ObjectQuel\ReflectionManagement;
	
	use ReflectionClass;
	
	class ReflectionHandler {
		
		/**
		 * @var array<string, ReflectionClass<object>>
		 */
		private array $reflection_classes;
		
		/**
		 * @var array<string, array<int, string>>
		 */
		private array $file_cache;
		
		/**
		 * ReflectionHandler constructor
		 */
		public function __construct() {
			$this->reflection_classes = [];
			$this->file_cache = [];
		}
		
		/**
		 * Retrieves the name of the parent class for a given class.
		 * @param class-string|object $class The class name or object to inspect.
		 * @return string|null The name of the parent class as a string, or null if it doesn't exist or an error occurs.
		 */
		public function getParent(object|string $class): ?string {
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
		 * @param class-string|object $class The name of the class whose file path we want to look up.
		 * @return string|null The full path to the file where the class is defined, or null if the class is not found.
		 */
		public function getFilename(object|string $class): ?string {
			try {
				// Initialize ReflectionClass for the specified class name.
				$reflectionClass = $this->getReflectionClass($class);
				
				// Get the file path where the class is defined.
				$filename = $reflectionClass->getFileName();
				
				// If no filename present, return null
				if ($filename === false) {
					return null;
				}
				
				// Return the filename
				return $filename;
			} catch (\ReflectionException $e) {
				// Return null if an error occurs (e.g., class not found).
				return null;
			}
		}
		
		/**
		 * Fetch the namespace of a given class.
		 * @param class-string|object $class The fully qualified class name.
		 * @return string|null The namespace name if it exists, otherwise null
		 */
		public function getNamespace(object|string $class): ?string {
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
		 * @param class-string|object $class The class name to fetch the DocComment for.
		 * @return string The DocComment as a string, or an empty string if not found or an error occurs.
		 */
		public function getDocComment(object|string $class): string {
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
		 * @param class-string|object $class The class name to inspect.
		 * @return array<int, string> An array containing the names of all implemented interfaces, or an empty array if not found or an error occurs.
		 */
		public function getInterfaces(object|string $class): array {
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
		 * @param class-string|object $class The class name to inspect.
		 * @param bool $onlyCurrentClass Only list the properties in the current class, not of parents
		 * @return array<int, string> An array containing the names of all properties.
		 */
		public function getProperties(object|string $class, bool $onlyCurrentClass = false): array {
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
		 * @param class-string|object $class The class name to inspect.
		 * @param string $property The property name to fetch the type for.
		 * @return string|null The type of the property, or null if not found or an error occurs.
		 */
		public function getPropertyType(object|string $class, string $property): ?string {
			try {
				// Initialize ReflectionClass for the given class name
				$reflectionClass = $this->getReflectionClass($class);
				
				// Fetch the ReflectionProperty object for the given property name
				$property = $reflectionClass->getProperty($property);
				
				// Get the type of the property, if any
				$type = $property->getType();
				
				// Check if the type is null before proceeding
				if ($type === null) {
					return null;
				}
				
				// Convert the type to string
				$typeString = $this->reflectionTypeToString($type);
				return $typeString !== "" ? $typeString : null;
			} catch (\ReflectionException $e) {
				return null;
			}
		}
		
		/**
		 * Returns the visibility (public, protected, or private) of a specified property in a given class.
		 * @param class-string|object $class The class name to inspect.
		 * @param string $property The property name to fetch the visibility for.
		 * @return string|null The visibility of the property ("public", "protected", or "private"), or null if not found or an error occurs.
		 */
		public function getPropertyVisibility(object|string $class, string $property): ?string {
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
		 * @param class-string|object $class The class name to inspect.
		 * @param string $property The property name to fetch the return type for.
		 * @return string The doc comments of the property, or an empty string if there's none
		 */
		public function getPropertyDocComment(object|string $class, string $property): string {
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
		 * @param class-string|object $class The class name to inspect.
		 * @param bool $onlyCurrentClass Only list the methods in the current class, not of parents
		 * @return array<int, string> An array containing the names of all methods.
		 */
		public function getMethods(object|string $class, bool $onlyCurrentClass = false): array {
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
		 * @param class-string|object $class The class name to inspect.
		 * @param string $method The method name to fetch the return type for.
		 * @return string The return type of the method
		 */
		public function getMethodReturnType(object|string $class, string $method): string {
			try {
				// Initialize ReflectionClass for the given class name
				$reflectionClass = $this->getReflectionClass($class);
				
				// Fetch the ReflectionMethod object for the given method name
				$method = $reflectionClass->getMethod($method);
				
				// Return empty string if the method does not have a return type
				if (!$method->hasReturnType()) {
					return "";
				}
				
				// Get the return type and convert to string
				return $this->reflectionTypeToString($method->getReturnType());
			} catch (\ReflectionException $e) {
				return "";
			}
		}
		
		/**
		 * Returns if the return method type is nullable
		 * @param class-string|object $class The class name to inspect.
		 * @param string $method The method name to fetch the return type for.
		 * @return bool True if the return type is nullable, false if not
		 */
		public function methodReturnTypeIsNullable(object|string $class, string $method): bool {
			try {
				// Initialize ReflectionClass for the given class name
				$reflectionClass = $this->getReflectionClass($class);
				
				// Fetch the ReflectionMethod object for the given method name
				$method = $reflectionClass->getMethod($method);
				
				// Return false if the method does not have a return type
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
		 * @param class-string|object $class The class name to inspect.
		 * @param string $method The method name to fetch the visibility for.
		 * @return string|null The visibility of the method ("public", "protected", or "private"), or null if not found or an error occurs.
		 */
		public function getMethodVisibility(object|string $class, string $method): ?string {
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
		 * @param class-string|object $class The class name to inspect.
		 * @param string $method The method name to fetch the return type for.
		 * @return string The doc comments of the methods, or an empty string if there are none
		 */
		public function getMethodDocComment(object|string $class, string $method): string {
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
		 * @param class-string|object $class The class name to inspect.
		 * @param string $method The method name to check.
		 * @return bool True if the method returns a reference, false otherwise.
		 */
		public function methodReturnsReference(object|string $class, string $method): bool {
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
		 * @param class-string|object $class The class name to inspect.
		 * @param string $method The method name to fetch parameters for.
		 * @return array<int, array{
		 *     index: int,
		 *     name: string,
		 *     type: string,
		 *     nullable: bool,
		 *     has_default: bool,
		 *     default: mixed,
		 *     passed_by_reference: bool
		 * }>
		 */
		public function getMethodParameters(object|string $class, string $method): array {
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
					$isDefaultValueAvailable = $parameter->isDefaultValueAvailable();
					
					$result[] = [
						'index'               => $parameter->getPosition(),
						'name'                => $parameter->getName(),
						'type'                => $type !== null ? $this->reflectionTypeToString($type) : "",
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
		 * Retrieves the body of a specified method from a given class, without
		 * the surrounding signature and braces — just the executable statements inside.
		 *
		 * Uses the Reflection API to locate the source file and line range, then
		 * slices and strips the opening/closing brace. Handles both K&R style
		 * (brace on signature line) and Allman style (brace on its own line).
		 * Results are not cached here; file I/O is delegated to getCachedFile().
		 *
		 * @param class-string|object $class A class name or an instance of the class.
		 * @param string $method The method name to extract.
		 * @return string               The raw method body, or an empty string on failure.
		 */
		public function getMethodBody(object|string $class, string $method): string {
			try {
				// Fetch method reflection data
				$reflectionClass = $this->getReflectionClass($class);
				$reflectionMethod = $reflectionClass->getMethod($method);
				
				// Internal/built-in methods have no source file.
				$fileName = $reflectionMethod->getFileName();
				
				if ($fileName === false) {
					return "";
				}
				
				// Reflection line numbers are 1-based; convert to 0-based for array_slice.
				// getStartLine() points to the first line of the signature (or the opening
				// brace in Allman style), getEndLine() points to the closing '}'.
				$startLine = $reflectionMethod->getStartLine() - 1;
				$endLine = $reflectionMethod->getEndLine(); // inclusive, 1-based → exclusive after -1 cancel
				
				// Fetch file contents
				$lines = $this->getCachedFile($fileName);
				
				// Extract the body of the requested method
				$bodyLines = array_slice($lines, $startLine, $endLine - $startLine);
				
				// If that failed, return empty string
				if ($bodyLines === []) {
					return "";
				}
				
				// Find the opening brace. It is on the signature line in K&R style but
				// may appear on a subsequent line in Allman style or when the parameter
				// list wraps across multiple lines. Scan forward until we find it.
				$openingBraceLineIndex = null;
				$openingBraceCharPos = false;
				
				foreach ($bodyLines as $index => $line) {
					$pos = strpos($line, '{');
					
					if ($pos !== false) {
						$openingBraceLineIndex = $index;
						$openingBraceCharPos = $pos;
						break;
					}
				}
				
				// Find the closing brace on the last line (strrpos handles any inline
				// code before it, though in practice the closing '}' is always alone).
				$lastIndex = count($bodyLines) - 1;
				$closingBraceCharPos = strrpos($bodyLines[$lastIndex], '}');
				
				// If either brace is missing the source is malformed or unparseable;
				// return the raw slice so the caller at least has something to work with.
				if ($openingBraceLineIndex === null || $closingBraceCharPos === false) {
					return implode("", $bodyLines);
				}
				
				// Strip everything up to and including the opening brace on its line.
				$bodyLines[$openingBraceLineIndex] = substr(
					$bodyLines[$openingBraceLineIndex],
					$openingBraceCharPos + 1
				);
				
				// Strip the closing brace (and anything after it, e.g. a trailing comment).
				$bodyLines[$lastIndex] = substr($bodyLines[$lastIndex], 0, $closingBraceCharPos);
				
				// Drop any lines before the opening brace (the signature lines in Allman style).
				if ($openingBraceLineIndex > 0) {
					$bodyLines = array_slice($bodyLines, $openingBraceLineIndex);
				}
				
				// Return result
				return implode("", $bodyLines);
			} catch (\ReflectionException $e) {
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
		 * @param class-string|object $class
		 * @return bool
		 */
		public function hasConstructor(object|string $class): bool {
			return in_array("__construct", $this->getMethods($class));
		}
		
		/**
		 * Ensures the given value is a valid class-string and returns it.
		 * @param object|string $class
		 * @return class-string
		 * @throws \ReflectionException
		 */
		private function normalizeClassName(object|string $class): string {
			if (is_object($class)) {
				return $class::class;
			}
			
			if (!class_exists($class)) {
				throw new \ReflectionException("Class '$class' does not exist");
			}
			
			return $class;
		}
		
		/**
		 * Returns the lines of a file, using a cache to avoid repeated reads.
		 * Lines are returned without trailing newlines or carriage returns.
		 *
		 * @param string $filename Absolute or relative path to the file.
		 * @return array<int, string> Lines of the file, stripped of line endings, or empty array on failure.
		 */
		protected function getCachedFile(string $filename): array {
			// Return cached result if available, even if it's an empty array from a previous failure
			if (array_key_exists($filename, $this->file_cache)) {
				return $this->file_cache[$filename];
			}
			
			// Resolve the real path to catch traversal attempts and confirm the file exists
			$realPath = realpath($filename);
			
			// Cache and return empty array if the file doesn't exist or isn't readable
			if ($realPath === false || !is_readable($realPath)) {
				return $this->file_cache[$filename] = [];
			}
			
			// FILE_IGNORE_NEW_LINES strips trailing newlines; FILE_SKIP_EMPTY_LINES omits blank lines
			$lines = file($realPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			
			// Cache the result; fall back to empty array if file() failed despite passing the readability check
			return $this->file_cache[$filename] = ($lines !== false ? $lines : []);
		}
		
		/**
		 * Retrieves a cached ReflectionClass instance for the given class or object.
		 * @template T of object
		 * @param class-string<T>|T $class
		 * @return ReflectionClass<T>
		 * @throws \ReflectionException If the class does not exist.
		 */
		protected function getReflectionClass(object|string $class): ReflectionClass {
			$className = $this->normalizeClassName($class);
			
			if (!isset($this->reflection_classes[$className])) {
				$this->reflection_classes[$className] = new ReflectionClass($className);
			}
			
			/** @var ReflectionClass<T> */
			return $this->reflection_classes[$className];
		}

		/**
		 * Converts a ReflectionType to its string representation.
		 * Handles union types (A|B), intersection types (A&B), and named types.
		 * Does not include nullability indicators - caller must check allowsNull() separately.
		 * @param \ReflectionType $type The type to convert.
		 * @return string The string representation of the type, empty string if unknown type.
		 */
		protected function reflectionTypeToString(\ReflectionType $type): string {
			// Handle union types (e.g., string|int|null)
			if ($type instanceof \ReflectionUnionType) {
				return implode("|", array_map(
					fn(\ReflectionType $t) => $this->reflectionTypeToString($t),
					$type->getTypes()
				));
			}
			
			// Handle intersection types (e.g., Countable&Traversable)
			if ($type instanceof \ReflectionIntersectionType) {
				return implode("&", array_map(
					fn(\ReflectionType $t) => $this->reflectionTypeToString($t),
					$type->getTypes()
				));
			}
			
			// Handle simple named types (e.g., string, int, MyClass)
			if ($type instanceof \ReflectionNamedType) {
				return $type->getName();
			}
			
			// Fallback for unknown/future type classes
			return "";
		}
	}