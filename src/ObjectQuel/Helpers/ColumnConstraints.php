<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;

	/**
	 * The `nullable`/`identity` constraint keywords following a column's type.
	 * See Rules\ColumnDefinitionClause::parseColumnConstraints() for why columns are
	 * NOT NULL by default.
	 */
	final class ColumnConstraints {

		public readonly bool $nullable;
		public readonly bool $identity;

		/**
		 * Initializes this object.
		 * @param bool $nullable
		 * @param bool $identity
		 * @return void
		 */
		public function __construct(bool $nullable = false, bool $identity = false) {
			$this->nullable = $nullable;
			$this->identity = $identity;
		}
	}
