<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * A `destroy [temporary] Name {, Name} [if exists]` statement — a
	 * top-level statement, drops one or more tables (index destroy isn't
	 * implemented yet, see objectquel-destroy-plan.md). Flat name list,
	 * mirroring the grammar directly; `temporary` and `if exists` both apply
	 * to every name in the statement, not per-name.
	 *
	 * `temporary` (mirrors `create temporary`) tells the compiler every
	 * named target is a session-temp table, so it can resolve each to its
	 * real physical name unambiguously — needed because on SQL Server a
	 * local temp table's physical name (`#Name`) differs from its logical
	 * one, unlike every other supported engine. Without `temporary`, a
	 * SQL-Server target is resolved by emulating the same "a same-named
	 * session temp table shadows the permanent one" priority the other
	 * three engines already give unqualified names natively — see
	 * QuelToSQLDestroy.
	 *
	 * `if exists` is QUEL-flavored trailing-qualifier syntax for what SQL
	 * spells as a prefix `DROP TABLE IF EXISTS` — a name that doesn't exist
	 * is silently ignored instead of failing loudly, opt-in rather than the
	 * (fail-loud) default. Compiled and executed directly (see
	 * Execution\Executors\DestroyExecutor), same as AstCreateTable.
	 */
	class AstDestroy extends Ast {

		/** @var string[] */
		private array $names;

		private bool $temporary;

		private bool $ifExists;

		/**
		 * AstDestroy constructor.
		 * @param string[] $names
		 * @param bool $temporary
		 * @param bool $ifExists
		 */
		public function __construct(array $names, bool $temporary = false, bool $ifExists = false) {
			$this->names = $names;
			$this->temporary = $temporary;
			$this->ifExists = $ifExists;
		}

		/**
		 * @return string[]
		 */
		public function getNames(): array {
			return $this->names;
		}

		public function isTemporary(): bool {
			return $this->temporary;
		}

		public function isIfExists(): bool {
			return $this->ifExists;
		}

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->names, $this->temporary, $this->ifExists);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
