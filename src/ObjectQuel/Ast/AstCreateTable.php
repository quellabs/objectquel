<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * A `create [temporary] Name (attr = type constraints, ...) [if not
	 * exists]` statement — a top-level statement, not part of a `retrieve`
	 * query. Compiled and executed directly (see
	 * Execution\Executors\CreateTableExecutor), bypassing the retrieve
	 * pipeline entirely. `if not exists` is the trailing-qualifier
	 * counterpart to `destroy`'s `if exists`.
	 *
	 * The primary key is carried separately from $columns, as an ordered
	 * list of column names sourced from a table-level `primary key (...)`
	 * clause (empty when the table has no PK) — see
	 * objectquel-primary-key-design.md.
	 */
	class AstCreateTable extends Ast implements AstStatement {

		private string $tableName;

		/** @var AstColumnDefinition[] */
		private array $columns;

		private bool $temporary;

		private bool $ifNotExists;

		/** @var string[] */
		private array $primaryKeyColumns;

		/**
		 * AstCreateTable constructor.
		 * @param string $tableName
		 * @param AstColumnDefinition[] $columns
		 * @param bool $temporary
		 * @param bool $ifNotExists
		 * @param string[] $primaryKeyColumns Ordered PK column names, empty when the table has no PK
		 */
		public function __construct(string $tableName, array $columns, bool $temporary, bool $ifNotExists = false, array $primaryKeyColumns = []) {
			$this->tableName = $tableName;
			$this->columns = $columns;
			$this->temporary = $temporary;
			$this->ifNotExists = $ifNotExists;
			$this->primaryKeyColumns = $primaryKeyColumns;

			foreach ($this->columns as $column) {
				$column->setParent($this);
			}
		}

		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);

			foreach ($this->columns as $column) {
				$column->accept($visitor);
			}
		}

		public function getTableName(): string {
			return $this->tableName;
		}

		/**
		 * @return AstColumnDefinition[]
		 */
		public function getColumns(): array {
			return $this->columns;
		}

		public function isTemporary(): bool {
			return $this->temporary;
		}

		public function isIfNotExists(): bool {
			return $this->ifNotExists;
		}

		/**
		 * @return string[] Ordered PK column names, empty when the table has no PK
		 */
		public function getPrimaryKeyColumns(): array {
			return $this->primaryKeyColumns;
		}

		public function deepClone(): static {
			$clonedColumns = $this->cloneArray($this->columns);

			// @phpstan-ignore-next-line new.static
			$clone = new static($this->tableName, $clonedColumns, $this->temporary, $this->ifNotExists, $this->primaryKeyColumns);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
