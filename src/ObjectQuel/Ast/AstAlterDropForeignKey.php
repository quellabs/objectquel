<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * A `drop foreign key (col)` sub-operation inside `alter Name (...)` —
	 * targets by column, not by the derived constraint name, since the
	 * author writing `alter` DDL never needs to know or guess that name
	 * (see objectquel-foreign-key-design.md, decision 1). No standalone-
	 * statement analog to mirror the way `drop index name` mirrors
	 * AstDestroyIndex — see objectquel-foreign-key-design.md, decision 2.
	 */
	class AstAlterDropForeignKey extends Ast implements AstAlterOperation {

		private string $column;

		public function __construct(string $column) {
			$this->column = $column;
		}

		public function getColumn(): string {
			return $this->column;
		}

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->column);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
