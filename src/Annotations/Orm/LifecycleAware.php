<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;
	
	/**
	 * @Annotation
	 */
	#[\Attribute(\Attribute::TARGET_CLASS)]
	class LifecycleAware implements AnnotationInterface {
		use ReflectsAnnotationParameters;
		
		/**
		 * Constructs the LifecycleAware annotation.
		 * @return void
		 */
		public function __construct() {
		}
	}