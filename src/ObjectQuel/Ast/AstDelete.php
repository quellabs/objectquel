<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * A `delete <range> where ...` statement — a top-level statement, not
	 * part of a `retrieve` query. Compiled and executed directly (see
	 * Execution\Executors\DeleteExecutor and ObjectQuel\QuelToSQLDelete),
	 * bypassing the retrieve pipeline entirely, same as AstAppend/AstReplace.
	 *
	 * Single target range only — no `delete` driven by a join across
	 * multiple ranges (see objectquel-delete-plan.md's scope cut). The
	 * target must already be a declared, real persisted entity range (not a
	 * subquery/temp-table/JSON range), same restriction as AstReplace, and
	 * for the same reason: the mandatory WHERE clause needs a concrete range
	 * to resolve identifiers against.
	 *
	 * Implements NodeWithRanges (a one-element ranges list, this statement's
	 * single target) so the existing identifier-resolution visitors
	 * (ResolveRootIdentifierType, ResolveIdentifierRange) — built for
	 * AstRetrieve's ranges list — work unchanged here too.
	 *
	 * `where` is mandatory (unlike `retrieve`'s optional WHERE) — there's no
	 * "current tuple of an enclosing retrieve loop" default here; that
	 * exception belongs to the EQUEL procedural layer, not bare `delete`.
	 */
	class AstDelete extends Ast implements NodeWithConditions, NodeWithRanges {

		private AstRangeDatabase|AstRangeTable $range;

		private ?AstInterface $conditions;

		/**
		 * AstDelete constructor.
		 * @param AstRangeDatabase|AstRangeTable $range Target range — a declared, real
		 *        persisted entity range or plain-table range
		 * @param AstInterface $conditions Mandatory WHERE condition
		 */
		public function __construct(AstRangeDatabase|AstRangeTable $range, AstInterface $conditions) {
			$this->range = $range;
			$this->conditions = $conditions;
			$this->conditions->setParent($this);
		}

		public function accept(AstVisitorInterface $visitor): void {
			// Process the range first, mirroring AstRetrieve's accept() order.
			$this->range->accept($visitor);

			parent::accept($visitor);

			$this->conditions?->accept($visitor);
		}

		public function getRange(): AstRangeDatabase|AstRangeTable {
			return $this->range;
		}

		/**
		 * @return AstRange[] Always a single-element array — this statement's one target range.
		 */
		public function getRanges(): array {
			return [$this->range];
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

			// @phpstan-ignore-next-line new.static
			$clone = new static($clonedRange, $this->conditions->deepClone());
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
