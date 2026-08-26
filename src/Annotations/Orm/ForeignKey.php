<?php

	namespace Quellabs\ObjectQuel\Annotations\Orm;

	use Quellabs\AnnotationReader\AnnotationInterface;

	/**
	 * @Annotation
	 * Declares a real database-level foreign key constraint for a column.
	 *
	 * Usable directly on a plain @Orm\Column-backed scalar property (no ManyToOne/OneToOne
	 * required), or stacked alongside a ManyToOne/OneToOne annotation on the same property
	 * for entities that also model the relation as an object graph.
	 *
	 * Example: @Orm\ForeignKey(target=UserEntity::class, referencedColumn="id", onDelete="RESTRICT")
	 */
	class ForeignKey implements AnnotationInterface {

		/**
		 * Contains all parameters defined in the annotation
		 * @var array<string, mixed>
		 */
		protected array $parameters;

		/** @var array<int, string> Valid values for onDelete/onUpdate */
		private const array VALID_ACTIONS = ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION'];

		private string $target;
		private ?string $referencedColumn;
		private string $onDelete;
		private string $onUpdate;

		/**
		 * ForeignKey constructor.
		 * @param array<string, mixed> $parameters Array of parameters from the annotation
		 * @throws \InvalidArgumentException
		 */
		public function __construct(array $parameters) {
			$target = $parameters['target'] ?? null;
			$referencedColumn = $parameters['referencedColumn'] ?? null;
			$onDelete = $parameters['onDelete'] ?? 'RESTRICT';
			$onUpdate = $parameters['onUpdate'] ?? 'NO ACTION';

			if (!is_string($target) || $target === '') {
				throw new \InvalidArgumentException("ForeignKey: 'target' must be a non-empty string");
			}

			if ($referencedColumn !== null && !is_string($referencedColumn)) {
				throw new \InvalidArgumentException("ForeignKey: 'referencedColumn' must be a string or null");
			}

			if (!is_string($onDelete) || !in_array($onDelete, self::VALID_ACTIONS, true)) {
				throw new \InvalidArgumentException(
					"ForeignKey: 'onDelete' must be one of: " . implode(', ', self::VALID_ACTIONS)
				);
			}

			if (!is_string($onUpdate) || !in_array($onUpdate, self::VALID_ACTIONS, true)) {
				throw new \InvalidArgumentException(
					"ForeignKey: 'onUpdate' must be one of: " . implode(', ', self::VALID_ACTIONS)
				);
			}

			$this->parameters = $parameters;
			$this->target = $target;
			$this->referencedColumn = $referencedColumn;
			$this->onDelete = $onDelete;
			$this->onUpdate = $onUpdate;
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
		 * @return string The fully qualified class name of the referenced entity.
		 */
		public function getTarget(): string {
			return $this->target;
		}

		/**
		 * Overwrites the target entity, e.g. after resolving a short class name to its
		 * fully qualified form. Mirrors ManyToOne::setTargetEntity().
		 * @param string $target
		 * @return void
		 */
		public function setTarget(string $target): void {
			$this->target = $target;
			$this->parameters['target'] = $target;
		}

		/**
		 * Retrieves the 'referencedColumn' parameter, if present.
		 * @return string|null The column on the target entity this key points to,
		 *                     or null when it should default to the target's primary key.
		 */
		public function getReferencedColumn(): ?string {
			return $this->referencedColumn;
		}

		/**
		 * Returns the ON DELETE action (default: "RESTRICT")
		 * @return string One of: RESTRICT, CASCADE, SET NULL, NO ACTION
		 */
		public function getOnDelete(): string {
			return $this->onDelete;
		}

		/**
		 * Returns the ON UPDATE action (default: "NO ACTION")
		 * @return string One of: RESTRICT, CASCADE, SET NULL, NO ACTION
		 */
		public function getOnUpdate(): string {
			return $this->onUpdate;
		}
	}
