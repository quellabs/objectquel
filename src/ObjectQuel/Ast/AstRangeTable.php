<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;

	/**
	 * Class AstRangeTable
	 * Represents a range in the AST that sources data directly from a plain
	 * database table by name, with no backing entity class.
	 *
	 * `via` is supported, but means something different here than it does for
	 * an entity range: an entity's `via <relation>` names a declared relation
	 * (`@OneToOne`/`@ManyToOne`/`@InverseOf`) that gets rewritten into a join
	 * condition by RewriteViaRelationToJoinCondition. A plain-table range has
	 * no relation catalog to name anything from, so its `via <condition>`
	 * takes the literal join condition directly — parsed once, up front, and
	 * never rewritten (see Rules\Range::parseTableRange()). It's always a
	 * LEFT JOIN: there's no relation annotation to consult for "required", and
	 * this deliberately doesn't grow QUEL a way to spell INNER — the same
	 * default a bare entity `via` uses before any @RequiredRelation upgrades
	 * it (see objectquel-plain-table-range-plan.md).
	 */
	class AstRangeTable extends AstRange {

		/**
		 * The physical table name this range reads from/writes to
		 * @var string
		 */
		protected string $tableName;

		/**
		 * Constructs a new plain-table range.
		 * @param string $name The identifier/alias for this range
		 * @param string $tableName The physical table name
		 * @param AstInterface|null $joinCondition The `via <condition>` join
		 *        condition, already a real expression (not a relation name to
		 *        resolve later) — null when the range has no `via` clause.
		 */
		public function __construct(string $name, string $tableName, ?AstInterface $joinCondition = null) {
			parent::__construct($name, false, $joinCondition);
			$this->tableName = $tableName;
		}

		/**
		 * Retrieves the physical table name.
		 * @return string
		 */
		public function getTableName(): string {
			return $this->tableName;
		}

		/**
		 * Sets the physical table name.
		 * @param string $tableName
		 */
		public function setTableName(string $tableName): void {
			$this->tableName = $tableName;
		}

		// ========================================
		// Join Inclusion Control
		// ========================================

		/**
		 * Check whether this range should be included as a JOIN clause.
		 * @return bool True if this range should be included as a JOIN
		 */
		public function includeAsJoin(): bool {
			return true;
		}

		/**
		 * Creates a deep clone of this range node.
		 * @return static A new instance with the same property values
		 */
		public function deepClone(): static {
			$joinCondition = $this->getJoinProperty()?->deepClone();

			// @phpstan-ignore-next-line new.static
			$clone = new static($this->getName(), $this->getTableName(), $joinCondition);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
