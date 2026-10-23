<?php

	namespace Quellabs\ObjectQuel\Annotations\Orm;

	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;

	/**
	 * @Annotation
	 */
	#[\Attribute(\Attribute::TARGET_CLASS)]
	class DiscriminatorColumn implements AnnotationInterface {
		use ReflectsAnnotationParameters;



		/**
		 * @var string Column name
		 */
		private string $name;

		/**
		 * Constructs the DiscriminatorColumn annotation.
		 * @param string $name
		 * @return void
		 */
		public function __construct(string $name) {
			$this->name = $name;
		}





		/**
		 * Returns the name
		 * @return string
		 */
		public function getName(): string {
			return $this->name;
		}
	}