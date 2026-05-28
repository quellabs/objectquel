<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * @Annotation
	 */
	class PrimaryKeyStrategy implements AnnotationInterface {
		
		/** @var array<string, mixed> */
		protected array $parameters;
		
		/** @var string Strategy type */
		private string $strategy;
		
		/**
		 * PrimaryKeyStrategy constructor.
		 * @param array<string, mixed> $parameters
		 * @throws \InvalidArgumentException
		 */
		public function __construct(array $parameters) {
			$strategy = $parameters['strategy'] ?? 'identity';
			
			if (!is_string($strategy)) {
				throw new \InvalidArgumentException(
					'PrimaryKeyStrategy: strategy must be a string'
				);
			}
			
			$this->parameters = $parameters;
			$this->strategy = $strategy;
		}
		
		/**
		 * Returns the parameters for this annotation
		 * @return array<string, mixed>
		 */
		public function getParameters(): array {
			return $this->parameters;
		}
		
		/**
		 * Returns the chosen strategy (default: "identity")
		 * @return string
		 */
		public function getValue(): string {
			return $this->strategy;
		}
	}