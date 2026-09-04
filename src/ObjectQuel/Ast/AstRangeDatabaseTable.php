<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * Class AstRangeDatabaseTable
	 *
	 * Represents `range of alias is table Name` — a range keyed on a raw table
	 * name rather than an Entity class, so no EntityStore lookup is involved.
	 *
	 * Distinct from AstRangeDatabaseTempTable, which materializes a subquery's
	 * results into a temp table as an internal planner optimization. A
	 * QUEL-authored table (via `create`) has an explicit column list, not an
	 * underlying query, so it needs this lighter range shape instead.
	 *
	 * Parses today (see Rules\Range); full compile/execution support in the
	 * `retrieve` pipeline — property resolution, hydration, etc. against a
	 * non-Entity-mapped table — is not wired up yet. That integration is
	 * deferred until the `append`/`replace`/`delete`/`destroy` statements this
	 * range form primarily exists for are implemented.
	 */
	class AstRangeDatabaseTable extends AstRange {

		private string $tableName;

		/**
		 * AstRangeDatabaseTable constructor.
		 * @param string $name The alias for this range in the query
		 * @param string $tableName The raw (physical) table name
		 */
		public function __construct(string $name, string $tableName) {
			parent::__construct($name);
			$this->tableName = $tableName;
		}

		public function getTableName(): string {
			return $this->tableName;
		}

		public function setTableName(string $tableName): void {
			$this->tableName = $tableName;
		}

		public function deepClone(): static {
			// @phpstan-ignore-next-line new.static
			$clone = new static($this->getName(), $this->tableName);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
