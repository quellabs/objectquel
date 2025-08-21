<?php
	
	namespace Quellabs\ObjectQuel\ReflectionManagement;
	
	/**
	 * Abstract base class that provides enumeration functionality for PHP.
	 * This class uses reflection to provide enum-like behavior by working with
	 * class constants defined in child classes.
	 * @url https://stackoverflow.com/questions/254514/php-and-enumerations
	 */
	abstract class BasicEnum {

		/**
		 * Cache for storing reflection constants to avoid repeated reflection calls
		 * Static property shared across all child classes
		 * @var array|null
		 */
		private static ?array $constCache = null;
		
		/**
		 * Returns all the constants defined in the calling enum class
		 * @return array|null Array of constant name => value pairs, or null if none found
		 */
		public static function getConstants(): ?array {
			// Check if constants are already cached to avoid repeated reflection
			if (self::$constCache === null) {
				// Use late static binding to get the actual calling class (not BasicEnum)
				$reflect = new \ReflectionClass(get_called_class());
				
				// Cache all constants for future use
				self::$constCache = $reflect->getConstants();
			}
			
			return self::$constCache;
		}
		
		/**
		 * Converts an enum constant name to its corresponding value
		 * @param string $key The constant name to look up
		 * @param bool $strict Whether to perform case-sensitive matching (default: false)
		 * @return bool|int|string The constant value if found, false if not found
		 */
		public static function toValue(string $key, bool $strict = false): bool|int|string {
			$constants = self::getConstants();
			
			// Case-sensitive lookup: return value if key exists, false otherwise
			if ($strict) {
				return array_key_exists($key, $constants) ? $constants[$key] : false;
			}
			
			// Case-insensitive lookup: iterate through all constants
			foreach ($constants as $k => $v) {
				if (strcasecmp($key, $k) == 0) {
					return $v;
				}
			}
			
			// Return false if no matching key found
			return false;
		}
		
		/**
		 * Performs reverse lookup to find the constant name for a given value.
		 * Note: If multiple constants have the same value, this will return
		 * the first match found by array_search().
		 * @param mixed $value The enum value to convert to a constant name
		 * @return bool|int|string The constant name if found, false if not found
		 */
		public static function toString(mixed $value): bool|int|string {
			// Use array_search to find the key for the given value
			// Returns the key if found, false if not found
			return array_search($value, self::getConstants());
		}
	}