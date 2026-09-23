<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * One `type name` entry in a routine's parameter list.
	 */
	class AstRoutineParameter extends Ast {

		private string $name;
		private string $type;

		/**
		 * @param string $name Parameter name
		 * @param string $type Type name as written in the source
		 */
		public function __construct(string $name, string $type) {
			$this->name = $name;
			$this->type = $type;
		}

		/**
		 * @return string Parameter name
		 */
		public function getName(): string {
			return $this->name;
		}

		/**
		 * @return string Type name as written in the source
		 */
		public function getType(): string {
			return $this->type;
		}

		/**
		 * @return static
		 */
		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			return new static($this->name, $this->type);
		}
	}
