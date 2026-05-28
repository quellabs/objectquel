<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	
	/**
	 * @Annotation
	 *
	 * Marks a property as receiving its value from an external source range during hydration.
	 *
	 * When a query joins a database entity against an external source range (json_source(), etc.),
	 * ObjectQuel can automatically write matching fields directly onto the entity rather than
	 * surfacing them as separate scalar columns in the result row.
	 *
	 * Usage (explicit range — required when multiple JSON sources are present):
	 * @SourceField(field="name", range="product")
	 *
	 * Usage (implicit range — only valid when exactly one JSON source is in the query):
	 * @SourceField(field="name")
	 *
	 * Rules:
	 *  - If `range` is omitted and exactly one JSON range exists in the row, that range
	 *    is used automatically.
	 *  - If `range` is omitted and multiple JSON ranges exist, a LogicException is thrown
	 *    instructing the developer to add an explicit range.
	 *  - If the named range is not present in the current query row, no action is taken.
	 *
	 * @package Quellabs\ObjectQuel\Annotations\Orm
	 */
	class SourceField implements AnnotationInterface {
		
		/** @var array<string, mixed> */
		protected array $parameters;
		
		/** @var string Source Field */
		private string $field;
		
		/** @var string|null Source range */
		private ?string $range;
		
		/**
		 * SourceField constructor.
		 * @param array<string, mixed> $parameters Recognised keys: 'field' (required), 'range' (optional).
		 * @throws \InvalidArgumentException When 'field' is absent or not a string.
		 */
		public function __construct(array $parameters) {
			$field = $parameters['field'] ?? null;
			$range = $parameters['range'] ?? null;
			
			if (!is_string($field) || $field === '') {
				throw new \InvalidArgumentException(
					'SourceField annotation requires a non-empty "field" parameter'
				);
			}
			
			if ($range !== null && !is_string($range)) {
				throw new \InvalidArgumentException(
					'SourceField: "range" must be a string'
				);
			}
			
			$this->parameters = $parameters;
			$this->field = $field;
			$this->range = $range;
		}
		
		/** @return array<string, mixed> */
		public function getParameters(): array {
			return $this->parameters;
		}
		
		/**
		 * Returns the field name to read from the external source row.
		 * This is the key as it appears inside the source data (without any range prefix).
		 * @return string
		 */
		public function getField(): string {
			return $this->field;
		}
		
		/**
		 * Returns the explicit range alias this field should be sourced from, or null
		 * when the range is to be inferred automatically from the query context.
		 * @return string|null
		 */
		public function getRange(): ?string {
			return $this->range;
		}
	}