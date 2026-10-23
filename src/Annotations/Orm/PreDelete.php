<?php

    namespace Quellabs\ObjectQuel\Annotations\Orm;

    use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;

    /**
     * @Annotation
     */
    #[\Attribute(\Attribute::TARGET_METHOD)]
	class PreDelete implements AnnotationInterface {
		use ReflectsAnnotationParameters;



        /**
		 * Constructs the PreDelete annotation.
		 * @return void
		 */
		public function __construct() {
		}




    }