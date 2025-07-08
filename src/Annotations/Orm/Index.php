<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
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
	class Index implements AnnotationInterface {
		
		/**
		 * Contains all parameters defined in the index annotation
		 * @var array
		 */
		protected array $parameters;
		
		/**
		 * Index constructor.
		 * @param array $parameters Array of parameters from the annotation
		 */
		public function __construct(array $parameters) {
			$this->parameters = $parameters;
		}
		
		/**
		 * Returns all parameters for this index annotation
		 * @return array All parameters defined in the annotation
		 */
		public function getParameters(): array {
			return $this->parameters;
		}
		
		/**
		 * Returns the name of the index
		 * @return string The index name or empty string if not defined
		 */
		public function getName(): string {
			return $this->parameters['name'] ?? '';
		}
		
		/**
		 * Returns the columns to create an index on
		 * @return array List of column names to be indexed
		 */
		public function getColumns(): array {
			return $this->parameters['columns'] ?? [];
		}
	}