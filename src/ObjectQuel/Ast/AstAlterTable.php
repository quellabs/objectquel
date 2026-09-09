<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * An `alter Name (op {, op})` statement — a top-level statement, not
	 * part of a `retrieve` query. Compiled and executed directly (see
	 * Execution\Executors\AlterTableExecutor), bypassing the retrieve
	 * pipeline entirely, same as AstCreateTable/AstDestroy.
	 *
	 * One table target per statement, matching `create`/`destroy`'s
	 * one-object-per-statement invariant (see AstDestroy's docblock).
	 * $operations holds every sub-operation in declaration order — one node
	 * per AstAlterOperation implementor (add/drop/rename/retype a column,
	 * set/drop the primary key, add/drop an index). See
	 * objectquel-alter-table-design.md.
	 *
	 * Execution order is NOT declaration order for every op kind: column
	 * and primary-key sub-operations always run before index
	 * sub-operations, so an index on a column added in the same statement
	 * has something to index by the time it runs (see
	 * objectquel-index-clause-design.md, decision 3) — AlterTableExecutor
	 * partitions $operations by kind rather than replaying them in the raw
	 * declared order.
	 */
	class AstAlterTable extends Ast implements AstStatement {

		private string $tableName;

		/** @var AstAlterOperation[] */
		private array $operations;

		/**
		 * AstAlterTable constructor.
		 * @param string $tableName
		 * @param AstAlterOperation[] $operations
		 */
		public function __construct(string $tableName, array $operations) {
			$this->tableName = $tableName;
			$this->operations = $operations;

			foreach ($this->operations as $operation) {
				if ($operation instanceof Ast) {
					$operation->setParent($this);
				}
			}
		}

		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);

			foreach ($this->operations as $operation) {
				if ($operation instanceof Ast) {
					$operation->accept($visitor);
				}
			}
		}

		public function getTableName(): string {
			return $this->tableName;
		}

		/**
		 * @return AstAlterOperation[]
		 */
		public function getOperations(): array {
			return $this->operations;
		}

		public function deepClone(): static {
			$clonedOperations = $this->cloneArray($this->operations);

			// @phpstan-ignore-next-line new.static
			$clone = new static($this->tableName, $clonedOperations);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
