<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * @Annotation
	 * Defines the ManyToOne class that describes the relationship between entities
	 */
	class ManyToOne implements AnnotationInterface {
		
		// Contains parameters that provide additional information about the relationship
		/** @var array<string, mixed> */
		protected array $parameters;
		
		private string $targetEntity;
		private ?string $referencedColumn;
		private ?string $localColumn;
		/** @var list<string> */
		private array $localColumns;
		/** @var list<string> */
		private array $referencedColumns;
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
			$localColumns = $parameters['localColumns'] ?? null;
			$referencedColumns = $parameters['referencedColumns'] ?? null;
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

			AnnotationColumnArrays::validate('ManyToOne', $localColumns, $referencedColumns, $localColumn, $referencedColumn);

			if (!is_string($fetch)) {
				throw new \InvalidArgumentException("ManyToOne: 'fetch' must be a string");
			}

			$this->parameters = $parameters;
			$this->targetEntity = $targetEntity;
			$this->referencedColumn = $referencedColumn;
			$this->localColumn = $localColumn;
			$this->localColumns = AnnotationColumnArrays::normalizeList($localColumns);
			$this->referencedColumns = AnnotationColumnArrays::normalizeList($referencedColumns);
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
		 * @return string|null The target entity's scalar @Column-backed property that the
		 *                     local FK column points to, or null to fall back to the target's primary key.
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
		 * Ordered list of local property names for a composite (multi-column) relation,
		 * paired by index with getReferencedColumns(). Empty when the relation uses the
		 * singular localColumn/referencedColumn form instead.
		 * @return list<string>
		 */
		public function getLocalColumns(): array {
			return $this->localColumns;
		}

		/**
		 * Ordered list of target property names for a composite (multi-column) relation,
		 * paired by index with getLocalColumns().
		 * @return list<string>
		 */
		public function getReferencedColumns(): array {
			return $this->referencedColumns;
		}

		/**
		 * True when this relation was declared with the plural localColumns/referencedColumns
		 * form (a multi-column relation), false for the singular single-column form.
		 * @return bool
		 */
		public function hasCompositeColumns(): bool {
			return $this->localColumns !== [];
		}

		/**
		 * Returns fetch method (default EAGER)
		 * @return string
		 */
		public function getFetch(): string {
			return $this->fetch;
		}
	}