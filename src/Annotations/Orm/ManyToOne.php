<?php

	namespace Quellabs\ObjectQuel\Annotations\Orm;

	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;

	/**
	 * @Annotation
	 * Defines the ManyToOne class that describes the relationship between entities
	 */
	#[\Attribute(\Attribute::TARGET_PROPERTY)]
	class ManyToOne implements AnnotationInterface {
		use ReflectsAnnotationParameters;


		private string $targetEntity;
		private ?string $referencedColumn;
		private ?string $localColumn;
		/**
		 * @var list<string>
		 */
		private ?array $localColumns;
		/**
		 * @var list<string>
		 */
		private ?array $referencedColumns;
		private string $fetch;

		/**
		 * Constructs the ManyToOne annotation.
		 * @param string $targetEntity
		 * @param ?string $referencedColumn
		 * @param ?string $localColumn
		 * @param array<mixed>|null $localColumns
		 * @param array<mixed>|null $referencedColumns
		 * @param string $fetch
		 * @return void
		 */
		public function __construct(string $targetEntity, ?string $referencedColumn = null, ?string $localColumn = null, ?array $localColumns = null, ?array $referencedColumns = null, string $fetch = 'EAGER') {
			AnnotationColumnArrays::validate('ManyToOne', $localColumns, $referencedColumns, $localColumn, $referencedColumn);
			$this->targetEntity = $targetEntity;
			$this->referencedColumn = $referencedColumn;
			$this->localColumn = $localColumn;
			$this->localColumns = $localColumns === null ? null : AnnotationColumnArrays::normalizeList($localColumns);
			$this->referencedColumns = $referencedColumns === null ? null : AnnotationColumnArrays::normalizeList($referencedColumns);
			$this->fetch = strtoupper($fetch);

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
			return $this->localColumns ?? [];
		}

		/**
		 * Ordered list of target property names for a composite (multi-column) relation,
		 * paired by index with getLocalColumns().
		 * @return list<string>
		 */
		public function getReferencedColumns(): array {
			return $this->referencedColumns ?? [];
		}

		/**
		 * True when this relation was declared with the plural localColumns/referencedColumns
		 * form (a multi-column relation), false for the singular single-column form.
		 * @return bool
		 */
		public function hasCompositeColumns(): bool {
			return $this->localColumns !== null && $this->localColumns !== [];
		}

		/**
		 * Returns fetch method (default EAGER)
		 * @return string
		 */
		public function getFetch(): string {
			return $this->fetch;
		}
	}
