<?php
	
	namespace Quellabs\ObjectQuel\Validation\Rules;
	
	use Quellabs\ObjectQuel\Validation\ValidationInterface;
	
	class RegExp implements ValidationInterface {
		
		/** @var array<string, mixed> */
		protected array $conditions;
		
		/** @var string|null The regular expression pattern */
		protected ?string $regexp;
		
		/** @var string|null Custom error message */
		protected ?string $errorMessage;
		
		/**
		 * RegExp constructor
		 * @param array<string, mixed> $conditions
		 * @param string|null $errorMessage
		 */
		public function __construct(array $conditions = [], ?string $errorMessage = null) {
			$regexp = $conditions['regexp'] ?? null;
			
			if ($regexp !== null && !is_string($regexp)) {
				throw new \InvalidArgumentException("RegExp: 'regexp' must be a string or null.");
			}
			
			$this->conditions = $conditions;
			$this->regexp = $regexp;
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
			if ($value === '' || $value === null || $this->regexp === null) {
				return true;
			}
			
			// Only strings can be validated against a regular expression.
			if (!is_string($value)) {
				return false;
			}
			
			// Return true only when the pattern successfully matches.
			return preg_match($this->regexp, $value) === 1;
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