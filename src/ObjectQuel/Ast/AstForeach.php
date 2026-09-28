<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;

	/**
	 * `foreach (cursorName as rowName) { ... }` — loops a `cursor` local. Inside
	 * the body, `rowName.field` reads the current row; `cursorName` itself
	 * names only the iterator, never the row.
	 */
	class AstForeach extends AstStatementBlock {

		private string $cursorName;
		private string $rowName;

		/**
		 * @param string $cursorName Name of the `cursor` local being looped
		 * @param string $rowName Name bound to the current row inside the body
		 * @param AstInterface[] $body Statements inside the loop
		 */
		public function __construct(string $cursorName, string $rowName, array $body) {
			parent::__construct($body);
			$this->cursorName = $cursorName;
			$this->rowName = $rowName;
		}

		/**
		 * @return string Name of the `cursor` local being looped
		 */
		public function getCursorName(): string {
			return $this->cursorName;
		}

		/**
		 * Rewrites the looped cursor's name, used to resolve block-scoped name reuse to a routine-wide-unique name.
		 * @param string $cursorName The resolved cursor name
		 * @return void
		 */
		public function setCursorName(string $cursorName): void {
			$this->cursorName = $cursorName;
		}

		/**
		 * @return string Name bound to the current row inside the body
		 */
		public function getRowName(): string {
			return $this->rowName;
		}

		/**
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->cursorName, $this->rowName, $this->cloneArray($this->getBody()));
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
