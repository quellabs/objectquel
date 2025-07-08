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
		 */
		protected array $parameters;
		
		/**
		 * Cascade constructor.
		 * @param array $parameters Array of parameters from the annotation
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
		 * Get the operations that should cascade
		 *
		 * Possible values include:
		 * - "remove": Cascade deletion
		 * - "persist": Cascade persistence
		 * @return array List of operations to cascade
		 */
		public function getOperations(): array {
			return $this->parameters['operations'] ?? [];
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
			return $this->parameters['strategy'] ?? "both";
		}
	}