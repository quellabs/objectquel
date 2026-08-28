<?php

	namespace Quellabs\ObjectQuel\Annotations\Orm;

	use Quellabs\AnnotationReader\AnnotationInterface;

	/**
	 * @Annotation
	 * Declares ORM-side (PHP) cascading behavior for a relation: whether
	 * persisting/removing an entity also persists/removes a related one,
	 * walked in PHP by UnitOfWork. Independent of any database-level FK
	 * constraint — @Orm\ForeignKey/@Orm\ForeignKeyAction configure the
	 * database itself and know nothing about this annotation.
	 *
	 * Valid on ManyToOne/OneToOne for "remove" and "persist". Valid on
	 * InverseOf for "persist" only: cascade-remove is discovered via a DB
	 * query on the dependent's FK column, so it never reads Cascade off
	 * InverseOf — declaring "remove" there is rejected at build time.
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
