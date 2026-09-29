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

		/**
		 * Initializes this AST node.
		 * @param string $indexName
		 * @return void
		 */
		public function __construct(string $indexName) {
			$this->indexName = $indexName;
		}

		/**
		 * Returns the index name.
		 * @return string
		 */
		public function getIndexName(): string {
			return $this->indexName;
		}

		/**
		 * Returns a deep clone of this node.
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->indexName);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
