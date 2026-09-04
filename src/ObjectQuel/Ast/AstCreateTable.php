<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Ast;

	use Quellabs\ObjectQuel\ObjectQuel\AstVisitorInterface;

	/**
	 * Class AstCreateTable
	 *
	 * Represents a `create [temporary] Name (attr = type constraints, ...)`
	 * statement — a top-level ObjectQuel statement, not part of a `retrieve`
	 * query. Compiled and executed directly against the connection (see
	 * Execution\Executors\CreateTableExecutor); does not go through the
	 * retrieve pipeline (semantic analyzer, optimizer, planner, hydration),
	 * none of which applies to a DDL statement with no rows to return.
	 */
	class AstCreateTable extends Ast {

		private string $tableName;

		/** @var AstColumnDefinition[] */
		private array $columns;

		private bool $temporary;

		/**
		 * AstCreateTable constructor.
		 * @param string $tableName
		 * @param AstColumnDefinition[] $columns
		 * @param bool $temporary
		 */
		public function __construct(string $tableName, array $columns, bool $temporary) {
			$this->tableName = $tableName;
			$this->columns = $columns;
			$this->temporary = $temporary;

			foreach ($this->columns as $column) {
				$column->setParent($this);
			}
		}

		public function accept(AstVisitorInterface $visitor): void {
			parent::accept($visitor);

			foreach ($this->columns as $column) {
				$column->accept($visitor);
			}
		}

		public function getTableName(): string {
			return $this->tableName;
		}

		/**
		 * @return AstColumnDefinition[]
		 */
		public function getColumns(): array {
			return $this->columns;
		}

		public function isTemporary(): bool {
			return $this->temporary;
		}

		public function deepClone(): static {
			$clonedColumns = $this->cloneArray($this->columns);

			// @phpstan-ignore-next-line new.static
			$clone = new static($this->tableName, $clonedColumns, $this->temporary);
			$clone->setParent($this->getParent());
			return $clone;
		}
	}
