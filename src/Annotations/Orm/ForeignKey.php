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
	 * Purely structural — target and referencedColumn only. The constraint's ON DELETE/ON
	 * UPDATE behavior is a separate concern, declared via @Orm\ForeignKeyAction on the same
	 * property when a non-default action is needed.
	 *
	 * Example: @Orm\ForeignKey(target=UserEntity::class, referencedColumn="id")
	 */
	class ForeignKey implements AnnotationInterface {

		/**
		 * Contains all parameters defined in the annotation
		 * @var array<string, mixed>
		 */
		protected array $parameters;

		private string $target;
		private ?string $referencedColumn;

		/**
		 * ForeignKey constructor.
		 * @param array<string, mixed> $parameters Array of parameters from the annotation
		 * @throws \InvalidArgumentException
		 */
		public function __construct(array $parameters) {
			$target = $parameters['target'] ?? null;
			$referencedColumn = $parameters['referencedColumn'] ?? null;

			if (!is_string($target) || $target === '') {
				throw new \InvalidArgumentException("ForeignKey: 'target' must be a non-empty string");
			}

			if ($referencedColumn !== null && !is_string($referencedColumn)) {
				throw new \InvalidArgumentException("ForeignKey: 'referencedColumn' must be a string or null");
			}

			$this->parameters = $parameters;
			$this->target = $target;
			$this->referencedColumn = $referencedColumn;
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
	}
