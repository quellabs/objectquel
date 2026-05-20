<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * Defines the OneToOne class that describes the relationship between entities
	 */
	class OneToOne implements AnnotationInterface {
		
		// Contains parameters that provide additional information about the relationship
		/** @var array<string, mixed> */
		protected array $parameters;
		
		/**
		 * Constructor to initialize the parameters.
		 * @param array<string, mixed> $parameters Array with parameters that describe the relationship.
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
		 * Retrieves the target entity.
		 * @return string The full namespace of the target entity.
		 */
		public function getTargetEntity(): string {
			if (!is_string($this->parameters["targetEntity"] ?? null)) {
				throw new \InvalidArgumentException("OneToOne: 'targetEntity' must be a string");
			}
			
			return $this->parameters["targetEntity"];
		}
		
		/**
		 * Retrieve the target entity.
		 * @param string $targetEntity
		 * @return void The full namespace of the target entity.
		 */
		public function setTargetEntity(string $targetEntity): void {
			$this->parameters["targetEntity"] = $targetEntity;
		}
		
		/**
		 * Retrieves the 'mappedBy' parameter.
		 * @return string|null The value of the 'mappedBy' parameter or an empty string if it is not set.
		 */
		public function getMappedBy(): ?string {
			$value = $this->parameters["mappedBy"] ?? null;
			
			if ($value !== null && !is_string($value)) {
				throw new \InvalidArgumentException("OneToOne: 'mappedBy' must be a string or null");
			}
			
			return $value;
		}
		
		/**
		 * Retrieves the 'inversedBy' parameter, if present.
		 * @return string|null The name of the field in the target entity that refers to the current entity, or null if it is not set.
		 */
		public function getInversedBy(): ?string {
			$value = $this->parameters["inversedBy"] ?? null;
			
			if ($value !== null && !is_string($value)) {
				throw new \InvalidArgumentException("OneToOne: 'inversedBy' must be a string or null");
			}
			
			return $value;
		}
		
		/**
		 * Retrieves the name of the relationship column.
		 * This method retrieves the name of the column that represents the ManyToOne relationship in the database.
		 * @return string|null The name of the join column or null if it is not set.
		 */
		public function getRelationColumn(): ?string {
			$value = $this->parameters["relationColumn"] ?? null;
			
			if ($value !== null && !is_string($value)) {
				throw new \InvalidArgumentException("OneToOne: 'relationColumn' must be a string or null");
			}
			
			return $value;
		}
		
		/**
		 * Retrieve the name of the relationship column in the target entity.
		 * @return string|null The name of the join column or null if it is not set.
		 */
		public function getForeignColumn(): ?string {
			$value = $this->parameters["foreignColumn"] ?? null;
			
			if ($value !== null && !is_string($value)) {
				throw new \InvalidArgumentException("OneToOne: 'foreignColumn' must be a string or null");
			}
			
			return $value;
		}
		
		/**
		 * Returns fetch method (default LAZY)
		 * @return string
		 */
		public function getFetch(): string {
			$value = $this->parameters["fetch"] ?? null;
			
			if ($value !== null && !is_string($value)) {
				throw new \InvalidArgumentException("OneToOne: 'fetch' must be a string or null");
			}
			
			return $value !== null ? strtoupper($value) : "LAZY";
		}
	}