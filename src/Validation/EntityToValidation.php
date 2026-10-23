<?php
	
	namespace Quellabs\ObjectQuel\Validation;
	
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\AnnotationReaderLocator;
	use Quellabs\AnnotationReader\Exception\AnnotationReaderException;
	use Quellabs\ObjectQuel\Annotations\Validation\PropertyValidationInterface;
	use Quellabs\ObjectQuel\Annotations\Validation\Date;
	use Quellabs\ObjectQuel\Annotations\Validation\Email;
	use Quellabs\ObjectQuel\Annotations\Validation\Length;
	use Quellabs\ObjectQuel\Annotations\Validation\NotBlank;
	use Quellabs\ObjectQuel\Annotations\Validation\RegExp;
	use Quellabs\ObjectQuel\Annotations\Validation\Type;
	use Quellabs\ObjectQuel\Annotations\Validation\ValueIn;
	use Quellabs\ObjectQuel\ReflectionManagement\ReflectionHandler;
	
	class EntityToValidation {
		
		private AnnotationReader $annotationReader;
		private ReflectionHandler $reflectionHandler;
		
		/**
		 * Uses an injected reader or ObjectQuel's registered reader. Does not invent a
		 * default-configured reader, since that would silently ignore the app's configured
		 * annotation source (docblocks vs attributes).
		 * @param AnnotationReader|null $annotationReader
		 * @throws AnnotationReaderException If no reader was injected and none is registered for 'objectquel'
		 */
		public function __construct(?AnnotationReader $annotationReader = null) {
			$reader = $annotationReader ?? AnnotationReaderLocator::getInstance('objectquel');

			if ($reader === null) {
				throw new AnnotationReaderException(
					'EntityToValidation requires an AnnotationReader, either injected or registered for \'objectquel\' via AnnotationReaderLocator.'
				);
			}

			$this->reflectionHandler = new ReflectionHandler();
			$this->annotationReader = $reader;
		}
		
		/**
		 * Converts entity annotations to validation rules.
		 * This function takes an entity object and converts the annotations of its properties
		 * to corresponding validation rules. It uses a predefined mapping
		 * between annotation classes and validation rule classes.
		 * @param object $entity The entity object whose annotations need to be converted
		 * @return array<int|string, array<int, ValidationInterface>>
		 */
		public function convert(object $entity): array {
			// Mapping of annotation classes to validation rule classes
			$annotationMap = [
				Date::class     => Rules\Date::class,
				Email::class    => Rules\Email::class,
				Length::class   => Rules\Length::class,
				NotBlank::class => Rules\NotBlank::class,
				RegExp::class   => Rules\RegExp::class,
				Type::class     => Rules\Type::class,
				ValueIn::class  => Rules\ValueIn::class,
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
					if (
						isset($annotationMap[$annotationClass]) &&
						$annotation instanceof PropertyValidationInterface
					) {
						// Add a new instance of the validation rule to the result
						$conditions = $annotation->getParameters();
						unset($conditions['property'], $conditions['message']);
						$result[$property][] = new $annotationMap[$annotationClass]($conditions, $annotation->getMessage());
					}
				}
			}
			
			// Return the array with validation rules
			return $result;
		}
	}
