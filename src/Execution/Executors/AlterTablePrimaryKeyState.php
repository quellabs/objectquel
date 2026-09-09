<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	/**
	 * Typed carrier for AlterTableExecutor::resolvePrimaryKeyState()'s
	 * result: the table's current primary-key columns (empty when it has
	 * none) and, on pgsql/sqlsrv, the constraint name needed to `DROP
	 * CONSTRAINT` it — resolved via schema introspection since
	 * QuelToSQLAlter has no connection of its own. Mirrors
	 * FulltextPrerequisites's role for CreateIndexExecutor.
	 */
	final class AlterTablePrimaryKeyState {

		/** @var string[] */
		private array $columns;

		private ?string $constraintName;

		/**
		 * @param string[] $columns
		 * @param string|null $constraintName
		 */
		public function __construct(array $columns, ?string $constraintName) {
			$this->columns = $columns;
			$this->constraintName = $constraintName;
		}

		/**
		 * @return string[]
		 */
		public function getColumns(): array {
			return $this->columns;
		}

		public function getConstraintName(): ?string {
			return $this->constraintName;
		}
	}
