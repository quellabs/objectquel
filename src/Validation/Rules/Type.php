<?php
	
	namespace Quellabs\ObjectQuel\Validation\Rules;
	
	use Quellabs\ObjectQuel\Validation\ValidationInterface;
	
	class Type implements ValidationInterface {
		
		/** @var array<string, mixed> */
		protected array $conditions;
		
		/** @var string|null Custom error message */
		protected ?string $errorMessage;
		
		/** @var string Internal error message */
		protected string $error = "";
		
		/**
		 * Maps deprecated/alias PHP type names to their canonical is_*() function names.
		 * 'long', 'double', and 'real' are aliases removed/deprecated in PHP 8.0+.
		 * 'numeric' maps to is_numeric(), which isn't strictly a type but is valid here.
		 * @var array<string, string>
		 */
		protected array $typeAliases = [
			'long'    => 'int',
			'double'  => 'float',
			'real'    => 'float',
			'boolean' => 'bool',
			'integer' => 'int',
		];
		
		/**
		 * Types validated via is_*() functions.
		 * These map directly to PHP's built-in type checking functions.
		 * @var string[]
		 */
		protected array $is_a_types = [
			'bool',
			'int',
			'float',
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
		
		/**
		 * Types validated via ctype_*() functions, mapped to their error messages.
		 * These operate on string representations and check character composition.
		 * Note: ctype_*() functions return false on empty strings in PHP 8+.
		 * @var array<string, string>
		 */
		protected array $ctype_types = [
			'alnum'  => 'This value should contain only alphanumeric characters.',
			'alpha'  => 'This value should contain only alphabetic characters.',
			'cntrl'  => 'This value should contain only control characters.',
			'digit'  => 'This value should contain only digits.',
			'graph'  => 'This value should contain only printable characters, excluding spaces.',
			'lower'  => 'This value should contain only lowercase letters.',
			'print'  => 'This value should contain only printable characters, including spaces.',
			'punct'  => 'This value should contain only punctuation characters.',
			'space'  => 'This value should contain only whitespace characters.',
			'upper'  => 'This value should contain only uppercase letters.',
			'xdigit' => 'This value should contain only hexadecimal digits.',
		];
		
		/**
		 * Constructor.
		 * @param array<string, mixed> $conditions Validation conditions, expects a 'type' key with the target type name
		 * @param string|null $errorMessage Optional custom error message that overrides all generated ones
		 */
		public function __construct(array $conditions = [], ?string $errorMessage = null) {
			$this->conditions = $conditions;
			$this->errorMessage = $errorMessage;
		}
		
		/**
		 * Returns the conditions used in this rule.
		 * @return array<string, mixed>
		 */
		public function getConditions(): array {
			return $this->conditions;
		}
		
		/**
		 * Validates that $value matches the configured type.
		 *
		 * Empty strings are skipped — let a Required rule handle presence checks.
		 * Null is not considered empty here, as it carries type significance.
		 * Unknown type names fail explicitly rather than passing silently.
		 *
		 * @param mixed $value The value to validate
		 * @return bool True if valid or no type constraint is set, false otherwise
		 */
		public function validate(mixed $value): bool {
			// Skip validation for absent string values; use a Required rule for presence checks.
			if ($value === null || $value === '') {
				return true;
			}
			
			// Nothing to validate without a type constraint
			if (!isset($this->conditions['type'])) {
				return true;
			}
			
			// Normalize deprecated/alias type names (e.g. 'long' -> 'int', 'boolean' -> 'bool')
			$type = $this->typeAliases[$this->conditions['type']] ?? $this->conditions['type'];
			
			// Validate types that can be checked through is_*() functions
			if (in_array($type, $this->is_a_types, strict: true)) {
				return $this->validateIsType($type, $value);
			}
			
			// Validate types that can be checked through ctype_*() functions
			if (array_key_exists($type, $this->ctype_types)) {
				return $this->validateCtypeType($type, $value);
			}
			
			// Unknown type — fail loudly rather than silently passing
			$this->error = "Unknown type constraint '{$type}'.";
			return false;
		}
		
		/**
		 * Validates $value using the appropriate is_*() function.
		 *
		 * All types in $is_a_types have a corresponding PHP built-in is_*() function,
		 * so the function_exists() guard is omitted intentionally — if it's missing,
		 * a fatal error is preferable to a silent pass.
		 *
		 * @param string $type The canonical type name (e.g. 'int', 'float', 'string')
		 * @param mixed $value The value to check
		 * @return bool True if the value matches the type, false otherwise
		 */
		protected function validateIsType(string $type, mixed $value): bool {
			$function = "is_{$type}";
			
			// Guard against missing is_*() functions; also narrows type to callable for static analysis
			if (!function_exists($function)) {
				$this->error = "Unknown type constraint '{$type}'.";
				return false;
			}
			
			// Call the resolved is_*() function to check the value's type
			if (!$function($value)) {
				$this->error = "This value should be of type {$type}.";
				return false;
			}
			
			return true;
		}
		
		/**
		 * Validates $value using the appropriate ctype_*() function.
		 *
		 * ctype_*() only operates on strings in PHP 8+; non-string values are rejected
		 * immediately without calling the function. An explicit is_string() guard makes
		 * this contract clear rather than relying on ctype's implicit rejection.
		 *
		 * @param string $type The ctype variant to check (e.g. 'digit', 'alpha', 'xdigit')
		 * @param mixed $value The value to check; must be a string to pass
		 * @return bool True if $value is a string and passes the ctype check, false otherwise
		 */
		protected function validateCtypeType(string $type, mixed $value): bool {
			$function = "ctype_{$type}";
			
			// Guard against missing ctype_*() functions; also narrows type to callable for static analysis
			if (!function_exists($function)) {
				$this->error = "Unknown type constraint '{$type}'.";
				return false;
			}
			
			// ctype_*() only operates on strings; reject non-strings immediately
			if (!is_string($value) || !$function($value)) {
				$this->error = $this->ctype_types[$type];
				return false;
			}
			
			return true;
		}
		
		/**
		 * Returns the error message from the last failed validation.
		 *
		 * If a custom message was provided at construction, it always takes precedence
		 * over any generated message, regardless of which type check failed.
		 *
		 * @return string The custom error message if set, otherwise the generated one
		 */
		public function getError(): string {
			return $this->errorMessage ?? $this->error;
		}
	}