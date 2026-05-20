<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	class DiscriminatorColumn implements AnnotationInterface {
		
		/** @var array<string, mixed> */
		protected array $parameters;
		
		/** @var string Column name */
		private string $name;
		
		/**
		 * DiscriminatorColumn constructor.
		 * @param array<string, mixed> $parameters
		 * @throws \InvalidArgumentException
		 */
		public function __construct(array $parameters) {
			if (!isset($parameters['name']) || !is_string($parameters['name'])) {
				throw new \InvalidArgumentException("DiscriminatorColumn annotation requires a valid 'name' parameter");
			}
			
			$this->parameters = $parameters;
			$this->name = $parameters['name'];
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
			return $this->name;
		}
	}