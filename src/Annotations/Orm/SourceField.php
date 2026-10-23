<?php
	
	namespace Quellabs\ObjectQuel\Annotations\Orm;
	
	use Quellabs\AnnotationReader\AnnotationInterface;
	use Quellabs\AnnotationReader\Annotation\ReflectsAnnotationParameters;
	
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
	#[\Attribute(\Attribute::TARGET_PROPERTY)]
	class SourceField implements AnnotationInterface {
		use ReflectsAnnotationParameters;
		
		/**
		 * @var string Source Field
		 */
		private string $field;
		
		/**
		 * @var string|null Source range
		 */
		private ?string $range;
		
		/**
		 * Constructs the SourceField annotation.
		 * @param string $field
		 * @param ?string $range
		 * @return void
		 */
		public function __construct(string $field, ?string $range = null) {
			if ($field === '') {
				throw new \InvalidArgumentException(
					'SourceField annotation requires a non-empty "field" parameter'
				);
			}
			
			$this->field = $field;
			$this->range = $range;
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
