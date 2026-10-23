<?php

	namespace Quellabs\ObjectQuel\Annotations\Orm;

	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;

	/**
	 * @Annotation
	 * Class Version
	 */
	#[\Attribute(\Attribute::TARGET_PROPERTY)]
	class Version implements AnnotationInterface {
		use ReflectsAnnotationParameters;



		/**
		 * Constructs the Version annotation.
		 * @return void
		 */
		public function __construct() {
		}




	}