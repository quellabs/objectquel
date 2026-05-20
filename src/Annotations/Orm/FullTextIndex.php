<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
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
	class FullTextIndex implements AnnotationInterface {
		
		/**
		 * Contains all parameters defined in the full-text index annotation
		 * @var array<string, mixed>
		 */
		protected array $parameters;
		
		/** @var string Index name */
		private string $name;
		
		/** @var array<int, string> */
		private array $columns;
		
		/**
		 * FullTextIndex constructor.
		 * @param array<string, mixed> $parameters Array of parameters from the annotation
		 * @throws \InvalidArgumentException
		 */
		public function __construct(array $parameters) {
			if (!isset($parameters['name']) || !is_string($parameters['name'])) {
				throw new \InvalidArgumentException("FullTextIndex annotation requires a valid 'name' parameter");
			}
			
			if (!is_array($parameters['columns']) || empty($parameters['columns'])) {
				throw new \InvalidArgumentException("FullTextIndex annotation requires a non-empty 'columns' array");
			}
			
			$this->parameters = $parameters;
			$this->name = $parameters['name'];
			$this->columns = array_values(array_filter($parameters['columns'], 'is_string'));
		}
		
		/**
		 * Returns all parameters for this full-text index annotation
		 * @return array<string, mixed> All parameters defined in the annotation
		 */
		public function getParameters(): array {
			return $this->parameters;
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