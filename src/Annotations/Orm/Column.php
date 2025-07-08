<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * Column annotation class for ORM mapping
	 * @package Quellabs\ObjectQuel\Annotations\Orm
	 */
	class Column implements AnnotationInterface {
		
		/**
		 * Array containing all column parameters
		 * @var array
		 */
		protected array $parameters;
		
		/**
		 * Column constructor
		 * @param array $parameters Associative array of column parameters
		 */
		public function __construct(array $parameters) {
			$this->parameters = $parameters;
		}
		
		/**
		 * Returns all parameters for this column annotation
		 * @return array The complete parameters array
		 */
		public function getParameters(): array {
			return $this->parameters;
		}
		
		/**
		 * Gets the column name
		 * @return string The name of the database column
		 */
		public function getName(): string {
			return $this->parameters["name"] ?? '';
		}
		
		/**
		 * Gets the column data type
		 * @return string The SQL data type of the column
		 */
		public function getType(): string {
			return $this->parameters["type"];
		}
		
		/**
		 * Gets the column length/size
		 * This method retrieves the defined length or size constraint for a database column
		 * (e.g., VARCHAR(255) where 255 is the limit)
		 * @return int|null The length/size of the column or null if not specified or invalid
		 */
		public function getLimit(): ?int {
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
			return $this->parameters["primary_key"] ?? false;
		}
		
		/**
		 * Checks if this column is unsigned (for numeric types)
		 * @return bool True if this column is unsigned, false otherwise
		 */
		public function isUnsigned(): bool {
			return $this->parameters["unsigned"] ?? false;
		}
		
		/**
		 * Checks if this column allows NULL values
		 * @return bool True if this column allows NULL values, false otherwise
		 */
		public function isNullable(): bool {
			return $this->parameters["nullable"] ?? false;
		}
		
		/**
		 * Gets the precision for this column (for decimal/numeric types)
		 * @return int|null The precision value or null if not set
		 */
		public function getPrecision(): ?int {
			return $this->parameters["precision"] ?? null;
		}
		
		/**
		 * Gets the scale for this column (for decimal/numeric types)
		 * @return int|null The scale value or null if not set
		 */
		public function getScale(): ?int {
			return $this->parameters["scale"] ?? null;
		}
	}