<?php
	
	namespace Quellabs\ObjectQuel\Validation;
	
	class Validation {
		
		/**
		 * Validate the data
		 * @param array<string, mixed> $input
		 * @param array<string, ValidationInterface|array<ValidationInterface>> $rules
		 * @param array<string, string|array<string>|null> $errors
		 * @return void
		 */
		public function validate(array $input, array $rules, array &$errors): void {
			foreach ($rules as $key => $value) {
				if (is_array($value)) {
					foreach ($value as $v) {
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
		
		/**
		 * Takes an error string and replaces variables within with the values stored in $variables
		 * @template T of string|array
		 * @param T $string
		 * @param array<string, mixed> $variables
		 * @return (T is array<string> ? array<string> : string)|null
		 */
		protected function replaceVariablesInErrorString(string|array $string, array $variables): string|array|null {
			$pattern = '/{{\s{1}([a-zA-Z_]\w*)\s{1}}}/';
			
			return preg_replace_callback(
				$pattern,
				function (array $matches) use ($variables): string {
					return (string) ($variables[$matches[1]] ?? '');
				},
				$string
			);
		}
	}