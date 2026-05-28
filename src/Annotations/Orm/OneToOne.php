<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * @Annotation
	 * Defines the OneToOne class that describes the relationship between entities
	 */
	class OneToOne implements AnnotationInterface {
		
		// Contains parameters that provide additional information about the relationship
		/** @var array<string, mixed> */
		protected array $parameters;
		
		private string $targetEntity;
		private ?string $mappedBy;
		private ?string $inversedBy;
		private ?string $relationColumn;
		private ?string $foreignColumn;
		private string $fetch;
		
		/**
		 * Constructor to initialize the parameters.
		 * @param array<string, mixed> $parameters Array with parameters that describe the relationship.
		 * @throws \InvalidArgumentException
		 */
		public function __construct(array $parameters) {
			$targetEntity = $parameters['targetEntity'] ?? null;
			$mappedBy = $parameters['mappedBy'] ?? null;
			$inversedBy = $parameters['inversedBy'] ?? null;
			$relationColumn = $parameters['relationColumn'] ?? null;
			$foreignColumn = $parameters['foreignColumn'] ?? null;
			$fetch = $parameters['fetch'] ?? 'LAZY';
			
			if (!is_string($targetEntity)) {
				throw new \InvalidArgumentException("OneToOne: 'targetEntity' must be a string");
			}
			
			if ($mappedBy !== null && !is_string($mappedBy)) {
				throw new \InvalidArgumentException("OneToOne: 'mappedBy' must be a string or null");
			}
			
			if ($inversedBy !== null && !is_string($inversedBy)) {
				throw new \InvalidArgumentException("OneToOne: 'inversedBy' must be a string or null");
			}
			
			if ($relationColumn !== null && !is_string($relationColumn)) {
				throw new \InvalidArgumentException("OneToOne: 'relationColumn' must be a string or null");
			}
			
			if ($foreignColumn !== null && !is_string($foreignColumn)) {
				throw new \InvalidArgumentException("OneToOne: 'foreignColumn' must be a string or null");
			}
			
			if (!is_string($fetch)) {
				throw new \InvalidArgumentException("OneToOne: 'fetch' must be a string");
			}
			
			$this->parameters = $parameters;
			$this->targetEntity = $targetEntity;
			$this->mappedBy = $mappedBy;
			$this->inversedBy = $inversedBy;
			$this->relationColumn = $relationColumn;
			$this->foreignColumn = $foreignColumn;
			$this->fetch = strtoupper($fetch);
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
			return $this->targetEntity;
		}
		
		/**
		 * Retrieve the target entity.
		 * @param string $targetEntity
		 * @return void The full namespace of the target entity.
		 */
		public function setTargetEntity(string $targetEntity): void {
			$this->targetEntity = $targetEntity;
			$this->parameters['targetEntity'] = $targetEntity;
		}
		
		/**
		 * Retrieves the 'mappedBy' parameter.
		 * @return string|null The value of the 'mappedBy' parameter or an empty string if it is not set.
		 */
		public function getMappedBy(): ?string {
			return $this->mappedBy;
		}
		
		/**
		 * Retrieves the 'inversedBy' parameter, if present.
		 * @return string|null The name of the field in the target entity that refers to the current entity, or null if it is not set.
		 */
		public function getInversedBy(): ?string {
			return $this->inversedBy;
		}
		
		/**
		 * Retrieves the name of the relationship column.
		 * This method retrieves the name of the column that represents the ManyToOne relationship in the database.
		 * @return string|null The name of the join column or null if it is not set.
		 */
		public function getRelationColumn(): ?string {
			return $this->relationColumn;
		}
		
		/**
		 * Retrieve the name of the relationship column in the target entity.
		 * @return string|null The name of the join column or null if it is not set.
		 */
		public function getForeignColumn(): ?string {
			return $this->foreignColumn;
		}
		
		/**
		 * Returns fetch method (default LAZY)
		 * @return string
		 */
		public function getFetch(): string {
			return $this->fetch;
		}
	}