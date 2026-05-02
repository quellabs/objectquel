<?php
	
	namespace Quellabs\ObjectQuel\Validation\Rules;
	
	use Quellabs\ObjectQuel\Validation\ValidationInterface;
	
	class RegExp implements ValidationInterface {
		
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
			if (($value === "") || is_null($value) || empty($this->conditions["regexp"])) {
				return true;
			}
			
			return preg_match($this->conditions["regexp"], $value) !== false;
		}
		
		public function getError(): string {
			if (!empty($this->errorMessage)) {
				return $this->errorMessage;
			}
			
			return "Regular expression did not match.";
		}
	}