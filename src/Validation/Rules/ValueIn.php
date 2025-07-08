<?php
	
	namespace Quellabs\ObjectQuel\Validation\Rules;
	
	use Quellabs\ObjectQuel\Validation\ValidationInterface;
	
	class ValueIn implements ValidationInterface {
		
		protected mixed $conditions;
		
		/**
		 * Email constructor
		 * @param array $conditions
		 */
		public function __construct(array $conditions = []) {
			$this->conditions = $conditions["values"] ?? [];
		}
		
		/**
		 * Returns the conditions used in this Rule
		 * @return array
		 */
		public function getConditions() : array {
			return $this->conditions;
		}
		
		public function validate($value): bool {
			if (empty($this->conditions["values"]) || ($value == "") || ($value == null)) {
				return true;
			}
			
			return in_array($value, $this->conditions);
		}
		
		public function getError(): string {
			if (!isset($this->conditions["message"])) {
				return "Value should be any of these: " . implode(",", array_map(function($e) { return "'{$e}'"; }, $this->conditions["values"]));
			}

			return $this->conditions["message"];
		}
		
	}