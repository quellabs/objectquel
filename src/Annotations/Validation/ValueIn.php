<?php

	namespace Quellabs\ObjectQuel\Annotations\Validation;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;

	/**
	 * @Annotation
	 */
	#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
	class ValueIn implements PropertyValidationInterface {
		use ReflectsAnnotationParameters;



		/**
		 * @var string The property to check
		 */
		protected string $property;

		/**
		 * @var array<mixed>|null The set of allowed values
		 */
		protected ?array $values;

		/**
		 * @var string|null The error message to show if check failed
		 */
		protected ?string $message;

		/**
		 * Constructs the ValueIn annotation.
		 * @param string $property
		 * @param array<mixed>|null $values
		 * @param ?string $message
		 * @return void
		 */
		public function __construct(string $property = '', ?array $values = null, ?string $message = null) {
			$this->property = $property;
			$this->values = $values;
			$this->message = $message;
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