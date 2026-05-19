<?php
	
	namespace Quellabs\ObjectQuel\Validation;
	
	class Validation {
		
		/**
		 * Validates input data against the provided validation rules.
		 * @param array<string, mixed> $input Input data to validate
		 * @param array<string, ValidationInterface|array<ValidationInterface>> $rules Validation rules keyed by input field
		 * @param array<string, string|array<string>> $errors Validation errors keyed by input field
		 * @return void
		 */
		public function validate(array $input, array $rules, array &$errors): void {
			foreach ($rules as $key => $validators) {
				// Normalize validators so we can always iterate over an array
				$validators = is_array($validators) ? $validators : [$validators];
				
				// Missing input values are treated as null
				$value = $input[$key] ?? null;
				
				// Run all validator
				foreach ($validators as $validator) {
					// Continue when validation succeeds
					if ($validator->validate($value)) {
						continue;
					}
					
					// Store the formatted error message for the failed validator
					$errors[$key] = $this->replaceVariablesInErrorString(
						$validator->getError(),
						array_merge(
							$validator->getConditions(),
							[
								'key'   => $key,
								'value' => $value,
							]
						)
					);
					
					// Stop at the first validation error for this field
					break;
				}
			}
		}
		
		/**
		 * Replaces template variables inside an error string or array of strings.
		 * @param string|array<string> $string Error string or array of error strings
		 * @param array<string, mixed> $variables Variables available for replacement
		 * @return string|array<string>
		 */
		protected function replaceVariablesInErrorString(string|array $string, array $variables): string|array {
			// Replaces {{ variable }} placeholders inside a single string
			$replace = function (string $text) use ($variables): string {
				$result = preg_replace_callback(
					'/{{\s*([a-zA-Z_]\w*)\s*}}/',
					function (array $matches) use ($variables): string {
						// Use empty string when the variable is not available
						$value = $variables[$matches[1]] ?? '';
						
						// Only scalar values can be safely converted to strings
						return is_scalar($value) ? (string)$value : '';
					},
					$text
				);
				
				// preg_replace_callback may return null on failure
				return $result ?? $text;
			};
			
			// Apply replacement to each string when input is an array
			if (is_array($string)) {
				return array_map($replace, $string);
			}
			
			// Apply replacement directly when input is a single string
			return $replace($string);
		}
	}