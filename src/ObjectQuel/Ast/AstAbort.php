<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * `abort` — rolls back and exits the innermost enclosing `begin transaction { }`.
	 */
	class AstAbort extends Ast {

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
