<?php
	
	namespace Quellabs\ObjectQuel\DatabaseAdapter;
	
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Serialization\Normalizer\EnumNormalizer;
	
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
		 * Convert Phinx type to SQL CREATE TABLE type definition
		 * @param string $phinxType The Phinx column type
		 * @param Column $annotation Column annotation with additional metadata
		 * @return string SQL type definition
		 */
		public static function phinxTypeToSqlType(string $phinxType, Column $annotation): string {
			// Get limit if specified, otherwise use default
			$limit = $annotation->getLimit() ?? TypeMapper::getDefaultLimit($phinxType);
			
			return match ($phinxType) {
				// Integer types
				'tinyinteger' => 'TINYINT' . ($limit ? "({$limit})" : ''),
				'smallinteger' => 'SMALLINT' . ($limit ? "({$limit})" : ''),
				'integer' => 'INT' . ($limit ? "({$limit})" : ''),
				'biginteger' => 'BIGINT' . ($limit ? "({$limit})" : ''),
				
				// String types
				'string', 'char' => 'VARCHAR' . ($limit ? "({$limit})" : '(255)'),
				'text' => 'TEXT',
				
				// Float/decimal types
				'float' => 'FLOAT',
				'decimal' => self::buildDecimalType($annotation),
				
				// Boolean type
				'boolean' => 'BOOLEAN',
				
				// Date and time types
				'date' => 'DATE',
				'datetime' => 'DATETIME',
				'time' => 'TIME',
				'timestamp' => 'TIMESTAMP',
				
				// Binary types
				'binary' => 'VARBINARY' . ($limit ? "({$limit})" : '(255)'),
				'blob' => 'BLOB',
				
				// JSON type
				'json', 'jsonb' => 'JSON',
				
				// Enum type
				'enum' => self::buildEnumType($annotation),
				
				// Default fallback
				default => 'VARCHAR(255)'
			};
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
				'enum'         => ['limit', 'values'],
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
		
		/**
		 * Extracts all enum cases from a Column annotation's enum type.
		 * @param string|null $enumType
		 * @return array Array of enum case values for backed enums, or names for unit enums
		 */
		public static function getEnumCases(?string $enumType): array {
			// Return empty array if no enum type is defined
			if (empty($enumType)) {
				return [];
			}
			
			// Validate that the enum type exists and is actually an enum class
			// This prevents fatal errors if an invalid class name is passed
			if (!enum_exists($enumType)) {
				return [];
			}
			
			// Get all enum cases using the static cases() method
			$cases = $enumType::cases();
			
			// Return empty array if no cases exist
			if (empty($cases)) {
				return [];
			}
			
			// Check if this is a backed enum using is_subclass_of
			// BackedEnum extends UnitEnum and adds the value property
			if (is_subclass_of($enumType, \BackedEnum::class)) {
				// For backed enums, extract the scalar values
				return array_map(
					fn(\UnitEnum $case): int|string => $case instanceof \BackedEnum ? $case->value : $case->name,
					$cases
				);
			}
			
			// For unit enums, extract the case names instead
			return array_map(fn(\UnitEnum $case) => $case->name, $cases);
		}
		
		/**
		 * Build DECIMAL type with precision and scale
		 * @param Column $annotation
		 * @return string
		 */
		private static function buildDecimalType(Column $annotation): string {
			$precision = $annotation->getPrecision() ?? 10;
			$scale = $annotation->getScale() ?? 0;
			return "DECIMAL({$precision},{$scale})";
		}
		
		/**
		 * Build ENUM type with values
		 * @param Column $annotation
		 * @return string
		 */
		private static function buildEnumType(Column $annotation): string {
			$values = self::getEnumCases($annotation->getEnumType());
			
			if (empty($values)) {
				return 'VARCHAR(255)';
			}
			
			$quotedValues = array_map(fn($v) => "'{$v}'", $values);
			return 'ENUM(' . implode(',', $quotedValues) . ')';
		}
	}