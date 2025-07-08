<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * Defines the OneToOne class that describes the relationship between entities
	 */
	class OneToOne implements AnnotationInterface {
		
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
		 * Retrieves the 'mappedBy' parameter.
		 * @return string The value of the 'mappedBy' parameter or an empty string if it is not set.
		 */
		public function getMappedBy(): ?string {
			return $this->parameters["mappedBy"] ?? null;
		}
		
		/**
		 * Retrieves the 'inversedBy' parameter, if present.
		 * @return string|null The name of the field in the target entity that refers to the current entity, or null if it is not set.
		 */
		public function getInversedBy(): ?string {
			return $this->parameters["inversedBy"] ?? null;
		}
		
		/**
		 * Retrieves the name of the relationship column.
		 * This method retrieves the name of the column that represents the ManyToOne relationship in the database.
		 * The column name is determined based on the following priorities:
		 * 1. If the parameter "relationColumn" is set in the annotation, then this value is used.
		 * 2. If "relationColumn" is not set but "inversedBy" is, then the value of "inversedBy" is used.
		 * 3. If neither parameter is set, null is returned.
		 * @return string|null The name of the join column or null if it is not set.
		 */
		public function getRelationColumn(): ?string {
			if (!isset($this->parameters["relationColumn"])) {
				return null;
			}
			
			return $this->parameters["relationColumn"];
		}
		
		/**
		 * Returns fetch method (default LAZY)
		 * @return string
		 */
		public function getFetch(): string {
			if (empty($this->parameters["fetch"])) {
				return "LAZY";
			}
			
			return strtoupper($this->parameters["fetch"]);
		}
	}