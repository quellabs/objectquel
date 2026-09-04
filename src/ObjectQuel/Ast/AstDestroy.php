<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * Class AstDestroy
	 *
	 * Represents a `destroy Name {, Name}` statement — a top-level ObjectQuel
	 * statement, not part of a `retrieve` query. Drops one or more tables
	 * (permanent or temporary; index destroy is not implemented yet — see
	 * objectquel-destroy-plan.md).
	 *
	 * A flat list of names, mirroring the comma-separated-list grammar
	 * directly rather than splitting into per-name statements at parse time.
	 * Compiled and executed directly against the connection (see
	 * Execution\Executors\DestroyExecutor); does not go through the retrieve
	 * pipeline, same as AstCreateTable.
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
