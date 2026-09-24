<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * `continue` — starts the next iteration of the innermost enclosing `while` or `foreach`.
	 */
	class AstContinue extends Ast {

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
