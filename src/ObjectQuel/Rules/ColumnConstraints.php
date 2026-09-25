<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Rules;

	/**
	 * The `nullable`/`identity` constraint keywords following a column's type.
	 * See ColumnDefinitionClause::parseColumnConstraints() for why columns are
	 * NOT NULL by default.
	 */
	final class ColumnConstraints {

		public readonly bool $nullable;
		public readonly bool $identity;

		public function __construct(bool $nullable = false, bool $identity = false) {
			$this->nullable = $nullable;
			$this->identity = $identity;
		}
	}
