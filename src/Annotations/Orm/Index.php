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
		 * @var array<string, mixed>
		 */
		protected array $parameters;
		
		private string $name;
		
		/** @var array<int, string> */
		private array $columns;
		
		/**
		 * Index constructor.
		 * @param array<string, mixed> $parameters Array of parameters from the annotation
		 * @throws \InvalidArgumentException
		 */
		public function __construct(array $parameters) {
			if (!isset($parameters['name']) || !is_string($parameters['name'])) {
				throw new \InvalidArgumentException("Index annotation requires a valid 'name' parameter");
			}
			
			if (!is_array($parameters['columns']) || empty($parameters['columns'])) {
				throw new \InvalidArgumentException("Index annotation requires a non-empty 'columns' array");
			}
			
			$this->parameters = $parameters;
			$this->name = $parameters['name'];
			$this->columns = array_values(array_filter($parameters['columns'], 'is_string'));
		}
		
		/**
		 * Returns all parameters for this index annotation
		 * @return array<string, mixed> All parameters defined in the annotation
		 */
		public function getParameters(): array {
			return $this->parameters;
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