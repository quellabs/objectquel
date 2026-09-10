<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * A `show Name on Table` statement — the reverse of AstHideIndex: marks a
	 * previously hidden index visible to the query optimizer again (MySQL
	 * 8.0+ `VISIBLE`, MariaDB `NOT IGNORED`). See AstHideIndex's docblock for
	 * why this is its own statement family rather than an `alter`
	 * sub-operation.
	 */
	class AstShowIndex extends Ast implements AstStatement {

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
