<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * `exit` — rolls back and exits the innermost enclosing `transaction { }`.
	 */
	class AstExit extends Ast {

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
