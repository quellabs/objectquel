<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\TypeMapper;
	use Quellabs\Support\Tools;
	
	/**
	 * @Annotation
	 * Column annotation class for ORM mapping
	 */
	class Column implements AnnotationInterface {
		
		/**
		 * Array containing all column parameters
		 * @var array<string, mixed>
		 */
		protected array $parameters;
		
		private string $name;
		private string $type;
		private bool $primaryKey;
		private bool $unsigned;
		private bool $nullable;
		private ?int $precision;
		private ?int $scale;
		private ?string $enumType;
		
		/**
		 * Column constructor
		 * @param array<string, mixed> $parameters Associative array of column parameters
		 * @throws \InvalidArgumentException
		 */
		public function __construct(array $parameters) {
			$name = $parameters['name'] ?? null;
			$type = $parameters['type'] ?? null;
			$primaryKey = $parameters['primary_key'] ?? false;
			$unsigned = $parameters['unsigned'] ?? false;
			$nullable = $parameters['nullable'] ?? false;
			$precision = $parameters['precision'] ?? null;
			$scale = $parameters['scale'] ?? null;
			$enumType = $parameters['enumType'] ?? null;
			
			if (!is_string($name)) {
				throw new \InvalidArgumentException(
					'Column annotation requires a "name" parameter'
				);
			}
			
			if (!is_string($type)) {
				throw new \InvalidArgumentException(
					'Column annotation requires a "type" parameter'
				);
			}
			
			if ($enumType !== null && !is_string($enumType)) {
				throw new \InvalidArgumentException(
					'Column annotation "enumType" must be a string or null'
				);
			}
			
			$this->parameters = $parameters;
			$this->name = $name;
			$this->type = $type;
			$this->primaryKey = is_bool($primaryKey) && $primaryKey;
			$this->unsigned = is_bool($unsigned) && $unsigned;
			$this->nullable = is_bool($nullable) && $nullable;
			$this->precision = is_int($precision) ? $precision : null;
			$this->scale = is_int($scale) ? $scale : null;
			$this->enumType = $enumType;
		}
		
		/**
		 * Returns all parameters for this column annotation
		 * @return array<string, mixed> The complete parameters array
		 */
		public function getParameters(): array {
			return $this->parameters;
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
			// Calculate the length if the type is 'enum'
			if ($this->getType() === 'enum') {
				$enumType = $this->getEnumType() ?? throw new \LogicException('Enum column must specify enumType');
				return max(Tools::getMaxEnumValueLength($enumType), 32);
			}
			
			// Check if the limit parameter exists or is empty
			if (empty($this->parameters["limit"])) {
				// Return null if no limit is defined
				return null;
			}
			
			// Validate that the limit parameter contains a numeric value
			if (!is_numeric($this->parameters["limit"])) {
				// Return null if the limit is not a valid number
				return null;
			}
			
			// Cast the limit to integer and return it
			// This ensures the return type matches the method's return type declaration
			return (int)$this->parameters["limit"];
		}
		
		/**
		 * Checks if this column has a default value
		 * @return bool True if a default value is specified, false otherwise
		 */
		public function hasDefault(): bool {
			return !empty($this->parameters["default"]);
		}
		
		/**
		 * Gets the default value for this column
		 * @return mixed The default value for the column
		 */
		public function getDefault(): mixed {
			return $this->parameters["default"] ?? null;
		}
		
		/**
		 * Checks if this column is a primary key
		 * @return bool True if this column is a primary key, false otherwise
		 */
		public function isPrimaryKey(): bool {
			return $this->primaryKey;
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