<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * A `foreign key (col {, col}) references Table (col {, col}) [on
	 * delete action] [on update action]` entry embedded in a `create Name
	 * (...)` statement's column list — compiled directly by
	 * QuelToSQLCreate as a trailing table constraint, unlike index entries
	 * (AstCreateTableIndex), which are sugar assembled into a separate
	 * AstCreateIndex statement. There's no equivalent lifecycle to delegate
	 * to here.
	 *
	 * $columns and $referencedColumns are ordered, positionally paired
	 * lists of equal length once $referencedColumns is resolved.
	 *
	 * No name field: the constraint name is always derived
	 * (ForeignKeyConstraintNamer), never author-supplied.
	 *
	 * $onDelete/$onUpdate are always resolved to one of RESTRICT/CASCADE/
	 * SET NULL/NO ACTION by the parser (Rules\ForeignKeyClause), defaulting
	 * to RESTRICT/NO ACTION when omitted — same defaults
	 * @Orm\ForeignKeyAction falls back to when absent.
	 *
	 * $referencedColumns is null when `references Table` omitted its
	 * column list; CreateTableExecutor resolves it via
	 * withReferencedColumns() before compiling.
	 */
	class AstCreateTableForeignKey extends Ast {

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
		 * Passes this node to the visitor.
		 * @param AstVisitorInterface $visitor
		 * @return void
		 */
		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
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
