<?php

    namespace Quellabs\ObjectQuel\Annotations\Orm;

    use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;

    /**
     * @Annotation
     */
    #[\Attribute(\Attribute::TARGET_CLASS)]
	class EntityBridge implements AnnotationInterface {
		use ReflectsAnnotationParameters;



        /**
		 * Constructs the EntityBridge annotation.
		 * @return void
		 */
		public function __construct() {
		}




    }