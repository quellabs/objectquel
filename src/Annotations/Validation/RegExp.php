<?php

	namespace Quellabs\ObjectQuel\Annotations\Validation;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;

	/**
	 * @Annotation
	 */
	#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
	class RegExp implements PropertyValidationInterface {
		use ReflectsAnnotationParameters;



		/**
		 * @var string The property to check
		 */
		protected string $property;

		/**
		 * @var string|null The regular expression to validate against
		 */
		protected ?string $regexp;

		/**
		 * @var string|null The error message to show if check failed
		 */
		protected ?string $message;

		/**
		 * Constructs the RegExp annotation.
		 * @param string $property
		 * @param ?string $regexp
		 * @param ?string $message
		 * @return void
		 */
		public function __construct(string $property = '', ?string $regexp = null, ?string $message = null) {
			$this->property = $property;
			$this->regexp = $regexp;
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