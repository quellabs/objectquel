<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	class DiscriminatorColumn implements AnnotationInterface {
		
		/** @var array<string, mixed> */
		protected array $parameters;
		
		/**
		 * DiscriminatorColumn constructor.
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
		 * Returns the name
		 * @return string
		 */
		public function getName(): string {
			if (!isset($this->parameters["name"]) || !is_string($this->parameters["name"])) {
				return "";
			}
			
			return $this->parameters['name'];
		}
	}