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
		
		private string $targetEntity;
		private ?string $mappedBy;
		private ?string $relationColumn;
		private string $orderBy;
		
		/**
		 * OneToMany constructor.
		 * @param array<string, mixed> $parameters The parameters of the OneToMany annotation.
		 * @throws \InvalidArgumentException
		 */
		public function __construct(array $parameters) {
			$targetEntity = $parameters['targetEntity'] ?? null;
			$mappedBy = $parameters['mappedBy'] ?? null;
			$relationColumn = $parameters['relationColumn'] ?? null;
			$orderBy = $parameters['orderBy'] ?? '';
			
			if (!is_string($targetEntity)) {
				throw new \InvalidArgumentException("OneToMany: 'targetEntity' must be a string");
			}
			
			if ($mappedBy !== null && !is_string($mappedBy)) {
				throw new \InvalidArgumentException("OneToMany: 'mappedBy' must be a string or null");
			}
			
			if ($relationColumn !== null && !is_string($relationColumn)) {
				throw new \InvalidArgumentException("OneToMany: 'relationColumn' must be a string or null");
			}
			
			if (!is_string($orderBy)) {
				throw new \InvalidArgumentException("OneToMany: 'orderBy' must be a string");
			}
			
			$this->parameters = $parameters;
			$this->targetEntity = $targetEntity;
			$this->mappedBy = $mappedBy;
			$this->relationColumn = $relationColumn;
			$this->orderBy = $orderBy;
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
			return $this->targetEntity;
		}
		
		/**
		 * Retrieve the target entity.
		 * @param class-string $targetEntity
		 * @return void The full namespace of the target entity.
		 */
		public function setTargetEntity(string $targetEntity): void {
			$this->targetEntity = $targetEntity;
			$this->parameters['targetEntity'] = $targetEntity;
		}
		
		/**
		 * Retrieve the 'mappedBy' parameter.
		 * @return string|null The value of the 'mappedBy' parameter or an empty string if it is not set.
		 */
		public function getMappedBy(): ?string {
			return $this->mappedBy;
		}
		
		/**
		 * Retrieve the name of the relationship column.
		 * This method retrieves the name of the column that represents the OneToMany relationship in the database.
		 * @return string|null The name of the join column or null if it is not set.
		 */
		public function getRelationColumn(): ?string {
			return $this->relationColumn;
		}
		
		/**
		 * Returns the sort order
		 * @return string
		 */
		public function getOrderBy(): string {
			return $this->orderBy;
		}
	}