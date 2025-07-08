<?php
	
	namespace Quellabs\ObjectQuel\ReflectionManagement;
	
	/**
	 * Class BasicEnum
	 * @url https://stackoverflow.com/questions/254514/php-and-enumerations
	 */
	abstract class BasicEnum {
		private static $constCache = null;
		
		/**
		 * Returns all the constants in this Enum
		 * @return array|null
		 * @throws \ReflectionException
		 */
		public static function getConstants(): ?array {
			if (self::$constCache === null) {
				$reflect = new \ReflectionClass(get_called_class());
				self::$constCache = $reflect->getConstants();
			}
			
			return self::$constCache;
		}
		
		/**
		 * Returns the lowest value in this enum
		 * @return mixed
		 * @throws \ReflectionException
		 */
		public static function lowestValue() {
			$values = array_values(self::getConstants());
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
			return in_array(strtolower($name), $keys);
		}
		
		/**
		 * Returns true if the given value is present in the enum, false if not
		 * @param $value
		 * @return bool
		 * @throws \ReflectionException
		 */
		public static function isValidValue($value): bool {
			$values = array_values(self::getConstants());
			return in_array($value, $values);
		}
		
		/**
		 * Converts a key to a value
		 * @param string $key
		 * @param bool $strict
		 * @return bool|int|string
		 */
		public static function toValue(string $key, bool $strict = false): bool|int|string {
			$constants = self::getConstants();
			
			if ($strict) {
				return array_key_exists($key, $constants) ? $constants[$key] : false;
			}
			
			foreach($constants as $k => $v) {
				if (strcasecmp($key, $k) == 0) {
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
			return array_search($value, self::getConstants());
		}
	}
