<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	class DiscriminatorValue implements AnnotationInterface {
		
		/** @var array<string, mixed> */
		protected array $parameters;
		
		/**
		 * Constructor.
		 * @param array<string, mixed> $parameters
		 */
		public function __construct(array $parameters) {
			$this->parameters = $parameters;
		}
		
		/**
		 * Returns the parameters for this annotation
		 * @return array<string, mixed>
		 */
		public function getParameters(): array {
			return $this->parameters;
		}
		
		/**
		 * Returns the value of the discriminator column
		 * @return string
		 */
		public function getValue(): string {
			if (!isset($this->parameters["value"]) || !is_string($this->parameters["value"])) {
				return "";
			}
			
			return $this->parameters['value'];
		}
	}