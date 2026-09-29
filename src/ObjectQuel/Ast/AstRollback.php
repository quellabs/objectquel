<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * `rollback` — rolls back and exits the innermost enclosing `atomic { }`.
	 */
	class AstRollback extends Ast {

		/**
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static();
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
