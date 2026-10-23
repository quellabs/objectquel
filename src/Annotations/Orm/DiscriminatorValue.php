<?php

	namespace Quellabs\ObjectQuel\Annotations\Orm;

	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;

	/**
	 * @Annotation
	 */
	#[\Attribute(\Attribute::TARGET_CLASS)]
	class DiscriminatorValue implements AnnotationInterface {
		use ReflectsAnnotationParameters;



		/**
		 * @var string Value to be set in DiscriminatorColumn
		 */
		private string $value;

		/**
		 * Constructs the DiscriminatorValue annotation.
		 * @param string $value
		 * @return void
		 */
		public function __construct(string $value) {
			$this->value = $value;
		}





		/**
		 * Returns the value of the discriminator column
		 * @return string
		 */
		public function getValue(): string {
			return $this->value;
		}
	}