<?php
	
	namespace Quellabs\ObjectQuel\DatabaseAdapter;
	
	/**
	 * TypeMapper static utility class
	 */
	class TypeMapper {
		
		/**
		 * Default limits for column types
		 * @var array
		 */
		private static array $defaultLimits = [
			// Integer types
			'tinyinteger'  => 4,
			'smallinteger' => 6,
			'integer'      => 11,
			'biginteger'   => 20,
			
			// String types
			'string'       => 255,
			'char'         => 255,
			'binary'       => 255,
		];
		
		/**
		 * Prevent instantiation
		 */
		private function __construct() {
		}
		
		/**
		 * Get the default limit for a column type
		 * @param string $type Column type
		 * @return int|array|null The default limit (null if not applicable)
		 */
		public static function getDefaultLimit(string $type): int|array|null {
			return self::$defaultLimits[$type] ?? null;
		}
		
		/**
		 * Convert a Phinx column type to a corresponding PHP type
		 * @param string $phinxType The Phinx column type
		 * @return string The corresponding PHP type
		 */
		public static function phinxTypeToPhpType(string $phinxType): string {
			$typeMap = [
				// Integer types
				'tinyinteger'  => 'int',
				'smallinteger' => 'int',
				'integer'      => 'int',
				'biginteger'   => 'int', // Could be 'string' for very large numbers
				
				// String types
				'string'       => 'string',
				'char'         => 'string',
				'text'         => 'string',
				
				// Float/decimal types
				'float'        => 'float',
				'decimal'      => 'float', // Could also be string for precision
				
				// Boolean type
				'boolean'      => 'bool',
				
				// Date and time types
				'date'         => '\DateTime',
				'datetime'     => '\DateTime',
				'time'         => '\DateTime',
				'timestamp'    => '\DateTime',
				
				// Binary type
				'binary'       => 'string',
				'blob'         => 'string',
				
				// JSON type
				'json'         => 'array',  // Assuming JSON is decoded to array
				'jsonb'        => 'array',  // PostgreSQL JSON type
				
				// Other types
				'enum'         => 'string',
				'set'          => 'array',
				'uuid'         => 'string',
				'year'         => 'int'
			];
			
			return $typeMap[$phinxType] ?? 'mixed';
		}
		
		/**
		 * Converts a Phinx database column type to its corresponding JavaScript/TypeScript type.
		 * This method first converts the Phinx type to a PHP type, then maps that PHP type
		 * to the appropriate JavaScript equivalent for use in frontend code or API documentation.
		 * @param string $phinxType The Phinx column type (e.g., 'integer', 'text', 'datetime')
		 * @return string The corresponding JavaScript type (e.g., 'number', 'string', 'Date')
		 */
		public static function phinxTypeToJsType(string $phinxType): string {
			// First convert Phinx type to PHP type using existing method
			$phpType = self::phinxTypeToPhpType($phinxType);
			
			// Map PHP types to their JavaScript equivalents
			$typeMap = [
				'int'               => 'number',        // PHP integers become JS numbers
				'float'             => 'number',        // PHP floats become JS numbers
				'bool'              => 'boolean',       // PHP booleans become JS booleans
				'string'            => 'string',        // PHP strings remain strings
				'array'             => 'array',         // PHP arrays become JS arrays
				'DateTime'          => 'Date',          // PHP DateTime objects become JS Date objects
				'DateTimeImmutable' => 'Date'           // PHP DateTimeImmutable objects become JS Date objects
			];
			
			// Return the mapped JavaScript type, defaulting to 'string' for unknown types
			return $typeMap[$phpType] ?? 'string';
		}
		
		/**
		 * Get relevant properties for column comparison based on type
		 * @param string $type
		 * @return array
		 */
		public static function getRelevantProperties(string $type): array {
			// Base properties all columns have
			$baseProperties = ['type', 'null', 'default'];
			
			// Type-specific properties
			$typeProperties = [
				// Integer types (universally supported)
				'tinyinteger'  => ['limit', 'unsigned', 'identity'],
				'smallinteger' => ['limit', 'unsigned', 'identity'],
				'integer'      => ['limit', 'unsigned', 'identity'],
				'biginteger'   => ['limit', 'unsigned', 'identity'],
				
				// String types (universally supported)
				'string'       => ['limit'],
				'char'         => ['limit'],
				'text'         => [],
				
				// Float/decimal types (universally supported)
				'float'        => ['precision', 'unsigned'],
				'decimal'      => ['precision', 'scale', 'unsigned'],
				
				// Boolean type (universally supported)
				'boolean'      => [],
				
				// Date and time types (universally supported)
				'date'         => [],
				'datetime'     => ['precision'],
				'time'         => ['precision'],
				'timestamp'    => ['precision', 'update'],
				
				// Binary type (universally supported)
				'binary'       => ['limit'],
				'blob'         => [],
				
				// Common extension types
				'json'         => [],
				'enum'         => ['values'],
			];
			
			return array_merge($baseProperties, $typeProperties[$type] ?? []);
		}
		
		/**
		 * Format a value for inclusion in PHP code
		 * @param mixed $value The value to format
		 * @return string Formatted value
		 */
		public static function formatValue(mixed $value): string {
			if (is_null($value)) {
				return 'null';
			}
			
			if (is_bool($value)) {
				return $value ? 'true' : 'false';
			}
			
			if (is_int($value) || is_float($value)) {
				return (string)$value;
			}
			
			return "'" . addslashes($value) . "'";
		}
	}