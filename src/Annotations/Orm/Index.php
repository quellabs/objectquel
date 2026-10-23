<?php

	namespace Quellabs\ObjectQuel\Annotations\Orm;

	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;

	/**
	 * @Annotation
	 *
	 * Class Index
	 *
	 * Represents a database index annotation that can be applied to entity classes
	 * This class handles the parsing and retrieval of index configuration from annotations.
	 *
	 * Usage example:
	 * @Orm\Index(name="product_sku", columns={"sku"})
	 * @Orm\Index(name="user_email_domain", columns={"email", "domain"})
	 *
	 * @package Quellabs\ObjectQuel\Annotations\Orm
	 */
	#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
	class Index implements AnnotationInterface {
		use ReflectsAnnotationParameters;


		private string $name;

		/**
		 * @var array<int, string>
		 */
		private array $columns;

		/**
		 * Constructs the Index annotation.
		 * @param string $name
		 * @param array<mixed> $columns
		 * @return void
		 */
		public function __construct(string $name, array $columns) {
			if ($name === '') {
				throw new \InvalidArgumentException("Index annotation requires a valid 'name' parameter");
			}

			if ($columns === []) {
				throw new \InvalidArgumentException("Index annotation requires a non-empty 'columns' array");
			}

			$this->name = $name;
			$this->columns = array_values(array_filter($columns, 'is_string'));

		}





		/**
		 * Returns the name of the index
		 * @return string The index name
		 */
		public function getName(): string {
			return $this->name;
		}

		/**
		 * Returns the columns to create an index on
		 * @return array<int, string> List of column names to be indexed
		 */
		public function getColumns(): array {
			return $this->columns;
		}
	}
