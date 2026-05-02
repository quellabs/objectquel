<?php
	
	namespace Quellabs\ObjectQuel\Validation\Rules;
	
	use Quellabs\ObjectQuel\Validation\ValidationInterface;
	
	class Email implements ValidationInterface {
		
		protected array $conditions;
		protected ?string $errorMessage;
		
		/**
		 * Email constructor
		 * @param array $conditions
		 */
		public function __construct(array $conditions = [], ?string $errorMessage = null) {
			$this->conditions = $conditions;
			$this->errorMessage = $errorMessage;
		}
		
		/**
		 * Returns the conditions used in this Rule
		 * @return array
		 */
		public function getConditions(): array {
			return $this->conditions;
		}
		
		public function validate($value): bool {
			if (($value === "") || is_null($value)) {
				return true;
			}
			
			return filter_var($value, FILTER_VALIDATE_EMAIL);
		}
		
		public function getError(): string {
			if (!empty($this->errorMessage)) {
				return $this->errorMessage;
			}
			
			return "This value is not a valid email address.";
		}
	}