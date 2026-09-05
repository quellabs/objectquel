<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * An `index [unique|fulltext] on Table is index_name (column {, column})`
	 * statement — a top-level statement, not part of a `retrieve` query.
	 * Compiled and executed directly (see
	 * Execution\Executors\CreateIndexExecutor), bypassing the retrieve
	 * pipeline entirely, same as AstCreateTable/AstDestroy.
	 *
	 * $tableName is used as the literal physical table name, exactly like
	 * AstCreateTable/AstDestroy — no EntityStore resolution, and no
	 * restriction against targeting an Entity-mapped table (see
	 * objectquel-create-index-plan.md, "Open decisions": the same
	 * no-restriction governance answer as `create`/`destroy`).
	 *
	 * $unique and $type are set from `unique`/`fulltext`, which occupy the
	 * same grammar slot right after `index` in Rules\CreateIndex — at most
	 * one of them is ever true/non-null, since the parser can match only one
	 * of the two keywords. There is no "unique fulltext index" case to guard
	 * against here (see QuelToSQLCreateIndex): the combination is
	 * unrepresentable, not rejected.
	 */
	class AstCreateIndex extends Ast {

		private string $tableName;

		private string $indexName;

		/** @var string[] */
		private array $columns;

		private bool $unique;

		private ?string $type;

		/**
		 * AstCreateIndex constructor.
		 * @param string $tableName
		 * @param string $indexName
		 * @param string[] $columns
		 * @param bool $unique
		 * @param string|null $type
		 */
		public function __construct(string $tableName, string $indexName, array $columns, bool $unique, ?string $type = null) {
			$this->tableName = $tableName;
			$this->indexName = $indexName;
			$this->columns = $columns;
			$this->unique = $unique;
			$this->type = $type;
		}

		public function getTableName(): string {
			return $this->tableName;
		}

		public function getIndexName(): string {
			return $this->indexName;
		}

		/**
		 * @return string[]
		 */
		public function getColumns(): array {
			return $this->columns;
		}

		public function isUnique(): bool {
			return $this->unique;
		}

		public function getType(): ?string {
			return $this->type;
		}

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->tableName, $this->indexName, $this->columns, $this->unique, $this->type);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
