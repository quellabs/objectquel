<?php
	
	namespace Quellabs\ObjectQuel\Validation\Rules;
	
	use Quellabs\ObjectQuel\Validation\ValidationInterface;
	
	class Length implements ValidationInterface {
		
		protected array $conditions;
		protected $error;
		
		/**
		 * Email constructor
		 * @param array $conditions
		 */
		public function __construct(array $conditions = []) {
			$this->conditions = $conditions;
			$this->error = "";
		}
		
		/**
		 * Returns the conditions used in this Rule
		 * @return array
		 */
		public function getConditions() : array {
			return $this->conditions;
		}
		
		public function validate($value): bool {
            if (($value === "") || is_null($value)) {
                return true;
            }
            
            if (isset($this->conditions['min'])) {
				if (strlen($value) < $this->conditions['min']) {
					$this->error = "This value is too short. It should have {{ min }} characters or more.";
					return false;
				}
			}
			
			if (isset($this->conditions['max'])) {
				if (strlen($value) > $this->conditions['max']) {
					$this->error = "This value is too long. It should have {{ max }} characters or less.";
					return false;
				}
			}
			
			return true;
		}
		
		public function getError(): string {
			if (!isset($this->conditions["message"])) {
				return $this->error;
			}
			
			return $this->conditions["message"];
		}
	}