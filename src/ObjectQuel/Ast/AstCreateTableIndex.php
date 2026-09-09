<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * A `[unique|fulltext] index index_name (col {, col})` entry embedded in
	 * a `create Name (...)` statement's column list — sugar that assembles a
	 * real AstCreateIndex (against the enclosing statement's table) rather
	 * than a second implementation of index lifecycle; see
	 * objectquel-index-clause-design.md, "Sugar, not reimplementation", and
	 * Execution\Executors\CreateTableExecutor, which does the assembling.
	 *
	 * Same name+column-list shape as standalone `index ... on Table is
	 * name (...)` and alter's `add index` sub-operation (AstAlterAddIndex),
	 * minus the `on Table is`/`add` phrasing, which is redundant once
	 * already scoped to one table by the enclosing `create` statement.
	 */
	class AstCreateTableIndex extends Ast implements AstIndexEntry {

		private string $indexName;

		/** @var string[] */
		private array $columns;

		private bool $unique;

		private ?string $type;

		/**
		 * @param string $indexName
		 * @param string[] $columns
		 * @param bool $unique
		 * @param string|null $type
		 */
		public function __construct(string $indexName, array $columns, bool $unique, ?string $type = null) {
			$this->indexName = $indexName;
			$this->columns = $columns;
			$this->unique = $unique;
			$this->type = $type;
		}

		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);
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
			$clone = new static($this->indexName, $this->columns, $this->unique, $this->type);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
