<?php
	
	namespace Quellabs\ObjectQuel\Validation\Rules;
	
	use Quellabs\ObjectQuel\Validation\ValidationInterface;
	
	class ValueIn implements ValidationInterface {
		
		/** @var array<string, mixed> */
		protected array $conditions;
		
		/** @var string|null Error to show */
		protected ?string $errorMessage;
		
		/**
		 * ValueIn constructor
		 * @param array<string, mixed> $conditions
		 * @param string|null $errorMessage
		 */
		public function __construct(array $conditions=[], ?string $errorMessage = null) {
			$this->conditions = $conditions;
			$this->errorMessage = $errorMessage;
		}
		
		/**
		 * Returns the conditions used in this Rule
		 * @return array<string, mixed>
		 */
		public function getConditions(): array {
			return $this->conditions;
		}
		
		public function validate(mixed $value): bool {
			if (empty($this->conditions) || ($value == "") || ($value == null)) {
				return true;
			}
			
			return in_array($value, $this->conditions, true);
		}
		
		public function getError(): string {
			if ($this->errorMessage !== null) {
				return $this->errorMessage;
			}
			
			$allowedValues = implode(
				',',
				array_map(fn($e) => "'{$e}'", $this->conditions)
			);
			
			return "Value should be any of these: {$allowedValues}";
		}
	}