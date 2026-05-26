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
		 * @var array<string, int>
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
		 * Get the default limit for a column type
		 * @param string $type Column type
		 * @return int|null The default limit (null if not applicable)
		 */
		public static function getDefaultLimit(string $type): int|null {
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
				
				// JSON type — 'json' is the ORM canonical type; the migration layer
				// translates it to 'jsonb' on PostgreSQL. 'jsonb' never appears here.
				'json'         => 'array',
				
				// Other types
				'enum'         => 'string',
				'set'          => 'array',
				'uuid'         => 'string',
				'year'         => 'int'
			];
			
			return $typeMap[$phinxType] ?? 'mixed';
		}
		
		/**
		 * Get relevant properties for column comparison based on type
		 * @param string $type
		 * @return string[]
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
			
			// Unknown types get no extra properties beyond the base set
			return array_merge($baseProperties, $typeProperties[$type] ?? []);
		}
		
		/**
		 * Format a value for inclusion in PHP code.
		 * Unsupported values return an empty string.
		 * @param mixed $value The value to format
		 * @return string Formatted value
		 */
		public static function formatValue(mixed $value): string {
			if ($value === null) {
				return 'null';
			}
			
			if (is_bool($value)) {
				return $value ? 'true' : 'false';
			}
			
			if (is_int($value) || is_float($value)) {
				return (string)$value;
			}
			
			// Strings are single-quoted with internal quotes escaped
			if (is_string($value) || $value instanceof \Stringable) {
				return var_export((string)$value, true);
			}
			
			// Unsupported values produce empty string
			return '';
		}
		
		/**
		 * Extracts all enum cases from a Column annotation's enum type.
		 * @param string|null $enumType
		 * @return array<int, string> Array of enum case values for backed enums, or names for unit enums
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
					fn(\UnitEnum $case): string => $case instanceof \BackedEnum ? (string)$case->value : $case->name,
					$cases
				);
			}
			
			// For unit enums, extract the case names instead
			return array_map(fn(\UnitEnum $case): string => $case->name, $cases);
		}
	}