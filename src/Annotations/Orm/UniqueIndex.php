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
		 * @var array
		 */
		protected array $parameters;
		
		/**
		 * UniqueIndex constructor.
		 * @param array $parameters Array of parameters from the annotation
		 */
		public function __construct(array $parameters) {
			$this->parameters = $parameters;
		}
		
		/**
		 * Returns all parameters for this unique index annotation
		 * @return array All parameters defined in the annotation
		 */
		public function getParameters(): array {
			return $this->parameters;
		}
		
		/**
		 * Returns the name of the unique index
		 * @return string The unique index name or empty string if not defined
		 */
		public function getName(): string {
			return $this->parameters['name'] ?? '';
		}
		
		/**
		 * Returns the columns to create a unique index on
		 * @return array List of column names to be uniquely indexed
		 */
		public function getColumns(): array {
			return $this->parameters['columns'] ?? [];
		}
	}