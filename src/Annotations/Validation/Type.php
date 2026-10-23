<?php

	namespace Quellabs\ObjectQuel\Annotations\Validation;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;

	/**
	 * @Annotation
	 */
	#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
	class Type implements PropertyValidationInterface {
		use ReflectsAnnotationParameters;



		/**
		 * @var string The property to check
		 */
		protected string $property;

		/**
		 * @var string|null The expected type
		 */
		protected ?string $type;

		/**
		 * @var string|null The error message to show if check failed
		 */
		protected ?string $message;

		/**
		 * Constructs the Type annotation.
		 * @param string $property
		 * @param ?string $type
		 * @param ?string $message
		 * @return void
		 */
		public function __construct(string $property = '', ?string $type = null, ?string $message = null) {
			$this->property = $property;
			$this->type = $type;
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