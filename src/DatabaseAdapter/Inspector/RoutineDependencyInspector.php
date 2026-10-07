<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Inspector;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Scans live attachments (triggers, and PostgreSQL's generated helper functions) for a
	 * call to a given routine, so `destroy function` can refuse while one still depends on it
	 * — see "Attachment dependency discovery" in objectquel-equel-triggers-design.md. There is
	 * no separate attachment registry; the live DDL is the only source of truth, so this is a
	 * DDL-time, rare-operation scan, not something run per query.
	 */
	class RoutineDependencyInspector {

		/**
		 * @param DatabaseAdapter $connection Connection whose catalog is read
		 */
		public function __construct(private readonly DatabaseAdapter $connection) {
		}

		/**
		 * Finds every trigger whose body (MySQL/MariaDB, SQL Server) or generated helper
		 * function (PostgreSQL) calls the given routine by its schema-qualified reference, not
		 * an arbitrary substring match.
		 * @param string $routineName Routine a `destroy function` would remove
		 * @return list<string> Names of dependent triggers; empty when none depend on it
		 * @throws QuelException When the lookup fails or triggers are unsupported
		 */
		public function findDependentTriggers(string $routineName): array {
			[$sql, $parameters] = $this->dependencyQuery();
			$result = $this->connection->execute($sql, $parameters);

			if ($result === null) {
				throw new QuelException("Failed to scan triggers for a dependency on '{$routineName}': {$this->connection->getLastErrorMessage()}", 'routine_destruction_error');
			}

			$pattern = $this->callPattern($routineName);
			$dependents = [];

			foreach ($result->fetchAll('assoc') as $row) {
				$body = (string)($row['body'] ?? '');

				if ($body !== '' && preg_match($pattern, $body) === 1) {
					$dependents[] = (string)$row['trigger_name'];
				}
			}

			return $dependents;
		}

		/**
		 * Builds a regex matching this routine's exact quoted, optionally schema-qualified
		 * reference as this package's own lowerings emit it — not a bare name substring, which
		 * could also match an unrelated longer identifier or a string literal.
		 * @param string $routineName Routine name as written
		 * @return string PCRE pattern
		 */
		private function callPattern(string $routineName): string {
			$quotedName = preg_quote($this->quoteIdentifier($routineName), '/');
			$schema = $this->connection->getRoutineSchema();
			$qualifiedName = $schema === null ? $quotedName : preg_quote($this->quoteIdentifier($schema), '/') . '\.' . $quotedName;
			$verb = $this->connection->getDatabaseType() === 'sqlsrv' ? 'EXEC' : 'CALL';

			return "/{$verb}\\s+(?:{$qualifiedName}|{$quotedName})\\s*[\\s(]/i";
		}

		/**
		 * Quotes an identifier the same way the connected engine's SqlIdentifierQuoter would.
		 * Reimplemented here rather than depending on it, which needs a full
		 * PlatformCapabilitiesInterface this class has no other reason to carry.
		 * @param string $identifier Unquoted identifier
		 * @return string
		 */
		private function quoteIdentifier(string $identifier): string {
			return match ($this->connection->getDatabaseType()) {
				'pgsql' => '"' . str_replace('"', '""', $identifier) . '"',
				'sqlsrv' => '[' . str_replace(']', ']]', $identifier) . ']',
				default => '`' . str_replace('`', '``', $identifier) . '`',
			};
		}

		/**
		 * Builds the query listing every trigger's name and the body to search: the trigger
		 * definition itself for MySQL/MariaDB and SQL Server, or the generated helper
		 * function's source for PostgreSQL (triggers there call the helper, not the routine
		 * directly — see PostgresEventAttachmentLowering).
		 * @return array{string, array<string, string>} SQL and its parameters
		 * @throws QuelException When the engine has no triggers
		 */
		private function dependencyQuery(): array {
			return match ($this->connection->getDatabaseType()) {
				'pgsql' => [
					'SELECT t.tgname AS trigger_name, p.prosrc AS body FROM pg_trigger t JOIN pg_proc p ON p.oid = t.tgfoid WHERE NOT t.tgisinternal',
					[],
				],

				'sqlsrv' => [
					'SELECT t.name AS trigger_name, sm.definition AS body FROM sys.triggers t JOIN sys.sql_modules sm ON sm.object_id = t.object_id',
					[],
				],

				'mysql', 'mariadb' => [
					'SELECT TRIGGER_NAME AS trigger_name, ACTION_STATEMENT AS body FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()',
					[],
				],

				default => throw new QuelException("Triggers can't be scanned on '{$this->connection->getDatabaseType()}'.", 'routine_destruction_error'),
			};
		}
	}
