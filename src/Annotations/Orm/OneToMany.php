<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * Class OneToMany
	 * This class represents a OneToMany relationship in the ORM and contains various methods
	 * to obtain information about the relationship.
	 */
	class OneToMany implements AnnotationInterface {
		
		/**
		 * @var array The parameters that were passed with the annotation.
		 */
		protected array $parameters;
		
		/**
		 * OneToMany constructor.
		 * @param array $parameters The parameters of the OneToMany annotation.
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
		 * Retrieve the target entity.
		 * @return string The full namespace of the target entity.
		 */
		public function getTargetEntity(): string {
			return $this->parameters["targetEntity"];
		}
		
		/**
		 * Retrieve the 'mappedBy' parameter.
		 * @return string|null The value of the 'mappedBy' parameter or an empty string if it is not set.
		 */
		public function getMappedBy(): ?string {
			return $this->parameters["mappedBy"] ?? null;
		}
		
		/**
		 * Retrieve the name of the relationship column.
		 * This method retrieves the name of the column that represents the OneToMany relationship in the database.
		 * The column name is determined based on the following priorities:
		 * 1. If the parameter "relationColumn" is set in the annotation, then this value is used.
		 * 2. If "relationColumn" is not set but "mappedBy" is, then the value of "mappedBy" is used.
		 * 3. If neither parameter is set, null is returned.
		 * @return string|null The name of the join column or null if it is not set.
		 */
		public function getRelationColumn(): ?string {
			return $this->parameters["relationColumn"] ?? null;
		}
		
		/**
		 * Returns the sort order
		 * @return string
		 */
		public function getOrderBy(): string {
			return $this->parameters["orderBy"] ?? '';
		}
	}