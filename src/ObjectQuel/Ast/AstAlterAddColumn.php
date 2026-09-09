<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * An `add attr = type constraints` sub-operation inside `alter Name
	 * (...)` — adds a new column to the table. Reuses the exact same column
	 * grammar as `create` (see AstColumnDefinition/Rules\ColumnDefinitionClause),
	 * rather than a second column syntax — see
	 * objectquel-alter-table-design.md.
	 */
	class AstAlterAddColumn extends Ast implements AstAlterOperation {

		private AstColumnDefinition $column;

		public function __construct(AstColumnDefinition $column) {
			$this->column = $column;
			$this->column->setParent($this);
		}

		public function getColumn(): AstColumnDefinition {
			return $this->column;
		}

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->column->deepClone());
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
