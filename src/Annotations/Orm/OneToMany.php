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
		 * @var array<string, mixed> The parameters that were passed with the annotation.
		 */
		protected array $parameters;
		
		/**
		 * OneToMany constructor.
		 * @param array<string, mixed> $parameters The parameters of the OneToMany annotation.
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
		 * Retrieve the target entity.
		 * @return string The full namespace of the target entity.
		 */
		public function getTargetEntity(): string {
			if (!is_string($this->parameters["targetEntity"] ?? null)) {
				throw new \InvalidArgumentException("OneToMany: 'targetEntity' must be a string");
			}
			
			return $this->parameters["targetEntity"];
		}
		
		/**
		 * Retrieve the target entity.
		 * @param class-string $targetEntity
		 * @return void The full namespace of the target entity.
		 */
		public function setTargetEntity(string $targetEntity): void {
			$this->parameters["targetEntity"] = $targetEntity;
		}
		
		/**
		 * Retrieve the 'mappedBy' parameter.
		 * @return string|null The value of the 'mappedBy' parameter or an empty string if it is not set.
		 */
		public function getMappedBy(): ?string {
			$value = $this->parameters["mappedBy"] ?? null;
			
			if ($value !== null && !is_string($value)) {
				throw new \InvalidArgumentException("OneToMany: 'mappedBy' must be a string or null");
			}
			
			return $value;
		}
		
		/**
		 * Retrieve the name of the relationship column.
		 * This method retrieves the name of the column that represents the OneToMany relationship in the database.
		 * @return string|null The name of the join column or null if it is not set.
		 */
		public function getRelationColumn(): ?string {
			$value = $this->parameters["relationColumn"] ?? null;
			
			if ($value !== null && !is_string($value)) {
				throw new \InvalidArgumentException("OneToMany: 'relationColumn' must be a string or null");
			}
			
			return $value;
		}
		
		/**
		 * Returns the sort order
		 * @return string
		 */
		public function getOrderBy(): string {
			$value = $this->parameters["orderBy"] ?? null;
			
			if ($value !== null && !is_string($value)) {
				throw new \InvalidArgumentException("OneToMany: 'orderBy' must be a string or null");
			}
			
			return $value ?? '';
		}
	}