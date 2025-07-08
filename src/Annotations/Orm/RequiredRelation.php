<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	class RequiredRelation implements AnnotationInterface {
		
		// Bevat parameters die extra informatie over de relatie geven
		protected array $parameters;
		
		/**
		 * Constructor om de parameters te initialiseren.
		 * @param array $parameters Array met parameters die de relatie beschrijven.
		 */
		public function __construct(array $parameters) {
			$this->parameters = $parameters;
		}
		
		/**
		 * Returns the parameters for this annotation
		 * @return array
		 */
		public function getParameters(): array {
			return $this->parameters;
		}
	}