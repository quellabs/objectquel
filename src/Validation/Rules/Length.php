<?php
	
	namespace Quellabs\ObjectQuel\Validation\Rules;
	
	use Quellabs\ObjectQuel\Validation\ValidationInterface;
	
	class Length implements ValidationInterface {
		
		/** @var array{min?: int, max?: int} */
		protected array $conditions;
		protected string $error;
		protected ?string $errorMessage;
		
		/**
		 * Email constructor
		 * @param array{min?: int, max?: int} $conditions
		 * @param string|null $errorMessage
		 */
		public function __construct(array $conditions = [], ?string $errorMessage = null) {
			$this->conditions = $conditions;
			$this->errorMessage = $errorMessage;
			$this->error = "";
		}
		
		/**
		 * Returns the conditions used in this Rule
		 * @return array{min?: int, max?: int}
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
			$min = $this->conditions['min'] ?? null;
			$max = $this->conditions['max'] ?? null;
			
			// Fail when the value is shorter than the configured minimum.
			if (is_int($min) && $length < $min) {
				$this->error = "This value is too short. It should have {{ min }} characters or more.";
				return false;
			}
			
			// Fail when the value exceeds the configured maximum.
			if (is_int($max) && $length > $max) {
				$this->error = "This value is too long. It should have {{ max }} characters or less.";
				return false;
			}
			
			return true;
		}
		
		public function getError(): string {
			if (!empty($this->errorMessage)) {
				return $this->errorMessage;
			}
			
			return $this->error;
		}
	}