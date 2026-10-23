<?php

	namespace Quellabs\ObjectQuel\Annotations\Orm;

	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;

	/**
	 * @Annotation
	 *
	 * Class UniqueIndex
	 *
	 * Represents a database unique index annotation that can be applied to entity classes.
	 * This class handles the parsing and retrieval of unique index configuration from annotations.
	 * Unique indices enforce that the combination of values in the specified columns must be unique
	 * across all records in the table.
	 *
	 * Usage example:
	 * @Orm\UniqueIndex(name="uniq_product_sku", columns={"sku"})
	 * @Orm\UniqueIndex(name="uniq_user_email_domain", columns={"email", "domain"})
	 *
	 * @package Quellabs\ObjectQuel\Annotations\Orm
	 */
	#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
	class UniqueIndex implements AnnotationInterface {
		use ReflectsAnnotationParameters;


		/**
		 * @var string Index name
		 */
		protected string $name;

		/**
		 * @var array<string>
		 */
		protected array $columns;

		/**
		 * Constructs the UniqueIndex annotation.
		 * @param string $name
		 * @param array<mixed> $columns
		 * @return void
		 */
		public function __construct(string $name, array $columns) {
			if ($name === '') {
				throw new \InvalidArgumentException("UniqueIndex annotation requires a valid 'name' parameter");
			}

			if ($columns === []) {
				throw new \InvalidArgumentException("UniqueIndex annotation requires a non-empty 'columns' array");
			}

			$this->name = $name;
			$this->columns = array_values(array_filter($columns, 'is_string'));

		}





		/**
		 * Returns the name of the unique index
		 * @return string The unique index name
		 */
		public function getName(): string {
			return $this->name;
		}

		/**
		 * Returns the columns to create a unique index on
		 * @return array<int, string> List of column names to be uniquely indexed
		 */
		public function getColumns(): array {
			return $this->columns;
		}
	}
