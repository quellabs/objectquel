<?php
	
	namespace Quellabs\ObjectQuel\Validation\Rules;
	
	use Quellabs\ObjectQuel\Validation\ValidationInterface;
	
	class RegExp implements ValidationInterface {
		
		protected array $conditions;
		
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
			if (($value === "") || is_null($value) || empty($this->conditions["regexp"])) {
                return true;
            }
            
            return preg_match($this->conditions["regexp"], $value) !== false;
		}
		
		public function getError(): string {
			if (!isset($this->conditions["message"])) {
				return "Regular expression did not match.";
			}
			
			return $this->conditions["message"];
		}
		
	}