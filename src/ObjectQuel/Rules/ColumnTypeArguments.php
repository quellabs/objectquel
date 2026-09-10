<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	/**
	 * The optional `(limit)` or `(precision, scale)` suffix after a column's
	 * type name. `limit` and `precision`/`scale` are mutually exclusive —
	 * exactly one of `limit` or `precision` is non-null, never both.
	 */
	final class ColumnTypeArguments {

		public function __construct(
			public readonly ?int $limit = null,
			public readonly ?int $precision = null,
			public readonly ?int $scale = null,
		) {
		}
	}
