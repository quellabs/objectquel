<?php

    namespace Quellabs\ObjectQuel\Annotations\Orm;

    use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;

    /**
     * @Annotation
     */
    #[\Attribute(\Attribute::TARGET_CLASS)]
	class Immutable implements AnnotationInterface {
		use ReflectsAnnotationParameters;



        /**
		 * Constructs the Immutable annotation.
		 * @return void
		 */
		public function __construct() {
		}




    }