<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Runs an ordered list of DDL statements against a connection, stopping
	 * at (and reporting) the first failure — optionally wrapped in a
	 * transaction when the platform supports transactional DDL. Shared by
	 * CreateTableExecutor/AlterTableExecutor (transactional) and
	 * CreateIndexExecutor/DestroyIndexExecutor (non-transactional; see
	 * objectquel-index-clause-design.md, decision 4 — transaction wrapping
	 * only applies to the `create`/`alter` statement sequence, not standalone
	 * `create index`/`destroy index`), so this loop-and-throw skeleton exists
	 * in exactly one place instead of four.
	 */
	final class DdlRunner {

		private DatabaseAdapter $connection;

		public function __construct(DatabaseAdapter $connection) {
			$this->connection = $connection;
		}

		/**
		 * Runs $statements in order, throwing on the first one that fails.
		 * @param list<string> $statements
		 * @param string $failureMessage Prefix used for the exception message; the connection's own last error is appended
		 * @param string $errorCode
		 * @throws QuelException On the first failing statement
		 */
		public function run(array $statements, string $failureMessage, string $errorCode): void {
			foreach ($statements as $sql) {
				// execute() swallows the exception and returns null on failure
				// rather than throwing — a try/catch here would never fire.
				if ($this->connection->execute($sql) === null) {
					throw new QuelException("{$failureMessage}: {$this->connection->getLastErrorMessage()}", $errorCode);
				}
			}
		}

		/**
		 * Runs $statements as run() does, wrapped in a transaction when
		 * $platform reports transactional DDL support; otherwise runs
		 * best-effort, statement by statement, same as run() alone (see
		 * objectquel-index-clause-design.md, decision 4).
		 * @param list<string> $statements
		 * @param string $failureMessage
		 * @param string $errorCode
		 * @throws QuelException On the first failing statement
		 * @throws \Throwable Any other exception raised while running $statements — rolled back before rethrowing
		 */
		public function runTransactionally(array $statements, PlatformCapabilitiesInterface $platform, string $failureMessage, string $errorCode): void {
			if (!$platform->supportsTransactionalDDL()) {
				$this->run($statements, $failureMessage, $errorCode);
				return;
			}

			$this->connection->beginTrans();

			try {
				$this->run($statements, $failureMessage, $errorCode);
			} catch (\Throwable $e) {
				$this->connection->rollbackTrans();
				throw $e;
			}

			$this->connection->commitTrans();
		}
	}
