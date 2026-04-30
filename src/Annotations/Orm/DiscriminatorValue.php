<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	class DiscriminatorValue implements AnnotationInterface {
		
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
		 * Returns the value of the discriminator column
		 * @return string
		 */
		public function getValue(): string {
			return $this->parameters['value'] ?? '';
		}
	}