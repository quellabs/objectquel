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

		/**
		 * AstCreateTable constructor.
		 * @param string $tableName
		 * @param AstColumnDefinition[] $columns
		 * @param bool $temporary
		 * @param bool $ifNotExists
		 * @param string[] $primaryKeyColumns Ordered PK column names, empty when the table has no PK
		 * @param AstCreateTableIndex[] $indexes Embedded index entries, in declaration order
		 */
		public function __construct(string $tableName, array $columns, bool $temporary, bool $ifNotExists = false, array $primaryKeyColumns = [], array $indexes = []) {
			$this->tableName = $tableName;
			$this->columns = $columns;
			$this->temporary = $temporary;
			$this->ifNotExists = $ifNotExists;
			$this->primaryKeyColumns = $primaryKeyColumns;
			$this->indexes = $indexes;

			foreach ($this->columns as $column) {
				$column->setParent($this);
			}

			foreach ($this->indexes as $index) {
				$index->setParent($this);
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

		public function deepClone(): static {
			$clonedColumns = $this->cloneArray($this->columns);
			$clonedIndexes = $this->cloneArray($this->indexes);

			// @phpstan-ignore-next-line new.static
			$clone = new static($this->tableName, $clonedColumns, $this->temporary, $this->ifNotExists, $this->primaryKeyColumns, $clonedIndexes);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
