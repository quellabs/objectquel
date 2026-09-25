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

		/**
		 * Initializes this AST node.
		 * @param AstColumnDefinition $column
		 * @return void
		 */
		public function __construct(AstColumnDefinition $column) {
			$this->column = $column;
			$this->column->setParent($this);
		}

		/**
		 * Returns the column.
		 * @return AstColumnDefinition
		 */
		public function getColumn(): AstColumnDefinition {
			return $this->column;
		}

		/**
		 * Returns a deep clone of this node.
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->column->deepClone());
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
