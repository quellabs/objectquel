<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * `destroy function name [if exists]`: drops a routine created by `define function`,
	 * whether it compiled to a FUNCTION or a PROCEDURE.
	 */
	class AstDestroyRoutine extends Ast implements AstStatement {

		private string $name;
		private bool $ifExists;

		/**
		 * @param string $name Routine name
		 * @param bool $ifExists True when a missing routine is ignored instead of an error
		 */
		public function __construct(string $name, bool $ifExists = false) {
			$this->name = $name;
			$this->ifExists = $ifExists;
		}

		/**
		 * @return string Routine name
		 */
		public function getName(): string {
			return $this->name;
		}

		/**
		 * @return bool True when a missing routine is ignored instead of an error
		 */
		public function isIfExists(): bool {
			return $this->ifExists;
		}

		/**
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->name, $this->ifExists);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
