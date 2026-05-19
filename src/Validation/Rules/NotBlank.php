<?php
	
	namespace Quellabs\ObjectQuel\Validation\Rules;
	
	use Quellabs\ObjectQuel\Validation\ValidationInterface;
	
	class NotBlank implements ValidationInterface {
		
		/** @var array<string, mixed> */
		protected array $conditions;
		protected ?string $errorMessage;
		
		/**
		 * Email constructor
		 * @param array<string, mixed> $conditions
		 * @param string|null $errorMessage
		 */
		public function __construct(array $conditions = [], ?string $errorMessage = null) {
			$this->conditions = $conditions;
			$this->errorMessage = $errorMessage;
		}
		
		/**
		 * Returns the conditions used in this Rule
		 * @return array<string, mixed>
		 */
		public function getConditions(): array {
			return $this->conditions;
		}
		
		/**
		 * Validates that the given value is not empty after trimming whitespace.
		 * @param mixed $value The value to validate.
		 * @return bool True when the value contains non-whitespace characters, false otherwise.
		 */
		public function validate(mixed $value): bool {
			// Convert scalar values to strings and remove surrounding whitespace.
			// Non-scalar values are treated as empty.
			$normalizedValue = is_scalar($value) ? trim((string)$value) : '';
			return strlen($normalizedValue) > 0;
		}
		
		/**
		 * Returns error
		 * @return string
		 */
		public function getError(): string {
			if (!empty($this->errorMessage)) {
				return $this->errorMessage;
			}
			
			return "This value should not be blank";
		}
	}