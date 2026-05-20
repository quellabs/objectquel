<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * Class Cascade
	 */
	class Cascade implements AnnotationInterface {
		
		/**
		 * Contains all parameters defined in the annotation
		 * Example: @Orm\Cascade(operations={"remove"}, strategy="database")
		 * @var array<string, mixed>
		 */
		protected array $parameters;
		
		/**
		 * Cascade constructor.
		 * @param array<string, mixed> $parameters Array of parameters from the annotation
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
		 * Get the operations that should cascade
		 *
		 * Possible values include:
		 * - "remove": Cascade deletion
		 * - "persist": Cascade persistence
		 * @return array<int, string> List of operations to cascade
		 */
		public function getOperations(): array {
			if (!isset($this->parameters['operations']) || !is_array($this->parameters['operations'])) {
				return [];
			}
			
			$operations = array_filter(
				$this->parameters['operations'],
				static fn($operation): bool => is_string($operation)
			);
			
			return array_values($operations);
		}
		
		/**
		 * Get the strategy for implementing cascades
		 *
		 * Possible values:
		 * - "orm": Implement at ORM level only
		 * - "database": Implement using database constraints
		 * - "both": Implement at both levels
		 *
		 * @return string The cascading strategy
		 */
		public function getStrategy(): string {
			if (
				!isset($this->parameters['strategy']) ||
				!is_string($this->parameters['strategy']) ||
				!in_array($this->parameters['strategy'], ['orm', 'database', 'both'])
			) {
				throw new \InvalidArgumentException('Strategy must be either orm, database or both');
			}
			
			return $this->parameters['strategy'];
		}
	}