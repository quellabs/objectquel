<?php

	namespace Quellabs\ObjectQuel\Annotations\Validation;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;

	/**
	 * @Annotation
	 */
	#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
	class Length implements PropertyValidationInterface {
		use ReflectsAnnotationParameters;



		/**
		 * @var string The property to check
		 */
		protected string $property;

		/**
		 * @var int|null The minimum length
		 */
		protected ?int $min;

		/**
		 * @var int|null The maximum length
		 */
		protected ?int $max;

		/**
		 * @var string|null The error message to show if check failed
		 */
		protected ?string $message;

		/**
		 * Constructs the Length annotation.
		 * @param string $property
		 * @param ?int $min
		 * @param ?int $max
		 * @param ?string $message
		 * @return void
		 */
		public function __construct(string $property = '', ?int $min = null, ?int $max = null, ?string $message = null) {
			$this->property = $property;
			$this->min = $min;
			$this->max = $max;
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