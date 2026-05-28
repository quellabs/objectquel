<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Validation;
	
	/**
	 * @Annotation
	 */
	class Length implements PropertyValidationInterface {
		
		/** @var array<string, mixed> */
		protected array $parameters;
		
		/** @var string The property to check */
		protected string $property;
		
		/** @var int|null The minimum length */
		protected ?int $min;
		
		/** @var int|null The maximum length */
		protected ?int $max;
		
		/** @var string|null The error message to show if check failed */
		protected ?string $message;
		
		/**
		 * Length constructor.
		 * @param array<string, mixed> $parameters
		 */
		public function __construct(array $parameters) {
			$property = $parameters['property'] ?? '';
			$min = $parameters['min'] ?? null;
			$max = $parameters['max'] ?? null;
			$message = $parameters['message'] ?? null;
			
			if (!is_string($property)) {
				throw new \InvalidArgumentException("Length: 'property' must be a string.");
			}
			
			if ($min !== null && !is_int($min)) {
				throw new \InvalidArgumentException("Length: 'min' must be an integer or null.");
			}
			
			if ($max !== null && !is_int($max)) {
				throw new \InvalidArgumentException("Length: 'max' must be an integer or null.");
			}
			
			if ($message !== null && !is_string($message)) {
				throw new \InvalidArgumentException("Length: 'message' must be a string or null.");
			}
			
			$this->parameters = $parameters;
			$this->property = $property;
			$this->min = $min;
			$this->max = $max;
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
		 * Returns the minimum length
		 * @return int|null
		 */
		public function getMin(): ?int {
			return $this->min;
		}
		
		/**
		 * Returns the maximum length
		 * @return int|null
		 */
		public function getMax(): ?int {
			return $this->max;
		}
		
		/**
		 * Returns the message when the validation fails, null if there's no message configured
		 * @return string|null
		 */
		public function getMessage(): ?string {
			return $this->message;
		}
	}