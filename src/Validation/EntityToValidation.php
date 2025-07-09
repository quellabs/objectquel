<?php
	
	namespace Quellabs\ObjectQuel\Validation;
	
	use Quellabs\AnnotationReader\Configuration;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\ObjectQuel\Validation\Rules\Date;
	use Quellabs\ObjectQuel\Validation\Rules\Email;
	use Quellabs\ObjectQuel\Validation\Rules\Length;
	use Quellabs\ObjectQuel\Validation\Rules\NotBlank;
	use Quellabs\ObjectQuel\Validation\Rules\RegExp;
	use Quellabs\ObjectQuel\Validation\Rules\Type;
	use Quellabs\ObjectQuel\Validation\Rules\ValueIn;
	use Quellabs\ObjectQuel\ReflectionManagement\ReflectionHandler;
	
	class EntityToValidation {
		
		private AnnotationReader $annotationReader;
		private ReflectionHandler $reflectionHandler;
		
		/**
		 * EntityToValidation constructor
		 */
		public function __construct() {
			$annotationReaderConfiguration = new Configuration();
			$annotationReaderConfiguration->setUseAnnotationCache(false);
			$annotationReaderConfiguration->setAnnotationCachePath('');
			
			$this->reflectionHandler = new ReflectionHandler();
			$this->annotationReader = new AnnotationReader($annotationReaderConfiguration);
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