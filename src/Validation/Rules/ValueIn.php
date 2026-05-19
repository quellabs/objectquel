<?php
	
	namespace Quellabs\ObjectQuel\Validation\Rules;
	
	use Quellabs\ObjectQuel\Validation\ValidationInterface;
	
	/**
	 * Validation rule that checks whether a value exists
	 * in a predefined list of allowed values.
	 */
	class ValueIn implements ValidationInterface {
		
		/**
		 * List of allowed values.
		 * @var array<mixed>
		 */
		protected array $conditions;
		
		/**
		 * Custom error message to return when validation fails.
		 * When null, a default message is generated.
		 * @var string|null
		 */
		protected ?string $errorMessage;
		
		/**
		 * ValueIn constructor
		 * @param array<mixed> $conditions Allowed values to validate against
		 * @param string|null $errorMessage Optional custom validation error message
		 */
		public function __construct(array $conditions = [], ?string $errorMessage = null) {
			$this->conditions = $conditions;
			$this->errorMessage = $errorMessage;
		}
		
		/**
		 * Returns the allowed values configured for this rule.
		 * @return array<mixed>
		 */
		public function getConditions(): array {
			return $this->conditions;
		}
		
		/**
		 * Validates whether the given value is part of the allowed values list.
		 * @param mixed $value Value to validate
		 * @return bool True when validation succeeds
		 */
		public function validate(mixed $value): bool {
			// Skip validation when no conditions are defined
			// or when the value is considered intentionally empty.
			if (empty($this->conditions) || $value === '' || $value === null) {
				return true;
			}
			
			// Use strict comparison to prevent type juggling.
			return in_array($value, $this->conditions, true);
		}
		
		/**
		 * Returns the validation error message.
		 * @return string
		 */
		public function getError(): string {
			// Return custom error message when available.
			if ($this->errorMessage !== null) {
				return $this->errorMessage;
			}
			
			// Convert scalar allowed values into a readable list.
			// Non-scalar values are ignored in the message output.
			$allowedValues = implode(
				', ',
				array_map(
					fn($value) => "'" . (string)$value . "'",
					array_filter($this->conditions, 'is_scalar')
				)
			);
			
			return "Value should be any of these: {$allowedValues}";
		}
	}