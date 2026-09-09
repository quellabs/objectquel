<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * An `add foreign key (col) references Table (col) [on delete action]
	 * [on update action]` sub-operation inside `alter Name (...)` —
	 * compiled directly by QuelToSQLAlter, unlike `add index`
	 * (AstAlterAddIndex), which is sugar assembled into a separate
	 * AstCreateIndex statement. There's no equivalent lifecycle to delegate
	 * to here — see objectquel-foreign-key-design.md, decision 2.
	 *
	 * Same column+references+action shape as the embedded `create` form
	 * (AstCreateTableForeignKey). No name field — see
	 * objectquel-foreign-key-design.md, decision 1.
	 *
	 * $referencedColumn is null when `references Table` omitted its column
	 * list; AlterTableExecutor resolves it via withReferencedColumn()
	 * before compiling.
	 */
	class AstAlterAddForeignKey extends Ast implements AstAlterOperation {

		private string $column;

		private string $referencedTable;

		private ?string $referencedColumn;

		private string $onDelete;

		private string $onUpdate;

		public function __construct(string $column, string $referencedTable, ?string $referencedColumn, string $onDelete, string $onUpdate) {
			$this->column = $column;
			$this->referencedTable = $referencedTable;
			$this->referencedColumn = $referencedColumn;
			$this->onDelete = $onDelete;
			$this->onUpdate = $onUpdate;
		}

		public function getColumn(): string {
			return $this->column;
		}

		public function getReferencedTable(): string {
			return $this->referencedTable;
		}

		public function getReferencedColumn(): ?string {
			return $this->referencedColumn;
		}

		public function getOnDelete(): string {
			return $this->onDelete;
		}

		public function getOnUpdate(): string {
			return $this->onUpdate;
		}

		/** Returns a clone with $referencedColumn resolved. */
		public function withReferencedColumn(string $referencedColumn): self {
			$clone = new self($this->column, $this->referencedTable, $referencedColumn, $this->onDelete, $this->onUpdate);
			$clone->setParent($this->getParent());
			return $clone;
		}

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->column, $this->referencedTable, $this->referencedColumn, $this->onDelete, $this->onUpdate);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
