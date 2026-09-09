<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * A `rename oldAttr to newAttr` sub-operation inside `alter Name (...)`
	 * — explicit, one direction, never inferred (see
	 * objectquel-alter-table-design.md, "Renames are explicit, never
	 * diff-inferred").
	 */
	class AstAlterRenameColumn extends Ast implements AstAlterOperation {

		private string $oldName;

		private string $newName;

		public function __construct(string $oldName, string $newName) {
			$this->oldName = $oldName;
			$this->newName = $newName;
		}

		public function getOldName(): string {
			return $this->oldName;
		}

		public function getNewName(): string {
			return $this->newName;
		}

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->oldName, $this->newName);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
