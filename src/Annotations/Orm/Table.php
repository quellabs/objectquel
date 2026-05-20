<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	class Table implements AnnotationInterface {
		
		/** @var array<string, mixed> */
		protected array $parameters;
		
		/** @var string Table name */
		private string $name;
		
		/**
		 * Table constructor.
		 * @param array<string, mixed> $parameters
		 * @throws \InvalidArgumentException
		 */
		public function __construct(array $parameters) {
			if (!isset($parameters['name']) || !is_string($parameters['name'])) {
				throw new \InvalidArgumentException("Table annotation requires a valid 'name' parameter");
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
		 * Returns the table name
		 * @return string
		 */
		public function getName(): string {
			return $this->name;
		}
	}