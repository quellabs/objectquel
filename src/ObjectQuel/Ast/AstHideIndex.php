<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * A `hide Name on Table` statement — a top-level statement, marks an
	 * existing index invisible to the query optimizer (MySQL 8.0+) or
	 * ignored (MariaDB) without dropping it. The reverse of AstShowIndex.
	 *
	 * Modeled on AstDestroyIndex's shape (bare index name + `on Table`, no
	 * column list, no `is` naming clause) rather than as an `alter`
	 * sub-operation: like create/destroy index, this doesn't change the
	 * table's shape — it toggles a flag on an index that already exists — so
	 * it gets its own statement family instead of nesting inside `alter`
	 * (see objectquel-index-visibility-design.md).
	 *
	 * $tableName is used as the literal physical table name, exactly like
	 * AstDestroyIndex/AstCreateIndex — no EntityStore resolution. Kept even
	 * though only mysql/mariadb ever compile this statement (see
	 * PlatformCapabilities::supportsIndexHiding()) because `ALTER INDEX ...`
	 * is always table-scoped on both.
	 */
	class AstHideIndex extends Ast implements AstStatement {

		private string $indexName;

		private string $tableName;

		public function __construct(string $indexName, string $tableName) {
			$this->indexName = $indexName;
			$this->tableName = $tableName;
		}

		public function getIndexName(): string {
			return $this->indexName;
		}

		public function getTableName(): string {
			return $this->tableName;
		}

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->indexName, $this->tableName);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
