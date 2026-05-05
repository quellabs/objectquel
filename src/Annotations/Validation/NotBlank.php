<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Validation;
	
	/**
	 * @phpstan-type NotBlankParams array{
	 *     property?: string,
	 *     message?: string|null
	 * }
	 */
	class NotBlank implements PropertyValidationInterface {
		
		/** @var NotBlankParams */
		protected array $parameters;
		
		/**
		 * NotBlank constructor.
		 * @param NotBlankParams $parameters
		 */
		public function __construct(array $parameters) {
			$this->parameters = $parameters;
		}
		
		/**
		 * Returns all parameters
		 * @return NotBlankParams
		 */
		public function getParameters(): array {
			return $this->parameters;
		}
		
		/**
		 * Returns true if the 'property' field is populated, false if not
		 * @return bool
		 */
		public function hasProperty(): bool {
			return !empty($this->parameters['property']);
		}
		
		/**
		 * Returns the value of 'column'
		 * @return string
		 */
		public function getProperty(): string {
			return $this->parameters['property'] ?? '';
		}
		
		/**
		 * Returns the message when the validation fails, null if there's no message configured
		 * @return string|null
		 */
		public function getMessage(): ?string {
			return $this->parameters['message'] ?? null;
		}
	}