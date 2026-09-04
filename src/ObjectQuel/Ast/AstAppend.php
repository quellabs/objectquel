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
	 * $entityName is already resolved at parse time to the target range's
	 * entity class name (see objectquel-append-plan.md).
	 *
	 * $onConflict is the upsert extension (see objectquel-upsert-plan.md):
	 * `append to u (...) or replace (...) where <cond>` — literally the
	 * already-defined AstReplace node, minus its own target range (implied
	 * to be this statement's own target range — see Rules\Append). Only
	 * meaningful for the literal-values form; the insert-from-select form's
	 * grammar never reaches an `or` token, so this is always null there.
	 */
	class AstAppend extends Ast {

		private string $entityName;

		/** @var AstAssignment[][]|null */
		private ?array $rows;

		/** @var string[]|null */
		private ?array $columns;

		private ?AstRetrieve $source;

		private ?AstReplace $onConflict;

		/**
		 * @param string $entityName Resolved target entity class name
		 * @param AstAssignment[][]|null $rows Assignment rows (literal-values form)
		 * @param string[]|null $columns Bare column list (insert-from-select form)
		 * @param AstRetrieve|null $source Source query (insert-from-select form)
		 * @param AstReplace|null $onConflict Upsert's `or replace (...) where ...` clause
		 */
		private function __construct(string $entityName, ?array $rows, ?array $columns, ?AstRetrieve $source, ?AstReplace $onConflict = null) {
			$this->entityName = $entityName;
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
		 * @param string $entityName
		 * @param AstAssignment[][] $rows
		 * @param AstReplace|null $onConflict Upsert's `or replace (...) where ...` clause
		 * @return self
		 */
		public static function forValues(string $entityName, array $rows, ?AstReplace $onConflict = null): self {
			return new self($entityName, $rows, null, null, $onConflict);
		}

		/**
		 * @param string $entityName
		 * @param string[] $columns
		 * @param AstRetrieve $source
		 * @return self
		 */
		public static function forSelect(string $entityName, array $columns, AstRetrieve $source): self {
			return new self($entityName, null, $columns, $source);
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

		public function getEntityName(): string {
			return $this->entityName;
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
		 * @return string[]|null
		 */
		public function getColumns(): ?array {
			return $this->columns;
		}

		public function getSource(): ?AstRetrieve {
			return $this->source;
		}

		public function getOnConflict(): ?AstReplace {
			return $this->onConflict;
		}

		public function deepClone(): static {
			$clonedOnConflict = $this->onConflict?->deepClone();

			if ($this->source !== null) {
				// @phpstan-ignore-next-line new.static
				$clone = new static($this->entityName, null, $this->columns, $this->source->deepClone(), $clonedOnConflict);
			} else {
				$clonedRows = array_map(
					fn(array $row) => $this->cloneArray($row),
					$this->rows ?? []
				);

				// @phpstan-ignore-next-line new.static
				$clone = new static($this->entityName, $clonedRows, null, null, $clonedOnConflict);
			}

			$clone->setParent($this->getParent());
			return $clone;
		}
	}
