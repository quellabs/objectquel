<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	/**
	 * The optional `(limit)`, `(precision, scale)`, or `('value', ...)` suffix
	 * after a column's type name. `limit`, `precision`/`scale`, and
	 * `enumValues` are mutually exclusive — at most one form is populated,
	 * `enumValues` only when the type name was `enum`.
	 */
	final class ColumnTypeArguments {

		public readonly ?int $limit;
		public readonly ?int $precision;
		public readonly ?int $scale;
		/** @var string[]|null */
		public readonly ?array $enumValues;

		/**
		 * @param string[]|null $enumValues
		 */
		public function __construct(?int $limit = null, ?int $precision = null, ?int $scale = null, ?array $enumValues = null) {
			$this->limit = $limit;
			$this->precision = $precision;
			$this->scale = $scale;
			$this->enumValues = $enumValues;
		}
	}
