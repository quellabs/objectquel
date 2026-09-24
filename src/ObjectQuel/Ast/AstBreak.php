<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * `break` — leaves the innermost enclosing `while` or `foreach`.
	 */
	class AstBreak extends Ast {

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
