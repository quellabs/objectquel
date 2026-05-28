<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * @Annotation
	 */
	class DiscriminatorValue implements AnnotationInterface {
		
		/** @var array<string, mixed> */
		protected array $parameters;
		
		/** @var string Value to be set in DiscriminatorColumn */
		private string $value;
		
		/**
		 * Constructor.
		 * @param array<string, mixed> $parameters
		 * @throws \InvalidArgumentException
		 */
		public function __construct(array $parameters) {
			if (!isset($parameters['value']) || !is_string($parameters['value'])) {
				throw new \InvalidArgumentException("DiscriminatorValue annotation requires a valid 'value' parameter");
			}
			
			$this->parameters = $parameters;
			$this->value = $parameters['value'];
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
			return $this->value;
		}
	}