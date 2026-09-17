<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	/**
	 * The optional `(limit)`, `(precision, scale)`, or `('value', ...)` suffix
	 * after a column's type name. `limit`, `precision`/`scale`, and
	 * `enumValues` are mutually exclusive — at most one form is populated,
	 * `enumValues` only when the type name was `enum`.
	 */
	final class ColumnTypeArguments {

		/**
		 * @param string[]|null $enumValues
		 */
		public function __construct(
			public readonly ?int $limit = null,
			public readonly ?int $precision = null,
			public readonly ?int $scale = null,
			public readonly ?array $enumValues = null,
		) {
		}
	}
