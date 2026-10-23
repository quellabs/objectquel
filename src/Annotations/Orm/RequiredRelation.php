<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;
	
	/**
	 * @Annotation
	 */
	#[\Attribute(\Attribute::TARGET_PROPERTY)]
	class RequiredRelation implements AnnotationInterface {
		use ReflectsAnnotationParameters;
		
		/**
		 * Constructs the RequiredRelation annotation.
		 * @return void
		 */
		public function __construct() {
		}
	}