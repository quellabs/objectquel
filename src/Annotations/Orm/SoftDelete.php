<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * @Annotation
	 * @package Quellabs\ObjectQuel\Annotations\Orm
	 */
	class SoftDelete implements AnnotationInterface {
		
		/** @var array<string, mixed> */
		protected array $parameters;
		
		/**
		 * SoftDelete constructor.
		 * @param array<string, mixed> $parameters
		 */
		public function __construct(array $parameters) {
			$this->parameters = $parameters;
		}
		
		/** @return array<string, mixed> */
		public function getParameters(): array {
			return $this->parameters;
		}
	}