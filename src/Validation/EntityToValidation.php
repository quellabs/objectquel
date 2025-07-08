<?php
	
	namespace Quellabs\ObjectQuel\Validation;
	
	use Quellabs\ObjectQuel\AnnotationsReader\Annotations\Validation\Date;
	use Quellabs\ObjectQuel\AnnotationsReader\Annotations\Validation\Email;
	use Quellabs\ObjectQuel\AnnotationsReader\Annotations\Validation\Length;
	use Quellabs\ObjectQuel\AnnotationsReader\Annotations\Validation\NotBlank;
	use Quellabs\ObjectQuel\AnnotationsReader\Annotations\Validation\RegExp;
	use Quellabs\ObjectQuel\AnnotationsReader\Annotations\Validation\Type;
	use Quellabs\ObjectQuel\AnnotationsReader\Annotations\Validation\ValueIn;
	use Quellabs\ObjectQuel\AnnotationsReader\AnnotationsReader;
	use Quellabs\ObjectQuel\Kernel\Kernel;
	use Quellabs\ObjectQuel\Kernel\ReflectionHandler;
	
	class EntityToValidation {
		
		private AnnotationsReader $annotationReader;
		private ReflectionHandler $reflectionHandler;
		
		/**
		 * EntityToValidation constructor
		 */
		public function __construct() {
			$this->reflectionHandler = new ReflectionHandler();
			$this->annotationReader = new AnnotationsReader();
		}
		
		/**
		 * Converts entity annotations to validation rules.
		 * This function takes an entity object and converts the annotations of its properties
		 * to corresponding validation rules. It uses a predefined mapping
		 * between annotation classes and validation rule classes.
		 * @param object $entity The entity object whose annotations need to be converted
		 * @return array An array with validation rules for each property of the entity
		 */
		public function convert(object $entity): array {
			// Mapping of annotation classes to validation rule classes
			$annotationMap = [
				Date::class          => Rules\Date::class,
				Email::class         => Rules\Email::class,
				Length::class        => Rules\Length::class,
				NotBlank::class      => Rules\NotBlank::class,
				RegExp::class        => Rules\RegExp::class,
				Type::class          => Rules\Type::class,
				ValueIn::class       => Rules\ValueIn::class,
			];
			
			// Loop through all properties of the entity
			$result = [];
			
			foreach ($this->reflectionHandler->getProperties($entity) as $property) {
				// Get the annotations for the current property
				$annotations = $this->annotationReader->getPropertyAnnotations($entity, $property);
				
				// Process each annotation
				foreach ($annotations as $annotation) {
					// Fetch the class name
					$annotationClass = get_class($annotation);
					
					// Check if there is a corresponding validation rule for this annotation
					if (isset($annotationMap[$annotationClass])) {
						// Add a new instance of the validation rule to the result
						$result[$property][] = new $annotationMap[$annotationClass]($annotation->getParameters());
					}
				}
			}
			
			// Return the array with validation rules
			return $result;
		}
	}