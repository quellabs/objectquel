<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * A `primary key (col {, col})` sub-operation inside `alter Name (...)`
	 * — replaces the table's full primary key outright, never merges with
	 * whatever primary key already exists (see
	 * objectquel-primary-key-design.md, decision 1, and
	 * objectquel-alter-table-design.md's "Open questions" resolution).
	 * Shares its clause grammar with `create` via
	 * Rules\PrimaryKeyClause. Symmetric removal op is AstAlterDropPrimaryKey.
	 */
	class AstAlterSetPrimaryKey extends Ast implements AstAlterOperation {

		/** @var string[] */
		private array $columns;

		/**
		 * @param string[] $columns Ordered PK column names
		 */
		public function __construct(array $columns) {
			$this->columns = $columns;
		}

		/**
		 * @return string[]
		 */
		public function getColumns(): array {
			return $this->columns;
		}

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->columns);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
