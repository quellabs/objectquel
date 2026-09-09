<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * A `drop primary key` sub-operation inside `alter Name (...)` —
	 * removes the table's primary key entirely. Its own verb rather than an
	 * empty-list `primary key ()`, which would read like a mistake instead
	 * of an intentional removal (see objectquel-primary-key-design.md,
	 * decision 3).
	 */
	class AstAlterDropPrimaryKey extends Ast implements AstAlterOperation {

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static();
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
