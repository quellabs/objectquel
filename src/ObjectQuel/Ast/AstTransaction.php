<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * `transaction { ... }` — an atomic block that preserves any caller-owned transaction.
	 */
	class AstTransaction extends AstStatementBlock {

		/**
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->cloneArray($this->getBody()));
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
