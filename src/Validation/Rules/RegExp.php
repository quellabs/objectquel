<?php
	
	namespace Quellabs\ObjectQuel\Validation\Rules;
	
	use Quellabs\ObjectQuel\Validation\ValidationInterface;
	
	class RegExp implements ValidationInterface {
		
		/** @var array{regexp?: string} */
		protected array $conditions;
		protected ?string $errorMessage;
		
		/**
		 * RegExp constructor
		 * @param array{regexp?: string} $conditions
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
		 * Validates that the given value matches the configured regular expression.
		 * @param mixed $value The value to validate.
		 * @return bool True when the value matches the configured pattern, false otherwise.
		 */
		public function validate(mixed $value): bool {
			// Allow empty values and skip validation when no pattern is configured.
			if ($value === '' || $value === null || empty($this->conditions['regexp'])) {
				return true;
			}
			
			// Only strings can be validated against a regular expression.
			if (!is_string($value)) {
				return false;
			}
			
			// Return true only when the pattern successfully matches.
			return preg_match($this->conditions['regexp'], $value) === 1;
		}
		
		/**
		 * Return error
		 * @return string
		 */
		public function getError(): string {
			if (!empty($this->errorMessage)) {
				return $this->errorMessage;
			}
			
			return "Regular expression did not match.";
		}
	}