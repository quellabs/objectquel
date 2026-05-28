<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Validation;
	
	/**
	 * @Annotation
	 */
	class Type implements PropertyValidationInterface {
		
		/** @var array<string, mixed> */
		protected array $parameters;
		
		/** @var string The property to check */
		protected string $property;
		
		/** @var string|null The expected type */
		protected ?string $type;
		
		/** @var string|null The error message to show if check failed */
		protected ?string $message;
		
		/**
		 * Type constructor.
		 * @param array<string, mixed> $parameters
		 */
		public function __construct(array $parameters) {
			$property = $parameters['property'] ?? '';
			$type = $parameters['type'] ?? null;
			$message = $parameters['message'] ?? null;
			
			if (!is_string($property)) {
				throw new \InvalidArgumentException("Type: 'property' must be a string.");
			}
			
			if ($type !== null && !is_string($type)) {
				throw new \InvalidArgumentException("Type: 'type' must be a string or null.");
			}
			
			if ($message !== null && !is_string($message)) {
				throw new \InvalidArgumentException("Type: 'message' must be a string or null.");
			}
			
			$this->parameters = $parameters;
			$this->property = $property;
			$this->type = $type;
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
		 * Returns the expected type
		 * @return string|null
		 */
		public function getType(): ?string {
			return $this->type;
		}
		
		/**
		 * Returns the message when the validation fails, null if there's no message configured
		 * @return string|null
		 */
		public function getMessage(): ?string {
			return $this->message;
		}
	}