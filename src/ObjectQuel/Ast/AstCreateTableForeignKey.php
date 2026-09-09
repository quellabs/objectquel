<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * A `foreign key (col) references Table (col) [on delete action] [on
	 * update action]` entry embedded in a `create Name (...)` statement's
	 * column list — single column each side (see
	 * objectquel-foreign-key-design.md, decision 3), compiled directly by
	 * QuelToSQLCreate as a trailing table constraint, unlike index entries
	 * (AstCreateTableIndex), which are sugar assembled into a separate
	 * AstCreateIndex statement. There's no equivalent lifecycle to delegate
	 * to here — see objectquel-foreign-key-design.md, decision 2.
	 *
	 * No name field: the constraint name is always derived
	 * (`fk_{table}_{column}`), never author-supplied — see
	 * objectquel-foreign-key-design.md, decision 1.
	 *
	 * $onDelete/$onUpdate are always resolved to one of RESTRICT/CASCADE/
	 * SET NULL/NO ACTION by the parser (Rules\ForeignKeyClause), defaulting
	 * to RESTRICT/NO ACTION when omitted — same defaults
	 * @Orm\ForeignKeyAction falls back to when absent.
	 */
	class AstCreateTableForeignKey extends Ast {

		private string $column;

		private string $referencedTable;

		private string $referencedColumn;

		private string $onDelete;

		private string $onUpdate;

		public function __construct(string $column, string $referencedTable, string $referencedColumn, string $onDelete, string $onUpdate) {
			$this->column = $column;
			$this->referencedTable = $referencedTable;
			$this->referencedColumn = $referencedColumn;
			$this->onDelete = $onDelete;
			$this->onUpdate = $onUpdate;
		}

		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
		}

		public function getColumn(): string {
			return $this->column;
		}

		public function getReferencedTable(): string {
			return $this->referencedTable;
		}

		public function getReferencedColumn(): string {
			return $this->referencedColumn;
		}

		public function getOnDelete(): string {
			return $this->onDelete;
		}

		public function getOnUpdate(): string {
			return $this->onUpdate;
		}

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->column, $this->referencedTable, $this->referencedColumn, $this->onDelete, $this->onUpdate);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
