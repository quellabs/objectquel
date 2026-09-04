<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * A `destroy Name {, Name}` statement — a top-level statement, drops one
	 * or more tables (permanent or temporary; index destroy isn't
	 * implemented yet, see objectquel-destroy-plan.md). Flat name list,
	 * mirroring the grammar directly. Compiled and executed directly (see
	 * Execution\Executors\DestroyExecutor), same as AstCreateTable.
	 */
	class AstDestroy extends Ast {

		/** @var string[] */
		private array $names;

		/**
		 * AstDestroy constructor.
		 * @param string[] $names
		 */
		public function __construct(array $names) {
			$this->names = $names;
		}

		/**
		 * @return string[]
		 */
		public function getNames(): array {
			return $this->names;
		}

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->names);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
