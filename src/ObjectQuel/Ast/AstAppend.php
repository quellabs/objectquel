<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * An `append to <range/entity> (...)` statement — a top-level statement,
	 * not part of a `retrieve` query. Compiled and executed directly (see
	 * Execution\Executors\AppendExecutor and ObjectQuel\QuelToSQLAppend),
	 * bypassing the retrieve pipeline entirely, same as AstCreateTable/
	 * AstDestroy.
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
	 * $entityName is already resolved at parse time to the target entity's
	 * class name, whether the statement named a declared range alias or a
	 * bare entity name directly — a range with nothing to read from isn't
	 * required, only to know which entity/table (see objectquel-append-plan.md).
	 */
	class AstAppend extends Ast {

		private string $entityName;

		/** @var AstAssignment[][]|null */
		private ?array $rows;

		/** @var string[]|null */
		private ?array $columns;

		private ?AstRetrieve $source;

		/**
		 * @param string $entityName Resolved target entity class name
		 * @param AstAssignment[][]|null $rows Assignment rows (literal-values form)
		 * @param string[]|null $columns Bare column list (insert-from-select form)
		 * @param AstRetrieve|null $source Source query (insert-from-select form)
		 */
		private function __construct(string $entityName, ?array $rows, ?array $columns, ?AstRetrieve $source) {
			$this->entityName = $entityName;
			$this->rows = $rows;
			$this->columns = $columns;
			$this->source = $source;

			foreach ($this->rows ?? [] as $row) {
				foreach ($row as $assignment) {
					$assignment->setParent($this);
				}
			}

			$this->source?->setParent($this);
		}

		/**
		 * @param string $entityName
		 * @param AstAssignment[][] $rows
		 * @return self
		 */
		public static function forValues(string $entityName, array $rows): self {
			return new self($entityName, $rows, null, null);
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

		public function deepClone(): static {
			if ($this->source !== null) {
				// @phpstan-ignore-next-line new.static
				$clone = new static($this->entityName, null, $this->columns, $this->source->deepClone());
			} else {
				$clonedRows = array_map(
					fn(array $row) => $this->cloneArray($row),
					$this->rows ?? []
				);

				// @phpstan-ignore-next-line new.static
				$clone = new static($this->entityName, $clonedRows, null, null);
			}

			$clone->setParent($this->getParent());
			return $clone;
		}
	}
