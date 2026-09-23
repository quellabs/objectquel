<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * `delete cursorName` inside `foreach cursorName { }` — deletes the row
	 * currently being iterated (lowers to `WHERE CURRENT OF`).
	 */
	class AstDeleteCurrent extends Ast {

		private string $cursorName;

		/**
		 * @param string $cursorName Name of the enclosing loop's `cursor` local
		 */
		public function __construct(string $cursorName) {
			$this->cursorName = $cursorName;
		}

		/**
		 * @return string Name of the enclosing loop's `cursor` local
		 */
		public function getCursorName(): string {
			return $this->cursorName;
		}

		/**
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->cursorName);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
