<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
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
	class UniqueIndex implements AnnotationInterface {
		
		/**
		 * Contains all parameters defined in the unique index annotation
		 * @var array<string, mixed>
		 */
		protected array $parameters;
		
		/** @var string Index name */
		protected string $name;
		
		/** @var array<string> */
		protected array $columns;
		
		/**
		 * UniqueIndex constructor.
		 * @param array<string, mixed> $parameters Array of parameters from the annotation
		 * @throws \InvalidArgumentException
		 */
		public function __construct(array $parameters) {
			if (!isset($parameters['name']) || !is_string($parameters['name'])) {
				throw new \InvalidArgumentException("UniqueIndex annotation requires a valid 'name' parameter");
			}
			
			if (!is_array($parameters['columns']) || empty($parameters['columns'])) {
				throw new \InvalidArgumentException("UniqueIndex annotation requires a non-empty 'columns' array");
			}
			
			$this->parameters = $parameters;
			$this->name = $parameters['name'];
			$this->columns = array_values(array_filter($parameters['columns'], 'is_string'));
		}
		
		/**
		 * Returns all parameters for this unique index annotation
		 * @return array<string, mixed> All parameters defined in the annotation
		 */
		public function getParameters(): array {
			return $this->parameters;
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