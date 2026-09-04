<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * A `replace <range> (attr = value, ...) where ...` statement — a
	 * top-level statement, not part of a `retrieve` query. Compiled and
	 * executed directly (see Execution\Executors\ReplaceExecutor and
	 * ObjectQuel\QuelToSQLReplace), bypassing the retrieve pipeline
	 * entirely, same as AstAppend/AstCreateTable/AstDestroy.
	 *
	 * Single target range only — no `replace` driven by a join across
	 * multiple ranges (see objectquel-replace-plan.md's scope cut). The
	 * target must already be a declared, real persisted entity range (not a
	 * bare entity name — unlike `append`, there's a WHERE clause that needs
	 * a concrete range to resolve identifiers against — and not a subquery/
	 * temp-table/JSON range either), which is why it's stored as a concrete
	 * AstRangeDatabase rather than just an entity name string.
	 *
	 * Implements NodeWithRanges (a one-element ranges list, this statement's
	 * single target) so the existing identifier-resolution visitors
	 * (ResolveRootIdentifierType, ResolveIdentifierRange) — built for
	 * AstRetrieve's ranges list — work unchanged here too, letting the WHERE
	 * clause and assignment values resolve `range.property` references
	 * exactly like a `retrieve`'s WHERE clause does.
	 *
	 * `where` is mandatory (unlike `retrieve`'s optional WHERE) — there's no
	 * "current tuple of an enclosing retrieve loop" default here; that
	 * exception belongs to the EQUEL procedural layer, not bare `replace`.
	 *
	 * $assignments is empty only when this node represents upsert's `or
	 * replace where <cond>` with no parenthesized list at all (see
	 * Rules\Append/QuelToSQLUpsert) — meaning "overwrite with the row that
	 * would have been inserted" instead of an explicit SET clause. A
	 * standalone `replace` always has at least one assignment; only
	 * Rules\Append's on-conflict parsing can produce an empty list.
	 */
	class AstReplace extends Ast implements NodeWithConditions, NodeWithRanges {

		private AstRangeDatabase $range;

		/** @var AstAssignment[] */
		private array $assignments;

		private ?AstInterface $conditions;

		/**
		 * AstReplace constructor.
		 * @param AstRangeDatabase $range Target range — a declared, real persisted entity range
		 * @param AstAssignment[] $assignments
		 * @param AstInterface $conditions Mandatory WHERE condition
		 */
		public function __construct(AstRangeDatabase $range, array $assignments, AstInterface $conditions) {
			$this->range = $range;
			$this->assignments = $assignments;
			$this->conditions = $conditions;

			foreach ($this->assignments as $assignment) {
				$assignment->setParent($this);
			}

			$this->conditions->setParent($this);
		}

		public function accept(AstVisitorInterface $visitor): void {
			// Process the range first, mirroring AstRetrieve's accept() order.
			$this->range->accept($visitor);

			parent::accept($visitor);

			foreach ($this->assignments as $assignment) {
				$assignment->accept($visitor);
			}

			$this->conditions?->accept($visitor);
		}

		public function getRange(): AstRangeDatabase {
			return $this->range;
		}

		/**
		 * @return AstRange[] Always a single-element array — this statement's one target range.
		 */
		public function getRanges(): array {
			return [$this->range];
		}

		/**
		 * @return AstAssignment[]
		 */
		public function getAssignments(): array {
			return $this->assignments;
		}

		public function getConditions(): ?AstInterface {
			return $this->conditions;
		}

		public function setConditions(?AstInterface $conditions): void {
			$this->conditions = $conditions;
			$this->conditions?->setParent($this);
		}

		public function deepClone(): static {
			$clonedRange = $this->range->deepClone();
			$clonedAssignments = $this->cloneArray($this->assignments);

			// @phpstan-ignore-next-line new.static
			$clone = new static($clonedRange, $clonedAssignments, $this->conditions->deepClone());
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
