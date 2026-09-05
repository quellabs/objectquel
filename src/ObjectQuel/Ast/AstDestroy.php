<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * A `destroy [temporary] Name [if exists]` statement — a top-level
	 * statement, drops one table. See AstDestroyIndex for the `destroy Name
	 * on Table [if exists]` index form (objectquel-destroy-index-plan.md) —
	 * a bare `destroy Name` always means this table form.
	 *
	 * Targets exactly one name per statement — the comma-separated
	 * multi-name list this statement used to support was removed so that
	 * every `destroy` form (table, index, and later procedure) targets
	 * exactly one object per statement, matching `create`'s own one-object
	 * precedent (see objectquel-destroy-index-plan.md, "No list support
	 * anywhere, not just for indexes").
	 *
	 * `temporary` (mirrors `create temporary`) tells the compiler the named
	 * target is a session-temp table, so it can resolve it to its real
	 * physical name unambiguously — needed because on SQL Server a local
	 * temp table's physical name (`#Name`) differs from its logical one,
	 * unlike every other supported engine. Without `temporary`, a
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

		private string $name;

		private bool $temporary;

		private bool $ifExists;

		public function __construct(string $name, bool $temporary = false, bool $ifExists = false) {
			$this->name = $name;
			$this->temporary = $temporary;
			$this->ifExists = $ifExists;
		}

		public function getName(): string {
			return $this->name;
		}

		public function isTemporary(): bool {
			return $this->temporary;
		}

		public function isIfExists(): bool {
			return $this->ifExists;
		}

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->name, $this->temporary, $this->ifExists);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
