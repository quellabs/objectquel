<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * @Annotation
	 * Defines an owning-side OneToOne relationship between entities.
	 *
	 * This annotation is used exclusively on the owning side — the entity that holds
	 * the foreign key column. The non-owning side is declared with @InverseOf, which
	 * is a hydration instruction only and does not participate in join generation.
	 */
	class OneToOne implements AnnotationInterface {
		
		// Contains parameters that provide additional information about the relationship
		/** @var array<string, mixed> */
		protected array $parameters;
		
		private string $targetEntity;
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
			$inversedBy = $parameters['inversedBy'] ?? null;
			$relationColumn = $parameters['relationColumn'] ?? null;
			$foreignColumn = $parameters['foreignColumn'] ?? null;
			$fetch = $parameters['fetch'] ?? 'LAZY';
			
			if (!is_string($targetEntity)) {
				throw new \InvalidArgumentException("OneToOne: 'targetEntity' must be a string");
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
		 * Sets the target entity.
		 * @param string $targetEntity The full namespace of the target entity.
		 * @return void
		 */
		public function setTargetEntity(string $targetEntity): void {
			$this->targetEntity = $targetEntity;
			$this->parameters['targetEntity'] = $targetEntity;
		}
		
		/**
		 * Retrieves the 'inversedBy' parameter.
		 * This is the primary key property on the target entity that the foreign key
		 * column points to — used by the normalizer to resolve the join condition.
		 * @return string|null The primary key property name on the target entity, or null if not set.
		 */
		public function getInversedBy(): ?string {
			return $this->inversedBy;
		}
		
		/**
		 * Retrieves the name of the relationship column.
		 * This is the foreign key column on the owning entity side.
		 * @return string|null The name of the join column or null if not set.
		 */
		public function getRelationColumn(): ?string {
			return $this->relationColumn;
		}
		
		/**
		 * Retrieves the name of the referenced column on the target entity.
		 * @return string|null The name of the referenced column or null if not set.
		 */
		public function getForeignColumn(): ?string {
			return $this->foreignColumn;
		}
		
		/**
		 * Returns the fetch mode (EAGER or LAZY, default LAZY).
		 * @return string
		 */
		public function getFetch(): string {
			return $this->fetch;
		}
	}