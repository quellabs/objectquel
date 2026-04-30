<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	class DiscriminatorColumn implements AnnotationInterface {
		
		protected array $parameters;
		
		/**
		 * Constructor.
		 * @param array $parameters
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
		
		/**
		 * Returns the name
		 * @return string
		 */
		public function getName(): string {
			return $this->parameters['name'] ?? '';
		}
	}