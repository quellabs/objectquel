<?php

	namespace Quellabs\ObjectQuel\Annotations\Orm;

	use Quellabs\AnnotationReader\AnnotationInterface;

	/**
	 * @Annotation
	 * Declares ORM-side (PHP) cascading behavior for a ManyToOne/OneToOne relation:
	 * whether persisting/removing the owning entity also persists/removes the
	 * related entity, walked in PHP by UnitOfWork.
	 *
	 * Deliberately independent of any database-level foreign key constraint —
	 * @Orm\ForeignKey/@Orm\ForeignKeyAction configure what the database itself
	 * does and know nothing about this annotation, the way Doctrine's own
	 * `cascade` option is unrelated to its `@JoinColumn(onDelete=...)`. Only
	 * meaningful alongside a ManyToOne/OneToOne — there's no PHP-side object
	 * graph to walk on a plain scalar column.
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
