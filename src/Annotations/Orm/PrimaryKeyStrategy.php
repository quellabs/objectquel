<?php

	namespace Quellabs\ObjectQuel\Annotations\Orm;

	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;

	/**
	 * @Annotation
	 */
	#[\Attribute(\Attribute::TARGET_PROPERTY)]
	class PrimaryKeyStrategy implements AnnotationInterface {
		use ReflectsAnnotationParameters;



		/**
		 * @var string Strategy type
		 */
		private string $strategy;

		/**
		 * Constructs the PrimaryKeyStrategy annotation.
		 * @param string $strategy
		 * @return void
		 */
		public function __construct(string $strategy = 'identity') {
			$this->strategy = $strategy;
		}





		/**
		 * Returns the chosen strategy (default: "identity")
		 * @return string
		 */
		public function getValue(): string {
			return $this->strategy;
		}
	}