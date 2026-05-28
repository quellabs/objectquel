<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * @Annotation
	 * Class Cascade
	 */
	class Cascade implements AnnotationInterface {
		
		/**
		 * Contains all parameters defined in the annotation
		 * Example: @Orm\Cascade(operations={"remove"}, strategy="database")
		 * @var array<string, mixed>
		 */
		protected array $parameters;
		
		/** @var array<int, string> */
		private array $operations;
		
		/** @var string Cascading strategy */
		private string $strategy;
		
		/**
		 * Cascade constructor.
		 * @param array<string, mixed> $parameters Array of parameters from the annotation
		 * @throws \InvalidArgumentException
		 */
		public function __construct(array $parameters) {
			$strategy = $parameters['strategy'] ?? 'both';
			$operations = $parameters['operations'] ?? [];
			
			if (!in_array($strategy, ['orm', 'database', 'both'], true)) {
				throw new \InvalidArgumentException(
					'Cascade: strategy must be one of: orm, database, both'
				);
			}
			
			if (!is_array($operations)) {
				$operations = [];
			}
			
			$this->parameters = $parameters;
			$this->operations = array_values(array_filter($operations, 'is_string'));
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
		 * Get the operations that should cascade
		 *
		 * Possible values include:
		 * - "remove": Cascade deletion
		 * - "persist": Cascade persistence
		 * @return array<int, string> List of operations to cascade
		 */
		public function getOperations(): array {
			return $this->operations;
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
			return $this->strategy;
		}
	}