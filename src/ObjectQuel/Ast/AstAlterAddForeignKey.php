<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * An `add foreign key (col {, col}) references Table (col {, col})
	 * [on delete action] [on update action]` sub-operation inside `alter
	 * Name (...)` — compiled directly by QuelToSQLAlter, unlike `add
	 * index` (AstAlterAddIndex), which is sugar assembled into a separate
	 * AstCreateIndex statement. There's no equivalent lifecycle to delegate
	 * to here.
	 *
	 * Same columns+references+action shape as the embedded `create` form
	 * (AstCreateTableForeignKey). No name field.
	 *
	 * $referencedColumns is null when `references Table` omitted its
	 * column list; AlterTableExecutor resolves it via
	 * withReferencedColumns() before compiling.
	 */
	class AstAlterAddForeignKey extends Ast implements AstAlterOperation {

		/** @var list<string> */
		private array $columns;

		private string $referencedTable;

		/** @var ?list<string> */
		private ?array $referencedColumns;

		private string $onDelete;

		private string $onUpdate;

		/**
		 * Initializes this AST node.
		 * @param list<string> $columns
		 * @param string $referencedTable
		 * @param ?list<string> $referencedColumns
		 * @param string $onDelete
		 * @param string $onUpdate
		 * @return void
		 */
		public function __construct(array $columns, string $referencedTable, ?array $referencedColumns, string $onDelete, string $onUpdate) {
			$this->columns = $columns;
			$this->referencedTable = $referencedTable;
			$this->referencedColumns = $referencedColumns;
			$this->onDelete = $onDelete;
			$this->onUpdate = $onUpdate;
		}

		/**
		 * Returns the ordered local columns.
		 * @return list<string>
		 */
		public function getColumns(): array {
			return $this->columns;
		}

		/**
		 * Returns the referenced table.
		 * @return string
		 */
		public function getReferencedTable(): string {
			return $this->referencedTable;
		}

		/**
		 * Returns the ordered referenced columns, positionally paired with getColumns().
		 * @return ?list<string>
		 */
		public function getReferencedColumns(): ?array {
			return $this->referencedColumns;
		}

		/**
		 * Returns the foreign key ON DELETE action.
		 * @return string
		 */
		public function getOnDelete(): string {
			return $this->onDelete;
		}

		/**
		 * Returns the foreign key ON UPDATE action.
		 * @return string
		 */
		public function getOnUpdate(): string {
			return $this->onUpdate;
		}

		/**
		 * Returns a clone with $referencedColumns resolved.
		 * @param list<string> $referencedColumns
		 */
		public function withReferencedColumns(array $referencedColumns): self {
			$clone = new self($this->columns, $this->referencedTable, $referencedColumns, $this->onDelete, $this->onUpdate);
			$clone->setParent($this->getParent());
			return $clone;
		}

		/**
		 * Returns a deep clone of this node.
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->columns, $this->referencedTable, $this->referencedColumns, $this->onDelete, $this->onUpdate);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
