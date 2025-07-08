<?php
	
	namespace Quellabs\ObjectQuel\Validation;
	
	class Validation {
		
		/**
		 * Takes an error string and replaces variables within with the values stored in $variables
		 * @param $string
		 * @param array $variables
		 * @return array|string|string[]|null
		 */
		protected function replaceVariablesInErrorString($string, array $variables): array|string|null {
			$pattern = '/{{\s{1}([a-zA-Z_][a-zA-Z0-9_]*)\s{1}}}/';
			
			return preg_replace_callback($pattern, function($matches) use ($variables) {
				return $variables[$matches[1]] ?? "";
			}, $string);
		}
		
		/**
		 * Validate the data
		 * @param array $input
		 * @param array $rules
		 * @param array $errors
		 * @return void
		 */
		public function validate(array $input, array $rules, array &$errors): void {
			foreach($rules as $key => $value) {
				if (is_array($value)) {
					foreach($value as $v) {
						if (!$v->validate($input[$key])) {
                            $errors[$key] = $this->replaceVariablesInErrorString($v->getError(), array_merge($v->getConditions(), [
                                'key'   => $key,
                                'value' => $input[$key],
                            ]));
						}
					}
					
					continue;
				}
				
				if (!$value->validate($input[$key])) {
					$errors[$key] = $this->replaceVariablesInErrorString($value->getError(), array_merge($value->getConditions(), [
                        'key'   => $key,
                        'value' => $input[$key],
                    ]));
				}
			}
		}
	}