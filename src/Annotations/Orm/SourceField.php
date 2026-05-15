<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * Marks a property as receiving its value from an external source range during hydration.
	 *
	 * When a query joins a database entity against an external source range (json_source(), csv_source(), etc.), ObjectQuel
	 * can automatically write matching fields directly onto the entity rather than
	 * surfacing them as separate scalar columns in the result row.
	 *
	 * Usage (explicit range — required when multiple JSON sources are present):
	 *   @SourceField(field="name", range="product")
	 *
	 * Usage (implicit range — only valid when exactly one JSON source is in the query):
	 *   @SourceField(field="name")
	 *
	 * Rules:
	 *  - If `range` is omitted and exactly one JSON range exists in the row, that range
	 *    is used automatically.
	 *  - If `range` is omitted and multiple JSON ranges exist, a SemanticException is thrown
	 *    instructing the developer to add an explicit range.
	 *  - If the named range is not present in the current query row, no action is taken
	 *    (the property retains its default value).
	 *
	 * @package Quellabs\ObjectQuel\Annotations\Orm
	 */
	class SourceField implements AnnotationInterface {
		
		/**
		 * All parameters passed to this annotation
		 * @var array<string, mixed>
		 */
		protected array $parameters;
		
		/**
		 * SourceField constructor.
		 * @param array<string, mixed> $parameters Associative array of annotation parameters.
		 *                                          Recognised keys: 'field' (required), 'range' (optional).
		 * @throws \InvalidArgumentException When the required 'field' parameter is absent.
		 */
		public function __construct(array $parameters) {
			$this->parameters = $parameters;
			
			// 'field' is mandatory — without it we cannot know which source key to read
			if (empty($this->parameters['field'])) {
				throw new \InvalidArgumentException('SourceField annotation requires a "field" parameter');
			}
		}
		
		/**
		 * Returns all parameters supplied to this annotation.
		 * @return array<string, mixed>
		 */
		public function getParameters(): array {
			return $this->parameters;
		}
		
		/**
		 * Returns the field name to read from the external source row.
		 * This is the key as it appears inside the source data (without any range prefix).
		 * @return string
		 */
		public function getField(): string {
			return $this->parameters['field'];
		}
		
		/**
		 * Returns the explicit range alias this field should be sourced from, or null
		 * when the range is to be inferred automatically from the query context.
		 * @return string|null The range alias (e.g. 'product'), or null if not specified.
		 */
		public function getRange(): ?string {
			return $this->parameters['range'] ?? null;
		}
	}