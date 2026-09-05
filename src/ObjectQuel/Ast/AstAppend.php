<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * An `append to <range> (...)` statement — a top-level statement, not
	 * part of a `retrieve` query. Compiled and executed directly (see
	 * Execution\Executors\AppendExecutor and ObjectQuel\QuelToSQLAppend),
	 * bypassing the retrieve pipeline entirely, same as AstCreateTable/
	 * AstDestroy.
	 *
	 * The target must already be a declared range (`range of <name> is
	 * <Entity>`), same restriction `replace`/`delete` have — see Rules\Append.
	 *
	 * Two mutually exclusive shapes, both represented by this one node
	 * (distinguished at parse time by whether `=` follows the first name in
	 * the parenthesized list — see Rules\Append):
	 *
	 *  - Literal values: `append to u (name = "Alice", ...), (name = "Bob", ...)`
	 *    — $rows holds one or more assignment-rows (multi-row append is the
	 *    same node with more entries, not a separate construct). $columns and
	 *    $source are null.
	 *
	 *  - Insert-from-select: `append to o (name, total) retrieve (a.name, a.total) ...`
	 *    — $columns holds the bare column list and $source the nested
	 *    AstRetrieve to select from. $rows is null.
	 *
	 * $range is the already-resolved target range (see objectquel-append-plan.md,
	 * for the plain-table case objectquel-plain-table-range-plan.md, and for
	 * the JSON-source case objectquel-json-append-plan.md — JSON only reaches
	 * the literal-values constructor below, never forSelect(), since
	 * insert-from-select into a JSON range is rejected at parse time).
	 * getEntityName()/getTableName()/getJsonSourcePath() derive from it —
	 * exactly one is non-null, matching the range kind.
	 *
	 * $onConflict is the upsert extension (see objectquel-upsert-plan.md):
	 * `append to u (...) or replace (...) where <cond>` — literally the
	 * already-defined AstReplace node, minus its own target range (implied
	 * to be this statement's own target range — see Rules\Append). Only
	 * meaningful for the literal-values form; the insert-from-select form's
	 * grammar never reaches an `or` token, so this is always null there.
	 */
	class AstAppend extends Ast {

		private AstRangeDatabase|AstRangeTable|AstRangeJsonSource $range;

		/** @var AstAssignment[][]|null */
		private ?array $rows;

		/** @var string[]|null */
		private ?array $columns;

		private ?AstRetrieve $source;

		private ?AstReplace $onConflict;

		/**
		 * @param AstRangeDatabase|AstRangeTable|AstRangeJsonSource $range Resolved target range
		 * @param AstAssignment[][]|null $rows Assignment rows (literal-values form)
		 * @param string[]|null $columns Bare column list (insert-from-select form)
		 * @param AstRetrieve|null $source Source query (insert-from-select form)
		 * @param AstReplace|null $onConflict Upsert's `or replace (...) where ...` clause
		 */
		private function __construct(AstRangeDatabase|AstRangeTable|AstRangeJsonSource $range, ?array $rows, ?array $columns, ?AstRetrieve $source, ?AstReplace $onConflict = null) {
			$this->range = $range;
			$this->rows = $rows;
			$this->columns = $columns;
			$this->source = $source;
			$this->onConflict = $onConflict;

			foreach ($this->rows ?? [] as $row) {
				foreach ($row as $assignment) {
					$assignment->setParent($this);
				}
			}

			$this->source?->setParent($this);
			$this->onConflict?->setParent($this);
		}

		/**
		 * @param AstRangeDatabase|AstRangeTable|AstRangeJsonSource $range
		 * @param AstAssignment[][] $rows
		 * @param AstReplace|null $onConflict Upsert's `or replace (...) where ...` clause
		 * @return self
		 */
		public static function forValues(AstRangeDatabase|AstRangeTable|AstRangeJsonSource $range, array $rows, ?AstReplace $onConflict = null): self {
			return new self($range, $rows, null, null, $onConflict);
		}

		/**
		 * @param AstRangeDatabase|AstRangeTable $range
		 * @param string[] $columns
		 * @param AstRetrieve $source
		 * @return self
		 */
		public static function forSelect(AstRangeDatabase|AstRangeTable $range, array $columns, AstRetrieve $source): self {
			return new self($range, null, $columns, $source);
		}

		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);

			foreach ($this->rows ?? [] as $row) {
				foreach ($row as $assignment) {
					$assignment->accept($visitor);
				}
			}

			$this->source?->accept($visitor);
			$this->onConflict?->accept($visitor);
		}

		public function getRange(): AstRangeDatabase|AstRangeTable|AstRangeJsonSource {
			return $this->range;
		}

		/**
		 * @return string|null The target entity class name, or null when the
		 *         target is a plain-table or JSON-source range (see
		 *         getTableName()/getJsonSourcePath()).
		 */
		public function getEntityName(): ?string {
			return $this->range instanceof AstRangeDatabase ? $this->range->getEntityName() : null;
		}

		/**
		 * @return string|null The target's physical table name, or null when
		 *         the target is an entity or JSON-source range (see
		 *         getEntityName()/getJsonSourcePath()).
		 */
		public function getTableName(): ?string {
			return $this->range instanceof AstRangeTable ? $this->range->getTableName() : null;
		}

		/**
		 * Same as getTableName(), for call sites already committed to the
		 * plain-table-range form (typically behind a getEntityName() === null
		 * check, having already ruled out a JSON-source range target — see
		 * AppendExecutor, the only production caller of the SQL compilers
		 * this feeds) where a null result would mean this node targets some
		 * other range kind — a real bug, not a case to handle.
		 */
		public function getTableNameOrFail(): string {
			$tableName = $this->getTableName();

			if ($tableName === null) {
				throw new \LogicException('AstAppend::getTableNameOrFail() called on a statement whose target range is not a plain table');
			}

			return $tableName;
		}

		/**
		 * @return string|null The target JSON file's path, or null when the
		 *         target is an entity or plain-table range (see
		 *         getEntityName()/getTableName()).
		 */
		public function getJsonSourcePath(): ?string {
			return $this->range instanceof AstRangeJsonSource ? $this->range->getPath() : null;
		}

		public function isInsertFromSelect(): bool {
			return $this->source !== null;
		}

		/**
		 * @return AstAssignment[][]|null
		 */
		public function getRows(): ?array {
			return $this->rows;
		}

		/**
		 * Same as getRows(), for call sites already committed to the
		 * literal-values form (typically behind an isInsertFromSelect()
		 * check) where a null result would mean this node is actually the
		 * other shape — a real bug, not a case to handle.
		 * @return AstAssignment[][]
		 */
		public function getRowsOrFail(): array {
			if ($this->rows === null) {
				throw new \LogicException('AstAppend::getRowsOrFail() called on an insert-from-select statement, which has no rows');
			}

			return $this->rows;
		}

		/**
		 * @return string[]|null
		 */
		public function getColumns(): ?array {
			return $this->columns;
		}

		/**
		 * Same as getColumns(), for call sites already committed to the
		 * insert-from-select form (behind an isInsertFromSelect() check)
		 * where a null result would mean this node is actually the other
		 * shape — a real bug, not a case to handle.
		 * @return string[]
		 */
		public function getColumnsOrFail(): array {
			if ($this->columns === null) {
				throw new \LogicException('AstAppend::getColumnsOrFail() called on a literal-values statement, which has no columns');
			}

			return $this->columns;
		}

		public function getSource(): ?AstRetrieve {
			return $this->source;
		}

		/**
		 * Same as getSource(), for call sites already committed to the
		 * insert-from-select form (behind an isInsertFromSelect() check)
		 * where a null result would mean this node is actually the other
		 * shape — a real bug, not a case to handle.
		 */
		public function getSourceOrFail(): AstRetrieve {
			if ($this->source === null) {
				throw new \LogicException('AstAppend::getSourceOrFail() called on a literal-values statement, which has no source');
			}

			return $this->source;
		}

		public function getOnConflict(): ?AstReplace {
			return $this->onConflict;
		}

		public function deepClone(): static {
			$clonedOnConflict = $this->onConflict?->deepClone();
			$clonedRange = $this->range->deepClone();

			if ($this->source !== null) {
				// @phpstan-ignore-next-line new.static
				$clone = new static($clonedRange, null, $this->columns, $this->source->deepClone(), $clonedOnConflict);
			} else {
				$clonedRows = array_map(
					fn(array $row) => $this->cloneArray($row),
					$this->rows ?? []
				);

				// @phpstan-ignore-next-line new.static
				$clone = new static($clonedRange, $clonedRows, null, null, $clonedOnConflict);
			}

			$clone->setParent($this->getParent());
			return $clone;
		}
	}
