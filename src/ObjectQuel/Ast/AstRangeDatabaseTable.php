<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	/**
	 * `range of alias is table Name` — keyed on a raw table name, not an
	 * Entity class, so no EntityStore lookup is involved. Distinct from
	 * AstRangeDatabaseTempTable, which materializes a subquery into a temp
	 * table as an internal planner optimization.
	 *
	 * Not usable in `retrieve`, by design: `retrieve` hydrates Entity-mapped
	 * data into objects, and a bare table has no entity to hydrate into.
	 * Exists for the bulk, direct-SQL write verbs
	 * (`append`/`replace`/`delete`/`destroy`) instead — see
	 * objectquel-write-verbs-design.md. Parses today; those verbs still need
	 * to be built to consume it.
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
