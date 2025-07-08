<?php
	
	namespace Quellabs\ObjectQuel\Validation;
	
	use Quellabs\ObjectQuel\AnnotationsReader\Annotations\Validation\Date;
	use Quellabs\ObjectQuel\AnnotationsReader\Annotations\Validation\Email;
	use Quellabs\ObjectQuel\AnnotationsReader\Annotations\Validation\Length;
	use Quellabs\ObjectQuel\AnnotationsReader\Annotations\Validation\NotBlank;
	use Quellabs\ObjectQuel\AnnotationsReader\Annotations\Validation\RegExp;
	use Quellabs\ObjectQuel\AnnotationsReader\Annotations\Validation\Type;
	use Quellabs\ObjectQuel\AnnotationsReader\Annotations\Validation\ValueIn;
	
	class AnnotationsToValidation {
		
		/**
		 * Converts entity annotations to validation rules.
		 * This function takes an entity object and converts the annotations of its properties
		 * to corresponding validation rules. It uses a predefined mapping
		 * between annotation classes and validation rule classes.
		 * @param array $annotations
		 * @return array An array with validation rules for each property of the entity
		 */
		public function convert(array $annotations): array {
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
			
			foreach ($annotations as $annotation) {
				$annotationClass = get_class($annotation);
				
				// The property parameter must be present. This is the property that needs to be checked
				if (!$annotation->hasProperty()) {
					continue;
				}
				
				// Check if there is a corresponding validation rule for this annotation
				if (isset($annotationMap[$annotationClass])) {
					// Add a new instance of the validation rule to the result
					$result[$annotation->getProperty()] = new $annotationMap[$annotationClass]($annotation->getParameters());
				}
			}
			
			// Return the array with validation rules
			return $result;
		}
	}