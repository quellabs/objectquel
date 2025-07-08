<?php
	
	namespace Quellabs\ObjectQuel\Validation\Rules;
	
	use Quellabs\ObjectQuel\Validation\ValidationInterface;
	
	class NotBlank implements ValidationInterface {
		
		protected $conditions;
		
		/**
		 * Email constructor
		 * @param array $conditions
		 */
		public function __construct(array $conditions = []) {
			$this->conditions = $conditions;
		}
		
		/**
		 * Returns the conditions used in this Rule
		 * @return array
		 */
		public function getConditions() : array {
			return $this->conditions;
		}
		
		public function validate($value): bool {
			return strlen(trim($value)) > 0;
		}
		
		public function getError(): string {
			if (!isset($this->conditions["message"])) {
				return "This value should not be blank";
			}
			
			return $this->conditions["message"];
		}
		
	}