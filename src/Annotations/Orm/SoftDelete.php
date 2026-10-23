<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;
	
	/**
	 * @Annotation
	 * @package Quellabs\ObjectQuel\Annotations\Orm
	 */
	#[\Attribute(\Attribute::TARGET_PROPERTY)]
	class SoftDelete implements AnnotationInterface {
		use ReflectsAnnotationParameters;
		
		/**
		 * Constructs the SoftDelete annotation.
		 * @return void
		 */
		public function __construct() {
		}
	}