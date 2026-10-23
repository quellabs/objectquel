<?php

	namespace Quellabs\ObjectQuel\Annotations\Orm;

	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;

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
	#[\Attribute(\Attribute::TARGET_PROPERTY)]
	class Cascade implements AnnotationInterface {
		use ReflectsAnnotationParameters;


		/**
		 * @var array<int, string>
		 */
		private array $operations;

		/**
		 * Constructs the Cascade annotation.
		 * @param array<mixed>|null $operations
		 * @return void
		 */
		public function __construct(?array $operations = null) {
			$this->operations = array_values(array_filter($operations ?? [], 'is_string'));
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
