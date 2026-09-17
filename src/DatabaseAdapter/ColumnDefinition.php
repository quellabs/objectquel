<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter;

	/**
	 * A single column's schema definition — the value object every
	 * DatabaseAdapter::getColumns() implementation (see
	 * Inspector\SchemaIntrospectorInterface) returns, and the shape both
	 * sides of a schema diff (SchemaComparator::analyzeSchemaChanges())
	 * compare against each other. Built by whichever per-engine introspector
	 * read it off a live table, or by EntityMetadataRecord::
	 * getColumnDefinitionsForSchema() from an entity's own @Orm\Column
	 * annotations — both sides of every diff are this same type.
	 *
	 * toArray() exists for SchemaComparator's own normalization/diffing math,
	 * which is inherently dynamic — filtering "which properties matter for
	 * this abstract type" via TypeMapper::getRelevantProperties() is a
	 * data-driven operation that works naturally over a keyed array, not a
	 * general-purpose escape hatch for bypassing the object elsewhere.
	 */
	final readonly class ColumnDefinition {

		/** @var string Abstract column type, e.g. 'integer', 'string', 'enum' */
		public string $type;

		/** @var string Corresponding PHP type, e.g. 'int', 'string', '\DateTime' */
		public string $php_type;

		/** @var int|array<int, int>|null Maximum length/display width, or null if not applicable */
		public int|array|null $limit;

		/** @var mixed Default value for the column, or null if none */
		public mixed $default;

		/** @var bool Whether NULL values are allowed */
		public bool $nullable;

		/** @var int|null Total digit count, for numeric types */
		public ?int $precision;

		/** @var int|null Digits after the decimal point, for numeric types */
		public ?int $scale;

		/** @var bool Whether the column rejects negative values */
		public bool $unsigned;

		/** @var mixed Generated-column expression; currently always null */
		public mixed $generated;

		/** @var bool Whether the column auto-increments */
		public bool $identity;

		/** @var bool Whether the column is part of the primary key */
		public bool $primary_key;

		/** @var array<int, string>|null Enum case values, or null for non-enum columns */
		public ?array $values;

		/**
		 * @param string $type
		 * @param string $php_type
		 * @param int|array<int, int>|null $limit
		 * @param mixed $default
		 * @param bool $nullable
		 * @param int|null $precision
		 * @param int|null $scale
		 * @param bool $unsigned
		 * @param mixed $generated
		 * @param bool $identity
		 * @param bool $primary_key
		 * @param array<int, string>|null $values
		 */
		public function __construct(
			string $type,
			string $php_type,
			int|array|null $limit,
			mixed $default,
			bool $nullable,
			?int $precision,
			?int $scale,
			bool $unsigned,
			mixed $generated,
			bool $identity,
			bool $primary_key,
			?array $values
		) {
			$this->type = $type;
			$this->php_type = $php_type;
			$this->limit = $limit;
			$this->default = $default;
			$this->nullable = $nullable;
			$this->precision = $precision;
			$this->scale = $scale;
			$this->unsigned = $unsigned;
			$this->generated = $generated;
			$this->identity = $identity;
			$this->primary_key = $primary_key;
			$this->values = $values;
		}

		/**
		 * Converts this definition to a plain associative array, keyed by
		 * property name — for SchemaComparator's normalization pipeline only.
		 * @return array{type: string, php_type: string, limit: int|array<int, int>|null, default: mixed, nullable: bool, precision: int|null, scale: int|null, unsigned: bool, generated: mixed, identity: bool, primary_key: bool, values: array<int, string>|null}
		 */
		public function toArray(): array {
			return [
				'type'        => $this->type,
				'php_type'    => $this->php_type,
				'limit'       => $this->limit,
				'default'     => $this->default,
				'nullable'    => $this->nullable,
				'precision'   => $this->precision,
				'scale'       => $this->scale,
				'unsigned'    => $this->unsigned,
				'generated'   => $this->generated,
				'identity'    => $this->identity,
				'primary_key' => $this->primary_key,
				'values'      => $this->values,
			];
		}
	}
