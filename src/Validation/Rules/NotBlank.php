<?php
	
	namespace Quellabs\ObjectQuel\Validation\Rules;
	
	use Quellabs\ObjectQuel\Validation\ValidationInterface;
	
	class NotBlank implements ValidationInterface {
		
		protected array $conditions;
		protected ?string $errorMessage;
		
		/**
		 * Email constructor
		 * @param array $conditions
		 * @param string|null $errorMessage
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
			return strlen(trim($value)) > 0;
		}
		
		public function getError(): string {
			if (!empty($this->errorMessage)) {
				return $this->errorMessage;
			}
			
			return "This value should not be blank";
		}
	}