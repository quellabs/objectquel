<?php
	
	namespace Quellabs\ObjectQuel\Validation;
	
	use Quellabs\ObjectQuel\Annotations\Validation\PropertyValidationInterface;
	
	class AnnotationsToValidation {
		
		/**
		 * Converts entity annotations to validation rules.
		 * This function takes an entity object and converts the annotations of its properties
		 * to corresponding validation rules. It uses a predefined mapping
		 * between annotation classes and validation rule classes.
		 * @param array<int, PropertyValidationInterface> $annotations
		 * @return array<string, array<int, ValidationInterface>>
		 */
		public function convert(array $annotations): array {
			// Maps annotation classes to their corresponding validation rule classes
			$annotationMap = [
				\Quellabs\ObjectQuel\Annotations\Validation\Date::class     => Rules\Date::class,
				\Quellabs\ObjectQuel\Annotations\Validation\Email::class    => Rules\Email::class,
				\Quellabs\ObjectQuel\Annotations\Validation\Length::class   => Rules\Length::class,
				\Quellabs\ObjectQuel\Annotations\Validation\NotBlank::class => Rules\NotBlank::class,
				\Quellabs\ObjectQuel\Annotations\Validation\RegExp::class   => Rules\RegExp::class,
				\Quellabs\ObjectQuel\Annotations\Validation\Type::class     => Rules\Type::class,
				\Quellabs\ObjectQuel\Annotations\Validation\ValueIn::class  => Rules\ValueIn::class,
			];
			
			$result = [];
			
			foreach ($annotations as $annotation) {
				$annotationClass = get_class($annotation);
				
				// Skip annotations that don't target a specific property
				if (!$annotation->hasProperty()) {
					continue;
				}
				
				// Skip annotations that have no corresponding validation rule
				if (!isset($annotationMap[$annotationClass])) {
					continue;
				}
				
				$parameters = $annotation->getParameters();
				$property = $annotation->getProperty();
				$message = $annotation->getMessage();
				
				// Unset parameters for clean data for the validator
				unset($parameters['message'], $parameters['property']);
				
				// Append the rule so multiple validators can apply to the same property
				$result[$property][] = new $annotationMap[$annotationClass]($parameters, $message);
			}
			
			return $result;
		}
	}