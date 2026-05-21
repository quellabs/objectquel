<?php
	
	namespace Quellabs\ObjectQuel\Validation\Rules;
	
	use Quellabs\ObjectQuel\Validation\ValidationInterface;
	
	class Length implements ValidationInterface {
		
		/** @var array<string, mixed> */
		protected array $conditions;
		
		/** @var int|null Minimum allowed length */
		protected ?int $min;
		
		/** @var int|null Maximum allowed length */
		protected ?int $max;
		
		/** @var string|null Custom error message */
		protected ?string $errorMessage;
		
		/** @var string Internal error message set during validation */
		protected string $error;
		
		/**
		 * Length constructor
		 * @param array<string, mixed> $conditions
		 * @param string|null $errorMessage
		 */
		public function __construct(array $conditions = [], ?string $errorMessage = null) {
			$min = $conditions['min'] ?? null;
			$max = $conditions['max'] ?? null;
			
			if ($min !== null && !is_int($min)) {
				throw new \InvalidArgumentException("Length: 'min' must be an integer or null.");
			}
			
			if ($max !== null && !is_int($max)) {
				throw new \InvalidArgumentException("Length: 'max' must be an integer or null.");
			}
			
			$this->conditions = $conditions;
			$this->min = $min;
			$this->max = $max;
			$this->errorMessage = $errorMessage;
			$this->error = "";
		}
		
		/**
		 * Returns the conditions used in this Rule
		 * @return array<string, mixed>
		 */
		public function getConditions(): array {
			return $this->conditions;
		}
		
		/**
		 * Validates that the given value satisfies configured length constraints.
		 * @param mixed $value The value to validate.
		 * @return bool True when valid, false otherwise.
		 */
		public function validate(mixed $value): bool {
			// Allow empty values; required validation belongs elsewhere.
			if ($value === '' || $value === null) {
				return true;
			}
			
			// Only scalar values can be measured for string length.
			// Non-scalar values are treated as zero-length.
			$length = is_scalar($value) ? strlen((string)$value) : 0;
			
			// Fail when the value is shorter than the configured minimum.
			if ($this->min !== null && $length < $this->min) {
				$this->error = "This value is too short. It should have {{ min }} characters or more.";
				return false;
			}
			
			// Fail when the value exceeds the configured maximum.
			if ($this->max !== null && $length > $this->max) {
				$this->error = "This value is too long. It should have {{ max }} characters or less.";
				return false;
			}
			
			return true;
		}
		
		/**
		 * Returns the error message from the last failed validation.
		 * @return string
		 */
		public function getError(): string {
			if (!empty($this->errorMessage)) {
				return $this->errorMessage;
			}
			
			return $this->error;
		}
	}