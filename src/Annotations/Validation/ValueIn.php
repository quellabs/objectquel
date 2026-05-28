<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Validation;
	
	/**
	 * @Annotation
	 */
	class ValueIn implements PropertyValidationInterface {
		
		/** @var array<string, mixed> */
		protected array $parameters;
		
		/** @var string The property to check */
		protected string $property;
		
		/** @var array<mixed>|null The set of allowed values */
		protected ?array $values;
		
		/** @var string|null The error message to show if check failed */
		protected ?string $message;
		
		/**
		 * ValueIn constructor.
		 * @param array<string, mixed> $parameters
		 */
		public function __construct(array $parameters) {
			$property = $parameters['property'] ?? '';
			$values = $parameters['values'] ?? null;
			$message = $parameters['message'] ?? null;
			
			if (!is_string($property)) {
				throw new \InvalidArgumentException("ValueIn: 'property' must be a string.");
			}
			
			if ($values !== null && !is_array($values)) {
				throw new \InvalidArgumentException("ValueIn: 'values' must be an array or null.");
			}
			
			if ($message !== null && !is_string($message)) {
				throw new \InvalidArgumentException("ValueIn: 'message' must be a string or null.");
			}
			
			$this->parameters = $parameters;
			$this->property = $property;
			$this->values = $values;
			$this->message = $message;
		}
		
		/**
		 * Returns all parameters
		 * @return array<string, mixed>
		 */
		public function getParameters(): array {
			return $this->parameters;
		}
		
		/**
		 * Returns true if the 'property' field is populated, false if not
		 * @return bool
		 */
		public function hasProperty(): bool {
			return $this->property !== '';
		}
		
		/**
		 * Returns the value of 'property'
		 * @return string
		 */
		public function getProperty(): string {
			return $this->property;
		}
		
		/**
		 * Returns the set of allowed values
		 * @return array<mixed>|null
		 */
		public function getValues(): ?array {
			return $this->values;
		}
		
		/**
		 * Returns the message when the validation fails, null if there's no message configured
		 * @return string|null
		 */
		public function getMessage(): ?string {
			return $this->message;
		}
	}