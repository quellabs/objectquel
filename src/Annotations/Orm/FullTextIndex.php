<?php

	namespace Quellabs\ObjectQuel\Annotations\Orm;

	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;

	/**
	 * @Annotation
	 *
	 * Class FullTextIndex
	 *
	 * Represents a database full-text index annotation that can be applied to entity classes.
	 * Full-text indexes enable the search() function to use MATCH...AGAINST queries instead
	 * of falling back to LIKE chains, providing relevance ranking and better performance on
	 * large text columns.
	 *
	 * When all columns passed to search() or search_score() are covered by a single
	 * FullTextIndex, ObjectQuel automatically uses MATCH...AGAINST IN BOOLEAN MODE.
	 * Otherwise it falls back to LIKE-based matching.
	 *
	 * Usage example:
	 * @Orm\FullTextIndex(name="idx_product_search", columns={"name", "description"})
	 *
	 * The columns must match the property names defined on the entity, not the database
	 * column names. ObjectQuel resolves the mapping internally.
	 *
	 * @package Quellabs\ObjectQuel\Annotations\Orm
	 */
	#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
	class FullTextIndex implements AnnotationInterface {
		use ReflectsAnnotationParameters;


		/**
		 * @var string Index name
		 */
		private string $name;

		/**
		 * @var array<int, string>
		 */
		private array $columns;

		/**
		 * Constructs the FullTextIndex annotation.
		 * @param string $name
		 * @param array<mixed> $columns
		 * @return void
		 */
		public function __construct(string $name, array $columns) {
			if ($name === '') {
				throw new \InvalidArgumentException("FullTextIndex annotation requires a valid 'name' parameter");
			}

			if ($columns === []) {
				throw new \InvalidArgumentException("FullTextIndex annotation requires a non-empty 'columns' array");
			}

			$this->name = $name;
			$this->columns = array_values(array_filter($columns, 'is_string'));

		}





		/**
		 * Returns the name of the full-text index
		 * @return string The index name
		 */
		public function getName(): string {
			return $this->name;
		}

		/**
		 * Returns the columns covered by this full-text index.
		 * These are property names on the entity, not database column names.
		 * @return array<int, string> List of property names included in the full-text index
		 */
		public function getColumns(): array {
			return $this->columns;
		}
	}
