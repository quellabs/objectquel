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
	 *
	 * Embedded `[unique|fulltext] index name (...)` entries are carried
	 * separately as well, as AstCreateTableIndex descriptors in declaration
	 * order — sugar assembled into real AstCreateIndex statements by
	 * Execution\Executors\CreateTableExecutor, not a second implementation
	 * of index lifecycle (see objectquel-index-clause-design.md).
	 *
	 * Embedded `foreign key (col) references Table (col) ...` entries are
	 * carried separately too, as AstCreateTableForeignKey descriptors in
	 * declaration order — compiled directly by QuelToSQLCreate as trailing
	 * table constraints, not sugar over a separate statement (see
	 * objectquel-foreign-key-design.md, decision 2).
	 */
	class AstCreateTable extends Ast implements AstStatement {

		private string $tableName;

		/** @var AstColumnDefinition[] */
		private array $columns;

		private bool $temporary;

		private bool $ifNotExists;

		/** @var string[] */
		private array $primaryKeyColumns;

		/** @var AstCreateTableIndex[] */
		private array $indexes;

		/** @var AstCreateTableForeignKey[] */
		private array $foreignKeys;

		/**
		 * AstCreateTable constructor.
		 * @param string $tableName
		 * @param AstColumnDefinition[] $columns
		 * @param bool $temporary
		 * @param bool $ifNotExists
		 * @param string[] $primaryKeyColumns Ordered PK column names, empty when the table has no PK
		 * @param AstCreateTableIndex[] $indexes Embedded index entries, in declaration order
		 * @param AstCreateTableForeignKey[] $foreignKeys Embedded foreign key entries, in declaration order
		 */
		public function __construct(string $tableName, array $columns, bool $temporary, bool $ifNotExists = false, array $primaryKeyColumns = [], array $indexes = [], array $foreignKeys = []) {
			$this->tableName = $tableName;
			$this->columns = $columns;
			$this->temporary = $temporary;
			$this->ifNotExists = $ifNotExists;
			$this->primaryKeyColumns = $primaryKeyColumns;
			$this->indexes = $indexes;
			$this->foreignKeys = $foreignKeys;

			foreach ($this->columns as $column) {
				$column->setParent($this);
			}

			foreach ($this->indexes as $index) {
				$index->setParent($this);
			}

			foreach ($this->foreignKeys as $foreignKey) {
				$foreignKey->setParent($this);
			}
		}

		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);

			foreach ($this->columns as $column) {
				$column->accept($visitor);
			}

			foreach ($this->indexes as $index) {
				$index->accept($visitor);
			}

			foreach ($this->foreignKeys as $foreignKey) {
				$foreignKey->accept($visitor);
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

		/**
		 * @return AstCreateTableIndex[] Embedded index entries, in declaration order
		 */
		public function getIndexes(): array {
			return $this->indexes;
		}

		/**
		 * @return AstCreateTableForeignKey[] Embedded foreign key entries, in declaration order
		 */
		public function getForeignKeys(): array {
			return $this->foreignKeys;
		}

		public function deepClone(): static {
			$clonedColumns = $this->cloneArray($this->columns);
			$clonedIndexes = $this->cloneArray($this->indexes);
			$clonedForeignKeys = $this->cloneArray($this->foreignKeys);

			// @phpstan-ignore-next-line new.static
			$clone = new static($this->tableName, $clonedColumns, $this->temporary, $this->ifNotExists, $this->primaryKeyColumns, $clonedIndexes, $clonedForeignKeys);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
