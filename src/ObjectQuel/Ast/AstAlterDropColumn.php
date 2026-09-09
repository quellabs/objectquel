<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * A `drop attr` sub-operation inside `alter Name (...)` — removes an
	 * existing column, name only, no type (see
	 * objectquel-alter-table-design.md).
	 */
	class AstAlterDropColumn extends Ast implements AstAlterOperation {

		private string $columnName;

		public function __construct(string $columnName) {
			$this->columnName = $columnName;
		}

		public function getColumnName(): string {
			return $this->columnName;
		}

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->columnName);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
