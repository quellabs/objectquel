<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterAddForeignKey;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterAddIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterDropIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterDropPrimaryKey;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterOperation;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterSetPrimaryKey;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterTable;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstStatement;
	use Quellabs\ObjectQuel\Execution\ExecutionContext;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLAlter;

	/**
	 * Executes an AstAlterTable statement: compiles it via QuelToSQLAlter
	 * (column/primary-key sub-operations) plus CreateIndexExecutor/
	 * DestroyIndexExecutor (index sub-operations, reused as sugar rather
	 * than reimplemented — see objectquel-index-clause-design.md, "Sugar,
	 * not reimplementation") and runs the resulting DDL statements directly
	 * against the connection, in order, stopping at the first failure.
	 * Mirrors CreateTableExecutor/CreateIndexExecutor/DestroyIndexExecutor.
	 *
	 * Bypasses the `retrieve` pipeline entirely — none of it applies to a DDL
	 * statement with no rows to return.
	 *
	 * Column and primary-key sub-operations always run before index
	 * sub-operations, regardless of their declared order in the statement,
	 * so an index on a column added in the same statement has something to
	 * index by the time it runs (see objectquel-index-clause-design.md,
	 * decision 3).
	 *
	 * A `primary key (...)`/`drop primary key` sub-operation needs one
	 * piece of schema information QuelToSQLAlter has no connection to look
	 * up itself — the table's current primary-key columns, and (pgsql/
	 * sqlsrv only) its constraint name — resolved here, via the
	 * connection, before compiling. Mirrors CreateIndexExecutor's fulltext
	 * prerequisite resolution.
	 *
	 * Where the platform supports transactional DDL
	 * (PlatformCapabilitiesInterface::supportsTransactionalDDL() —
	 * PostgreSQL, SQLite, SQL Server), the whole statement sequence wraps
	 * in one transaction. Where it doesn't (MySQL/MariaDB — DDL
	 * auto-commits per statement), the sequence runs best-effort,
	 * statement by statement — a failure partway through leaves earlier
	 * statements applied, same as a hand-written migration issuing several
	 * separate `$this->execute()` calls already would (see
	 * objectquel-index-clause-design.md, decision 4).
	 */
	class AlterTableExecutor implements DdlStatementExecutorInterface {

		private DatabaseAdapter $connection;

		private QuelToSQLAlter $compiler;

		private PlatformCapabilitiesInterface $platform;

		private CreateIndexExecutor $createIndexExecutor;

		private DestroyIndexExecutor $destroyIndexExecutor;

		private DdlRunner $ddlRunner;

		/**
		 * AlterTableExecutor constructor
		 * @param DatabaseAdapter $connection
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(DatabaseAdapter $connection, PlatformCapabilitiesInterface $platform) {
			$this->connection = $connection;
			$this->platform = $platform;
			$this->compiler = new QuelToSQLAlter($platform);
			$this->createIndexExecutor = new CreateIndexExecutor($connection, $platform);
			$this->destroyIndexExecutor = new DestroyIndexExecutor($connection, $platform);
			$this->ddlRunner = new DdlRunner($connection);
		}

		/**
		 * Compile and execute an `alter Name (op {, op})` statement.
		 * @param AstStatement $statement
		 * @param ExecutionContext $context
		 * @return void
		 * @throws QuelException On DDL failure, or if a sub-operation isn't
		 *         representable on the connected engine
		 */
		public function execute(AstStatement $statement, ExecutionContext $context): void {
			assert($statement instanceof AstAlterTable);

			$this->ddlRunner->runTransactionally(
				$this->compileSql($statement),
				$this->platform,
				"Failed to alter table '{$statement->getTableName()}'",
				'table_alteration_error'
			);
		}

		/**
		 * Compiles an `alter Name (op {, op})` statement to SQL, used by
		 * execute() before running it. Still performs the primary-key
		 * prerequisite lookup (schema introspection — read-only) since that
		 * determines the SQL itself, and delegates index sub-operations to
		 * CreateIndexExecutor/DestroyIndexExecutor's own compileSql(),
		 * which does the same for their own prerequisites (sqlsrv/sqlite
		 * fulltext).
		 * @param AstAlterTable $statement
		 * @return list<string>
		 * @throws QuelException If a sub-operation isn't representable on
		 *         the connected engine
		 */
		public function compileSql(AstAlterTable $statement): array {
			$primaryKeyState = $this->needsPrimaryKeyState($statement)
				? $this->resolvePrimaryKeyState($statement->getTableName())
				: new AlterTablePrimaryKeyState([], null);

			$statements = $this->compiler->convertToSQL(
				new AstAlterTable($statement->getTableName(), $this->resolveForeignKeyOperations($statement->getOperations())),
				$primaryKeyState->getColumns(),
				$primaryKeyState->getConstraintName()
			);

			return array_merge($statements, $this->compileIndexOperationsSql($statement));
		}

		/**
		 * Compiles the SQL for this statement's add/drop index sub-operations,
		 * delegating to CreateIndexExecutor/DestroyIndexExecutor's own compileSql().
		 * @param AstAlterTable $statement
		 * @return list<string>
		 * @throws QuelException If a sub-operation isn't representable on
		 *         the connected engine
		 */
		private function compileIndexOperationsSql(AstAlterTable $statement): array {
			$statements = [];

			foreach ($statement->getOperations() as $operation) {
				if ($operation instanceof AstAlterAddIndex) {
					$statements[] = $this->createIndexExecutor->compileSql(AstCreateIndex::fromEntry($statement->getTableName(), $operation));
				} elseif ($operation instanceof AstAlterDropIndex) {
					$statements[] = $this->destroyIndexExecutor->compileSql(new AstDestroyIndex($operation->getIndexName(), $statement->getTableName()));
				}
			}

			return array_merge([], ...$statements);
		}

		/**
		 * Defaults a column-less `references Table` to the target's primary
		 * key. Skipped on engines with no named-foreign-key support
		 * (SQLite): QuelToSQLAlter rejects `add foreign key` there
		 * regardless of the referenced column, so resolving it first would
		 * be a wasted round trip that can also mask the real error.
		 * @param AstAlterOperation[] $operations
		 * @return AstAlterOperation[]
		 */
		private function resolveForeignKeyOperations(array $operations): array {
			if (!$this->platform->supportsNamedForeignKeys()) {
				return $operations;
			}

			$resolved = [];

			foreach ($operations as $operation) {
				if ($operation instanceof AstAlterAddForeignKey && $operation->getReferencedColumn() === null) {
					$referencedColumn = ForeignKeyReferenceResolver::resolveReferencedColumn($this->connection, $operation->getReferencedTable());
					$operation = $operation->withReferencedColumn($referencedColumn);
				}

				$resolved[] = $operation;
			}

			return $resolved;
		}

		/**
		 * Whether $statement contains a `primary key (...)`/`drop primary key`
		 * sub-operation, which needs resolvePrimaryKeyState()'s schema lookup.
		 * @param AstAlterTable $statement
		 * @return bool
		 */
		private function needsPrimaryKeyState(AstAlterTable $statement): bool {
			foreach ($statement->getOperations() as $operation) {
				if ($operation instanceof AstAlterSetPrimaryKey || $operation instanceof AstAlterDropPrimaryKey) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Resolves the table's current primary-key columns and, on
		 * pgsql/sqlsrv, the constraint name needed to `DROP CONSTRAINT` it.
		 * MySQL/MariaDB never need a name (`DROP PRIMARY KEY` is a fixed
		 * clause) and SQLite rejects primary-key changes outright in
		 * QuelToSQLAlter before this name is ever used, so it's left null
		 * for both.
		 * @param string $tableName
		 * @return AlterTablePrimaryKeyState
		 */
		private function resolvePrimaryKeyState(string $tableName): AlterTablePrimaryKeyState {
			$columns = $this->connection->getPrimaryKeyColumns($tableName);

			if (!in_array($this->platform->getDatabaseType(), ['pgsql', 'sqlsrv'], true)) {
				return new AlterTablePrimaryKeyState($columns, null);
			}

			$constraintName = null;

			foreach ($this->connection->getIndexes($tableName) as $indexName => $index) {
				if ($index['type'] === 'primary') {
					$constraintName = $indexName;
					break;
				}
			}

			return new AlterTablePrimaryKeyState($columns, $constraintName);
		}
	}
