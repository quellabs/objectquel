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
		private ?string $referencedColumn;
		private ?string $localColumn;
		private string $fetch;
		
		/**
		 * Constructor to initialize the parameters.
		 * @param array<string, mixed> $parameters Array with parameters that describe the relationship.
		 * @throws \InvalidArgumentException
		 */
		public function __construct(array $parameters) {
			$targetEntity = $parameters['targetEntity'] ?? null;
			$referencedColumn = $parameters['referencedColumn'] ?? null;
			$localColumn = $parameters['localColumn'] ?? null;
			$fetch = $parameters['fetch'] ?? 'EAGER';
			
			if (!is_string($targetEntity)) {
				throw new \InvalidArgumentException("ManyToOne: 'targetEntity' must be a string");
			}
			
			if ($referencedColumn !== null && !is_string($referencedColumn)) {
				throw new \InvalidArgumentException("ManyToOne: 'referencedColumn' must be a string or null");
			}
			
			if ($localColumn !== null && !is_string($localColumn)) {
				throw new \InvalidArgumentException("ManyToOne: 'localColumn' must be a string or null");
			}
			
			if (!is_string($fetch)) {
				throw new \InvalidArgumentException("ManyToOne: 'fetch' must be a string");
			}
			
			$this->parameters = $parameters;
			$this->targetEntity = $targetEntity;
			$this->referencedColumn = $referencedColumn;
			$this->localColumn = $localColumn;
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
		 * @return void
		 */
		public function setTargetEntity(string $targetEntity): void {
			$this->targetEntity = $targetEntity;
			$this->parameters['targetEntity'] = $targetEntity;
		}
		
		/**
		 * Retrieves the 'referencedColumn' parameter, if present.
		 * @return string|null The property in the target entity that refers to the current
		 *                     entity, or null if it is not set.
		 */
		public function getReferencedColumn(): ?string {
			return $this->referencedColumn;
		}
		
		/**
		 * Retrieve the name of the relationship column in the current entity.
		 * This method retrieves the name of the column that represents the ManyToOne relationship in the database.
		 * @return string|null The name of the join column or null if it is not set.
		 */
		public function getLocalColumn(): ?string {
			return $this->localColumn;
		}
		
		/**
		 * Returns the fetch mode (EAGER or LAZY, default LAZY).
		 * @return string
		 */
		public function getFetch(): string {
			return $this->fetch;
		}
	}