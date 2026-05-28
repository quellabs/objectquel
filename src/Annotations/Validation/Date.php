<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Validation;
	
	/**
	 * @Annotation
	 */
	class Date implements PropertyValidationInterface {
		
		/** @var array<string, mixed>  */
		protected array $parameters;
		
		/** @var string The property to check */
		protected string $property;
		
		/** @var string|null The error message to show if check failed */
		protected ?string $message;
		
		/**
		 * Date constructor.
		 * @param array<string, mixed> $parameters
		 */
		public function __construct(array $parameters) {
			$property = $parameters['property'] ?? '';
			$message = $parameters['message'] ?? null;

			if (!is_string($property)) {
				throw new \InvalidArgumentException("Date: 'property' must be a string.");
			}
			
			if ($message !== null && !is_string($message)) {
				throw new \InvalidArgumentException("Date: 'message' must be a string or null.");
			}
			
			$this->parameters = $parameters;
			$this->property = $property;
			$this->message = $message;
		}
		
		/**
		 * Returns all parameters, except property and message
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
		 * Returns the message when the validation fails, null if there's no message configured
		 * @return string|null
		 */
		public function getMessage(): ?string {
			return $this->message;
		}
	}