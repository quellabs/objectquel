<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * A `drop foreign key (col {, col})` sub-operation inside `alter Name
	 * (...)` — targets by column list, not by the derived constraint
	 * name, since the author writing `alter` DDL never needs to know or
	 * guess that name. No standalone-statement analog to mirror the way
	 * `drop index name` mirrors AstDestroyIndex.
	 */
	class AstAlterDropForeignKey extends Ast implements AstAlterOperation {

		/** @var list<string> */
		private array $columns;

		/**
		 * Initializes this AST node.
		 * @param list<string> $columns
		 * @return void
		 */
		public function __construct(array $columns) {
			$this->columns = $columns;
		}

		/**
		 * Returns the ordered local columns.
		 * @return list<string>
		 */
		public function getColumns(): array {
			return $this->columns;
		}

		/**
		 * Returns a deep clone of this node.
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->columns);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
