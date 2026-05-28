<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Validation;
	
	/**
	 * @Annotation
	 */
	class RegExp implements PropertyValidationInterface {
		
		/** @var array<string, mixed> */
		protected array $parameters;
		
		/** @var string The property to check */
		protected string $property;
		
		/** @var string|null The regular expression to validate against */
		protected ?string $regexp;
		
		/** @var string|null The error message to show if check failed */
		protected ?string $message;
		
		/**
		 * RegExp constructor.
		 * @param array<string, mixed> $parameters
		 */
		public function __construct(array $parameters) {
			$property = $parameters['property'] ?? '';
			$regexp = $parameters['regexp'] ?? null;
			$message = $parameters['message'] ?? null;
			
			if (!is_string($property)) {
				throw new \InvalidArgumentException("RegExp: 'property' must be a string.");
			}
			
			if ($regexp !== null && !is_string($regexp)) {
				throw new \InvalidArgumentException("RegExp: 'regexp' must be a string or null.");
			}
			
			if ($message !== null && !is_string($message)) {
				throw new \InvalidArgumentException("RegExp: 'message' must be a string or null.");
			}
			
			$this->parameters = $parameters;
			$this->property = $property;
			$this->regexp = $regexp;
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
		 * Returns the regular expression to validate against
		 * @return string|null
		 */
		public function getRegExp(): ?string {
			return $this->regexp;
		}
		
		/**
		 * Returns the message when the validation fails, null if there's no message configured
		 * @return string|null
		 */
		public function getMessage(): ?string {
			return $this->message;
		}
	}