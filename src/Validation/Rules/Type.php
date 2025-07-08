<?php
	
	namespace Quellabs\ObjectQuel\Validation\Rules;
	
	use Quellabs\ObjectQuel\Validation\ValidationInterface;
	
	class Type implements ValidationInterface {
		
		protected $conditions;
		protected $error;
		protected $is_a_types;
		protected $ctype_types;
		
		/**
		 * Email constructor
		 * @param array $conditions
		 */
		public function __construct(array $conditions = []) {
			$this->conditions = $conditions;
			$this->error = "";
			$this->is_a_types = [
				'bool',
				'boolean',
				'int',
				'integer',
				'long',
				'float',
				'double',
				'real',
				'numeric',
				'string',
				'scalar',
				'array',
				'iterable',
				'countable',
				'callable',
				'object',
				'resource',
				'null',
			];
			$this->ctype_types = [
				'alnum',
				'alpha',
				'cntrl',
				'digit',
				'graph',
				'lower',
				'print',
				'punct',
				'space',
				'upper',
				'xdigit',
			];
		}
		
		/**
		 * Returns the conditions used in this Rule
		 * @return array
		 */
		public function getConditions() : array {
			return $this->conditions;
		}
		
		public function validate($value): bool {
			// no value
            if ($value == '') {
                return true;
            }
            
            // No type set
			if (!isset($this->conditions["type"])) {
				return true;
			}
			
			// Simple types checked by is_...
			if (in_array($this->conditions["type"], $this->is_a_types)) {
				if (!call_user_func("is_{$this->conditions["type"]}", $value)) {
					$this->error = "This value should be of type {$this->conditions["type"]}";
					return false;
				}
			}
			
			// ctype checks
			if (in_array($this->conditions["type"], $this->ctype_types)) {
				$errorMessages = [
					'alnum' => 'This value should contain only alphanumeric characters.',
					'alpha' => 'This value should contain only alphabetic characters.',
					'cntrl' => 'This value should contain only control characters.',
					'digit' => 'This value should contain only digits.',
					'graph' => 'This value should contain only printable characters, excluding spaces.',
					'lower' => 'This value should contain only lowercase letters.',
					'print' => 'This value should contain only printable characters, including spaces.',
					'punct' => 'This value should contain only punctuation characters.',
					'space' => 'This value should contain only whitespace characters.',
					'upper' => 'This value should contain only uppercase letters.',
					'xdigit' => 'This value should contain only hexadecimal digits.'
				];
				
				if (!call_user_func("ctype_{$this->conditions["type"]}", $value)) {
					$this->error = $errorMessages[$this->conditions["type"]];
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