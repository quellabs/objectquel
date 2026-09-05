<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * A `destroy Name on Table [if exists]` statement — a top-level
	 * statement, drops one index. Distinguished from AstDestroy (the table
	 * form) by shape, not by a keyword: nothing trailing after the name
	 * means "table"; `on Table` means "index" (see
	 * objectquel-destroy-index-plan.md, "Design rationale — why shape, not
	 * a keyword"). A separate node from AstDestroy rather than folded into
	 * it — different shape, different compile target, nothing worth
	 * sharing, mirroring why AstCreateIndex is its own node rather than
	 * reusing AstCreateTable.
	 *
	 * No `temporary` concept: temp-table indexing isn't in scope here (see
	 * objectquel-temp-table-indexing.md if that changes).
	 *
	 * $tableName is used as the literal physical table name, exactly like
	 * AstCreateIndex/AstDestroy — no EntityStore resolution. Kept even
	 * though pgsql/sqlite index names are unique per-schema and don't
	 * strictly need it to resolve `$indexName` — mysql/sqlsrv's `DROP INDEX
	 * ... ON <table>` syntax requires it, and the grammar itself is
	 * dialect-independent (see QuelToSQLDestroyIndex).
	 */
	class AstDestroyIndex extends Ast {

		private string $indexName;

		private string $tableName;

		private bool $ifExists;

		public function __construct(string $indexName, string $tableName, bool $ifExists = false) {
			$this->indexName = $indexName;
			$this->tableName = $tableName;
			$this->ifExists = $ifExists;
		}

		public function getIndexName(): string {
			return $this->indexName;
		}

		public function getTableName(): string {
			return $this->tableName;
		}

		public function isIfExists(): bool {
			return $this->ifExists;
		}

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->indexName, $this->tableName, $this->ifExists);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
