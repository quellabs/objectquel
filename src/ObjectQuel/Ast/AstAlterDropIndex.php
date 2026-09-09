<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * A `drop index name` sub-operation inside `alter Name (...)` — name
	 * only, no column list, mirrors `drop attr` and `drop primary key`.
	 * Sugar that assembles a real AstDestroyIndex (against the enclosing
	 * statement's table) — see objectquel-index-clause-design.md, "Sugar,
	 * not reimplementation".
	 */
	class AstAlterDropIndex extends Ast implements AstAlterOperation {

		private string $indexName;

		public function __construct(string $indexName) {
			$this->indexName = $indexName;
		}

		public function getIndexName(): string {
			return $this->indexName;
		}

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->indexName);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
