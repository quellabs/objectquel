<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * An `add attr = type constraints [backfill 'literal']` sub-operation
	 * inside `alter Name (...)` — adds a new column to the table. Reuses the
	 * exact same column grammar as `create` (see
	 * AstColumnDefinition/Rules\ColumnDefinitionClause), rather than a
	 * second column syntax — see objectquel-alter-table-design.md.
	 *
	 * `backfill` is a property of this operation, not of the column
	 * definition itself — it's only meaningful for `add` (a brand-new
	 * column on a possibly-populated table), never for `create` (no rows
	 * yet) or `retype` (the column already has values). See
	 * Rules\AlterTable::parseAdd().
	 */
	class AstAlterAddColumn extends Ast implements AstAlterOperation {

		private AstColumnDefinition $column;
		private ?string $backfillValue;

		public function __construct(AstColumnDefinition $column, ?string $backfillValue = null) {
			$this->column = $column;
			$this->column->setParent($this);
			$this->backfillValue = $backfillValue;
		}

		public function getColumn(): AstColumnDefinition {
			return $this->column;
		}

		/**
		 * @return string|null The literal value new/existing rows' column
		 *                      should be backfilled with, or null when no
		 *                      `backfill` clause was written.
		 */
		public function getBackfillValue(): ?string {
			return $this->backfillValue;
		}

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->column->deepClone(), $this->backfillValue);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
