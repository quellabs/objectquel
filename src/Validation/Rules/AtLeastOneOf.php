<?php
	
	namespace Quellabs\ObjectQuel\Validation\Rules;
	
	use Quellabs\ObjectQuel\Validation\ValidationInterface;
	
	class AtLeastOneOf implements ValidationInterface {

		/** @var array<ValidationInterface> List of validation rules to evaluate */
		protected array $conditions;
		
		/** Optional custom error message returned when validation fails */
		protected ?string $message;
		
		/**
		 * Initializes the rule with a set of conditions and an optional custom error message.
		 * @param array<ValidationInterface> $conditions Validation rules to evaluate
		 * @param string|null $message Custom error message (optional)
		 */
		public function __construct(array $conditions = [], ?string $message = null) {
			$this->conditions = $conditions;
			$this->message = $message;
		}
		
		/**
		 * Returns all validation conditions associated with this rule.
		 * @return array<ValidationInterface>
		 */
		public function getConditions(): array {
			return $this->conditions;
		}
		
		/**
		 * Validates the given value against all conditions.
		 * Returns true as soon as at least one condition passes.
		 * @param mixed $value Value to validate
		 * @return bool True if at least one condition is satisfied, false otherwise
		 */
		public function validate(mixed $value): bool {
			foreach ($this->conditions as $condition) {
				if ($condition->validate($value)) {
					return true;
				}
			}
			
			return false;
		}
		
		/**
		 * Returns the validation error message.
		 * Uses the custom message if provided, otherwise falls back to a default message.
		 * @return string
		 */
		public function getError(): string {
			return $this->message ?? 'At least one of the conditions should be fulfilled.';
		}
	}