<?php
	
	namespace Quellabs\ObjectQuel\Validation\Rules;
	
	use Quellabs\ObjectQuel\Validation\ValidationInterface;
	
	/**
	 * Validates that a value is a properly formatted email address.
	 */
	class Email implements ValidationInterface {
		
		/** @var array<string, mixed> Conditions controlling validation behaviour */
		protected array $conditions;
		
		/** @var string|null Custom error message, or null to use the default */
		protected ?string $errorMessage;
		
		/**
		 * Email constructor
		 * @param array<string, mixed> $conditions Conditions controlling validation behaviour
		 * @param string|null $errorMessage Custom error message, or null to use the default
		 */
		public function __construct(array $conditions = [], ?string $errorMessage = null) {
			$this->conditions = $conditions;
			$this->errorMessage = $errorMessage;
		}
		
		/**
		 * Returns the conditions used in this rule.
		 * @return array<string, mixed>
		 */
		public function getConditions(): array {
			return $this->conditions;
		}
		
		/**
		 * Validates that the given value is a properly formatted email address.
		 * @param mixed $value The value to validate
		 * @return bool        True if the value is empty, null, or a valid email address
		 */
		public function validate(mixed $value): bool {
			if (($value === "") || is_null($value)) {
				return true;
			}
			
			return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
		}
		
		/**
		 * Returns the error message to display when validation fails.
		 * @return string The validation error message
		 */
		public function getError(): string {
			if (!empty($this->errorMessage)) {
				return $this->errorMessage;
			}
			
			return "This value is not a valid email address.";
		}
	}