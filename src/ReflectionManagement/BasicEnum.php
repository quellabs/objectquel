<?php
	
	namespace Quellabs\ObjectQuel\ReflectionManagement;
	
	/**
	 * Abstract base class that provides enumeration functionality for PHP.
	 * This class uses reflection to provide enum-like behavior by working with
	 * class constants defined in child classes.
	 * @url https://stackoverflow.com/questions/254514/php-and-enumerations
	 */
	abstract class BasicEnum {

		/** @var array<class-string, array<string, mixed>> */
		private static array $constCache = [];
		
		/**
		 * Returns all the constants in this Enum
		 * @return array<string, mixed>
		 * @throws \ReflectionException
		 */
		public static function getConstants(): array {
			$class = get_called_class();
			
			if (!isset(self::$constCache[$class])) {
				$reflect = new \ReflectionClass($class);
				self::$constCache[$class] = $reflect->getConstants();
			}
			
			return self::$constCache[$class];
		}
		
		/**
		 * Returns the lowest value in this enum
		 * @return mixed
		 * @throws \ReflectionException
		 */
		public static function lowestValue(): mixed {
			$values = array_values(self::getConstants());
			
			if ($values === []) {
				throw new \LogicException('Enum has no constants');
			}
			
			return min($values);
		}
		
		/**
		 * Returns true if the given name is present in the enum, false if not
		 * @param string $name
		 * @param bool $strict
		 * @return bool
		 * @throws \ReflectionException
		 */
		public static function isValidName(string $name, $strict = false): bool {
			$constants = self::getConstants();
			
			if ($strict) {
				return array_key_exists($name, $constants);
			}
			
			$keys = array_map('strtolower', array_keys($constants));
			return in_array(strtolower($name), $keys, true);
		}
		
		/**
		 * Returns true if the given value is present in the enum, false if not
		 * @param mixed $value
		 * @return bool
		 * @throws \ReflectionException
		 */
		public static function isValidValue(mixed $value): bool {
			return in_array($value, self::getConstants(), true);
		}
		
		/**
		 * Converts a key to a value
		 * @param string $key
		 * @param bool $strict
		 * @return mixed
		 * @throws \ReflectionException
		 */
		public static function toValue(string $key, bool $strict = false): mixed {
			$constants = self::getConstants();
			
			if ($strict) {
				return array_key_exists($key, $constants) ? $constants[$key] : false;
			}
			
			foreach ($constants as $k => $v) {
				if (strcasecmp($key, $k) === 0) {
					return $v;
				}
			}
			
			return false;
		}
		
		/**
		 * Converts a value to a key
		 * @param mixed $value
		 * @return bool|int|string
		 * @throws \ReflectionException
		 */
		public static function toString(mixed $value): bool|int|string {
			return array_search($value, self::getConstants(), true);
		}
	}