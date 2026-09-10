<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Execution\ExecutionContext;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateTable;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstStatement;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLCreate;

	/**
	 * Executes an AstCreateTable statement: compiles it via QuelToSQLCreate
	 * (the CREATE TABLE statement itself) plus CreateIndexExecutor (any
	 * embedded index entries, reused as sugar rather than reimplemented —
	 * see objectquel-index-clause-design.md, "Sugar, not reimplementation")
	 * and runs the resulting DDL statements directly against the
	 * connection, in order, stopping at the first failure.
	 *
	 * Bypasses the `retrieve` pipeline entirely — none of it applies to a DDL
	 * statement with no rows to return. Mirrors TempTableExecutor's existing
	 * precedent of building and running DDL directly rather than through
	 * BuildSqlFromAst, which is a retrieve-pipeline expression visitor, not a
	 * top-level statement compiler.
	 *
	 * The table is created first, then embedded indexes run in declaration
	 * order (see objectquel-index-clause-design.md, decision 3) — there's
	 * nothing to index before the table exists.
	 *
	 * Where the platform supports transactional DDL
	 * (PlatformCapabilitiesInterface::supportsTransactionalDDL() — PostgreSQL,
	 * SQLite, SQL Server), the whole statement sequence wraps in one
	 * transaction. Where it doesn't (MySQL/MariaDB — DDL auto-commits per
	 * statement), execute() compensates instead: a failure anywhere in the
	 * sequence drops the table it just created, unless `if not exists`
	 * matched a table that already existed (in which case `CREATE TABLE` was
	 * a no-op and dropping it would destroy pre-existing data). That check
	 * reads information_schema, which never lists MySQL session-temp tables
	 * — so a `create temporary ... if not exists` re-matching a same-session
	 * temp table is indistinguishable from "didn't exist" and would still be
	 * dropped on a later failure. Left out of scope: narrow, session-scoped,
	 * lower blast radius than the permanent-table case this guards against.
	 */
	class CreateTableExecutor implements DdlStatementExecutorInterface {

		private DatabaseAdapter $connection;

		/**
		 * Compiles the AstCreateTable statement to dialect-correct SQL.
		 * @var QuelToSQLCreate
		 */
		private QuelToSQLCreate $compiler;

		private PlatformCapabilitiesInterface $platform;

		private CreateIndexExecutor $createIndexExecutor;

		private DdlRunner $ddlRunner;

		private SqlIdentifierQuoter $identifierQuoter;

		/**
		 * CreateTableExecutor constructor
		 * @param DatabaseAdapter $connection
		 * @param PlatformCapabilitiesInterface $platform
		 */
		public function __construct(DatabaseAdapter $connection, PlatformCapabilitiesInterface $platform) {
			$this->connection = $connection;
			$this->platform = $platform;
			$this->compiler = new QuelToSQLCreate($platform);
			$this->createIndexExecutor = new CreateIndexExecutor($connection, $platform);
			$this->ddlRunner = new DdlRunner($connection);
			$this->identifierQuoter = new SqlIdentifierQuoter($platform);
		}

		/**
		 * Compile and execute a `create [temporary] Name (...)` statement.
		 * @param AstStatement $statement
		 * @param ExecutionContext $context
		 * @return void
		 * @throws QuelException On DDL failure
		 */
		public function execute(AstStatement $statement, ExecutionContext $context): void {
			assert($statement instanceof AstCreateTable);

			$statements = $this->compileSql($statement);
			$failureMessage = "Failed to create table '{$statement->getTableName()}'";
			$errorCode = 'table_creation_error';

			if ($this->platform->supportsTransactionalDDL()) {
				$this->ddlRunner->runTransactionally($statements, $this->platform, $failureMessage, $errorCode);
				return;
			}

			$physicalTableName = $this->compiler->getPhysicalTableName($statement);
			$tableExistedBefore = in_array($physicalTableName, $this->connection->getTables(), true);

			try {
				$this->ddlRunner->run($statements, $failureMessage, $errorCode);
			} catch (\Throwable $e) {
				if (!$tableExistedBefore) {
					$this->compensateByDroppingTable($physicalTableName);
				}

				throw $e;
			}
		}

		/**
		 * Best-effort cleanup after a failed MySQL/MariaDB `create` sequence.
		 * Failures here are silently ignored — execute()'s original exception
		 * is what surfaces either way, and masking it with a cleanup failure
		 * would only hide the real error.
		 * @param string $physicalTableName
		 * @return void
		 */
		private function compensateByDroppingTable(string $physicalTableName): void {
			$this->connection->execute('DROP TABLE IF EXISTS ' . $this->identifierQuoter->quoteIdentifier($physicalTableName));
		}

		/**
		 * Compiles a `create [temporary] Name (...)` statement to SQL, used
		 * by execute() before running it. Embedded index entries delegate to
		 * CreateIndexExecutor's own compileSql(), which does the same for
		 * their own prerequisites (sqlsrv/sqlite fulltext).
		 * @param AstCreateTable $statement
		 * @return list<string>
		 * @throws QuelException If an embedded index isn't representable on
		 *         the connected engine
		 */
		public function compileSql(AstCreateTable $statement): array {
			$statement = $this->resolveForeignKeys($statement);
			$statements = [$this->compiler->convertToSQL($statement)];

			if ($statement->getIndexes() !== []) {
				$physicalTableName = $this->compiler->getPhysicalTableName($statement);

				foreach ($statement->getIndexes() as $index) {
					$statements = [
						...$statements,
						...$this->createIndexExecutor->compileSql(AstCreateIndex::fromEntry($physicalTableName, $index)),
					];
				}
			}

			return $statements;
		}

		/**
		 * Defaults a column-less `references Table` to the target's primary key.
		 */
		private function resolveForeignKeys(AstCreateTable $statement): AstCreateTable {
			$foreignKeys = $statement->getForeignKeys();

			if ($foreignKeys === []) {
				return $statement;
			}

			$resolvedForeignKeys = [];

			foreach ($foreignKeys as $foreignKey) {
				if ($foreignKey->getReferencedColumn() === null) {
					$referencedColumn = ForeignKeyReferenceResolver::resolveReferencedColumn($this->connection, $foreignKey->getReferencedTable());
					$foreignKey = $foreignKey->withReferencedColumn($referencedColumn);
				}

				$resolvedForeignKeys[] = $foreignKey;
			}

			return new AstCreateTable(
				$statement->getTableName(),
				$statement->getColumns(),
				$statement->isTemporary(),
				$statement->isIfNotExists(),
				$statement->getPrimaryKeyColumns(),
				$statement->getIndexes(),
				$resolvedForeignKeys
			);
		}
	}
