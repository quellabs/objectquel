<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	/**
	 * The `nullable`/`identity` constraint keywords following a column's type.
	 * See ColumnDefinitionClause::parseColumnConstraints() for why columns are
	 * NOT NULL by default.
	 */
	final class ColumnConstraints {

		public function __construct(
			public readonly bool $nullable = false,
			public readonly bool $identity = false,
		) {
		}
	}
