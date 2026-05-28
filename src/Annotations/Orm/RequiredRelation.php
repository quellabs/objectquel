<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * @Annotation
	 */
	class RequiredRelation implements AnnotationInterface {
		
		/** @var array<string, mixed> */
		protected array $parameters;
		
		/**
		 * RequiredRelation constructor
		 * @param array<string, mixed> $parameters Array met parameters die de relatie beschrijven.
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
	}