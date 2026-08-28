<?php

	namespace Quellabs\ObjectQuel\Annotations\Orm;

	use Quellabs\AnnotationReader\AnnotationInterface;

	/**
	 * @Annotation
	 * Declares ORM-side (PHP) cascading behavior for a relation: whether
	 * persisting/removing an entity also persists/removes a related one,
	 * walked in PHP by UnitOfWork.
	 *
	 * Deliberately independent of any database-level foreign key constraint —
	 * @Orm\ForeignKey/@Orm\ForeignKeyAction configure what the database itself
	 * does and know nothing about this annotation, the way Doctrine's own
	 * `cascade` option is unrelated to its `@JoinColumn(onDelete=...)`.
	 *
	 * Valid on ManyToOne/OneToOne (the owning side) for both "remove" and
	 * "persist" — cascade-remove is discovered by querying the dependent
	 * entity's FK column directly, so it never needs a loaded collection.
	 * Also valid on InverseOf, but for "persist" only: a new, not-yet-saved
	 * child only exists in the parent's in-memory InverseOf collection, so
	 * that's the only property cascade-persist can walk to find it there.
	 * "remove" on InverseOf is rejected at metadata-build time, since nothing
	 * reads it and it would silently do nothing.
	 *
	 * Example: @Orm\Cascade(operations={"remove"})
	 */
	class Cascade implements AnnotationInterface {

		/**
		 * Contains all parameters defined in the annotation
		 * Example: @Orm\Cascade(operations={"remove"})
		 * @var array<string, mixed>
		 */
		protected array $parameters;

		/** @var array<int, string> */
		private array $operations;

		/**
		 * Cascade constructor.
		 * @param array<string, mixed> $parameters Array of parameters from the annotation
		 * @throws \InvalidArgumentException
		 */
		public function __construct(array $parameters) {
			$operations = $parameters['operations'] ?? [];

			if (!is_array($operations)) {
				$operations = [];
			}

			$this->parameters = $parameters;
			$this->operations = array_values(array_filter($operations, 'is_string'));
		}

		/**
		 * Returns the parameters for this annotation
		 * @return array<string, mixed>
		 */
		public function getParameters(): array {
			return $this->parameters;
		}

		/**
		 * Get the operations that should cascade
		 *
		 * Possible values include:
		 * - "remove": Cascade deletion
		 * - "persist": Cascade persistence
		 * @return array<int, string> List of operations to cascade
		 */
		public function getOperations(): array {
			return $this->operations;
		}
	}
