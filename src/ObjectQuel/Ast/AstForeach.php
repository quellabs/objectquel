<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;

	/**
	 * `foreach cursorName { ... }` — loops a `cursor` local. Inside the body,
	 * `cursorName.field` reads the current row.
	 */
	class AstForeach extends AstStatementBlock {

		private string $cursorName;

		/**
		 * @param string $cursorName Name of the `cursor` local being looped
		 * @param AstInterface[] $body Statements inside the loop
		 */
		public function __construct(string $cursorName, array $body) {
			parent::__construct($body);
			$this->cursorName = $cursorName;
		}

		/**
		 * @return string Name of the `cursor` local being looped
		 */
		public function getCursorName(): string {
			return $this->cursorName;
		}

		/**
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->cursorName, $this->cloneArray($this->getBody()));
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
