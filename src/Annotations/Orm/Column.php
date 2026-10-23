<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\TypeMapper;
	
	/**
	 * @Annotation
	 * Column annotation class for ORM mapping
	 */
	#[\Attribute(\Attribute::TARGET_PROPERTY)]
	class Column implements AnnotationInterface {
		use ReflectsAnnotationParameters;
		
		private bool $primary_key;
		private mixed $limit;
		private mixed $default;
		
		
		private string $name;
		private string $type;
		private bool $unsigned;
		private bool $nullable;
		private ?int $precision;
		private ?int $scale;
		private ?string $enumType;
		
		/**
		 * Constructs the Column annotation.
		 * @param string $name
		 * @param string $type
		 * @param bool $primary_key
		 * @param bool $unsigned
		 * @param bool $nullable
		 * @param ?int $precision
		 * @param ?int $scale
		 * @param ?string $enumType
		 * @param mixed $limit
		 * @param mixed $default
		 * @return void
		 */
		public function __construct(string $name, string $type, bool $primary_key = false, bool $unsigned = false, bool $nullable = false, ?int $precision = null, ?int $scale = null, ?string $enumType = null, mixed $limit = null, mixed $default = null) {
			$this->name = $name;
			$this->type = $type;
			$this->unsigned = $unsigned;
			$this->nullable = $nullable;
			$this->precision = $precision;
			$this->scale = $scale;
			$this->enumType = $enumType;
			$this->primary_key = $primary_key;
			$this->limit = $limit;
			$this->default = $default;
		}
		
		/**
		 * Gets the column name
		 * @return string The name of the database column
		 */
		public function getName(): string {
			return $this->name;
		}
		
		/**
		 * Gets the column data type
		 * @return string The SQL data type of the column
		 */
		public function getType(): string {
			return $this->type;
		}
		
		/**
		 * Returns the enum type, or null if there is none
		 * @return string|null
		 */
		public function getEnumType(): ?string {
			return $this->enumType;
		}
		
		/**
		 * Gets the column length/size
		 * This method retrieves the defined length or size constraint for a database column
		 * (e.g., VARCHAR(255) where 255 is the limit)
		 * @return int|null The length/size of the column or null if not specified or invalid
		 */
		public function getLimit(): ?int {
			// Must match TypeMapper::enumFallbackLimit() exactly, or this
			// declared limit could never match what introspection reads
			// back on a non-native-enum engine — see that method's docblock.
			if ($this->getType() === 'enum') {
				$enumType = $this->getEnumType() ?? throw new \LogicException('Enum column must specify enumType');
				return TypeMapper::enumFallbackLimit(TypeMapper::getEnumCases($enumType));
			}
			
			// Check if the limit parameter exists or is empty
			if (empty($this->limit)) {
				// Return null if no limit is defined
				return null;
			}
			
			// Validate that the limit parameter contains a numeric value
			if (!is_numeric($this->limit)) {
				// Return null if the limit is not a valid number
				return null;
			}
			
			// Cast the limit to integer and return it
			// This ensures the return type matches the method's return type declaration
			return (int)$this->limit;
		}
		
		/**
		 * Checks if this column has a default value
		 * A declared default of 0, '0', '', or false is still a default;
		 * only the absence of a "default" parameter (or an explicit null)
		 * means there is none — see getDefault().
		 * @return bool True if a default value is specified, false otherwise
		 */
		public function hasDefault(): bool {
			return $this->default !== null;
		}
		
		/**
		 * Gets the default value for this column
		 * @return mixed The default value for the column
		 */
		public function getDefault(): mixed {
			return $this->default;
		}
		
		/**
		 * Checks if this column is a primary key
		 * @return bool True if this column is a primary key, false otherwise
		 */
		public function isPrimaryKey(): bool {
			return $this->primary_key;
		}
		
		/**
		 * Checks if this column is unsigned (for numeric types)
		 * @return bool True if this column is unsigned, false otherwise
		 */
		public function isUnsigned(): bool {
			return $this->unsigned;
		}
		
		/**
		 * Checks if this column allows NULL values
		 * @return bool True if this column allows NULL values, false otherwise
		 */
		public function isNullable(): bool {
			return $this->nullable;
		}
		
		/**
		 * Gets the precision for this column (for decimal/numeric types)
		 * @return int|null The precision value or null if not set
		 */
		public function getPrecision(): ?int {
			return $this->precision;
		}
		
		/**
		 * Gets the scale for this column (for decimal/numeric types)
		 * @return int|null The scale value or null if not set
		 */
		public function getScale(): ?int {
			return $this->scale;
		}
	}
