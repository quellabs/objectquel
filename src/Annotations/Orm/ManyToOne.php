<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * Defines the ManyToOne class that describes the relationship between entities
	 */
	class ManyToOne implements AnnotationInterface {
		
		// Contains parameters that provide additional information about the relationship
		protected array $parameters;
		
		/**
		 * Constructor to initialize the parameters.
		 * @param array $parameters Array with parameters that describe the relationship.
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
		 * Retrieves the target entity.
		 * @return string The full namespace of the target entity.
		 */
		public function getTargetEntity(): string {
			return $this->parameters["targetEntity"];
		}
		
		/**
		 * Retrieves the 'inversedBy' parameter, if present.
		 * @return string|null The name of the field in the target entity that refers to the current entity, or null if it is not set.
		 */
		public function getInversedBy(): ?string {
			return $this->parameters["inversedBy"] ?? null;
		}
		
		/**
		 * Retrieve the name of the relationship column in the current entity.
		 * This method retrieves the name of the column that represents the ManyToOne relationship in the database.
		 * @return string|null The name of the join column or null if it is not set.
		 */
		public function getRelationColumn(): ?string {
			return $this->parameters["relationColumn"] ?? null;
		}
		
		/**
		 * Retrieve the name of the relationship column in the target entity.
		 * @return string|null The name of the join column or null if it is not set.
		 */
		public function getTargetColumn(): ?string {
			return $this->parameters["targetColumn"] ?? null;
		}
		
		/**
		 * Returns fetch method (default EAGER)
		 * @return string
		 */
		public function getFetch(): string {
			return isset($this->parameters["fetch"]) ? strtoupper($this->parameters["fetch"]) : "EAGER";
		}
	}