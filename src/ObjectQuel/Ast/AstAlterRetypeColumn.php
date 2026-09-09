<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * A `retype attr = type constraints` sub-operation inside `alter Name
	 * (...)` — replaces an existing column's type/constraints in place.
	 * $column's name identifies the column being retyped; it must already
	 * exist (unlike `add`). Same column grammar as `add` (see
	 * AstColumnDefinition/Rules\ColumnDefinitionClause) — see
	 * objectquel-alter-table-design.md.
	 */
	class AstAlterRetypeColumn extends Ast implements AstAlterOperation {

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
