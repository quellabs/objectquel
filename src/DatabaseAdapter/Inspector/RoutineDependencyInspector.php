<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Inspector;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Scans live attachments (triggers, and PostgreSQL's generated helper functions) for a
	 * call to a given routine, so `destroy function` can refuse while one still depends on it,
	 * and for any attachment at all on a given table, so an `alter table` that would change a
	 * mapped column can refuse while one still exists — see "Attachment dependency discovery"
	 * in objectquel-equel-triggers-design.md. There is no separate attachment registry; the
	 * live DDL is the only source of truth, so this is a DDL-time, rare-operation scan, not
	 * something run per query.
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
			$pattern = $this->callPattern($routineName);
			$dependents = [];

			foreach ($this->listTriggerBodies() as ['trigger_name' => $name, 'body' => $body]) {
				if ($body !== '' && preg_match($pattern, $body) === 1) {
					$dependents[] = $name;
				}
			}

			return $dependents;
		}

		/**
		 * Finds every attachment trigger on a table, regardless of which routine it calls — so
		 * an `alter table` changing a mapped column (drop/rename/retype, or a primary-key
		 * change SQL Server's row-pairing relies on) can refuse while any attachment still
		 * depends on the table's current column shape. A trigger only counts when the routine
		 * it calls can actually be read back with `trigger`-declared ObjectQuel metadata —
		 * otherwise it's either not ours, or not an attachment at all (e.g. a user trigger that
		 * happens to also call some unrelated procedure), and alter table has no reason to care.
		 * @param string $table Physical table being altered
		 * @return list<string> Names of attachment triggers found on it; empty when none exist
		 * @throws QuelException When the lookup fails or triggers are unsupported
		 */
		public function findAttachmentTriggersOnTable(string $table): array {
			$attachments = [];

			foreach ($this->listTriggerBodies($table) as ['trigger_name' => $name, 'body' => $body]) {
				$routineName = $this->extractCalledRoutineName($body);

				if ($routineName !== null && $this->isTriggerDeclaredRoutine($routineName)) {
					$attachments[] = $name;
				}
			}

			return $attachments;
		}

		/**
		 * @param string $routineName Routine name recovered from a trigger body
		 * @return bool True when it exists and its metadata reports returnType 'trigger'
		 */
		private function isTriggerDeclaredRoutine(string $routineName): bool {
			try {
				return ($this->connection->getRoutineMetadata($routineName)['returnType'] ?? null) === 'trigger';
			} catch (QuelException) {
				// Missing, ambiguous, or without recognizable ObjectQuel metadata — not an attachment of ours.
				return false;
			}
		}

		/**
		 * Recovers the routine name a trigger body/helper source calls, exactly as this
		 * package's own lowerings emit it (CALL/EXEC followed by one quoted identifier,
		 * optionally schema-qualified) — never a bare substring match. EQUEL identifiers are
		 * plain word characters, so unlike callPattern()'s exact-match case this needs no
		 * doubled-quote unescaping.
		 * @param string $body Trigger definition or helper function source
		 * @return string|null Unquoted routine name, or null when the body doesn't call anything this way
		 */
		private function extractCalledRoutineName(string $body): ?string {
			[$verb, $open, $close] = match ($this->connection->getDatabaseType()) {
				'pgsql' => ['CALL', '"', '"'],
				'sqlsrv' => ['EXEC', '[', ']'],
				default => ['CALL', '`', '`'],
			};

			$openQuoted = preg_quote($open, '/');
			$closeQuoted = preg_quote($close, '/');
			$schemaPrefix = "(?:{$openQuoted}\\w+{$closeQuoted}\\.)?";
			$pattern = "/{$verb}\\s+{$schemaPrefix}{$openQuoted}(\\w+){$closeQuoted}/i";

			return preg_match($pattern, $body, $matches) === 1 ? $matches[1] : null;
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
		 * Lists every trigger's name and the body to search for a call: the trigger definition
		 * itself for MySQL/MariaDB and SQL Server, or the generated helper function's source for
		 * PostgreSQL (triggers there call the helper, not the routine directly — see
		 * PostgresEventAttachmentLowering).
		 * @param string|null $table Restrict to triggers on this physical table, or null for every table
		 * @return list<array{trigger_name: string, body: string}>
		 * @throws QuelException When the lookup fails or triggers are unsupported
		 */
		private function listTriggerBodies(?string $table = null): array {
			[$sql, $parameters] = $this->listQuery($table);
			$result = $this->connection->execute($sql, $parameters);

			if ($result === null) {
				throw new QuelException("Failed to scan triggers: {$this->connection->getLastErrorMessage()}", 'routine_destruction_error');
			}

			$rows = [];

			foreach ($result->fetchAll('assoc') as $row) {
				$rows[] = ['trigger_name' => (string)$row['trigger_name'], 'body' => (string)($row['body'] ?? '')];
			}

			return $rows;
		}

		/**
		 * @param string|null $table Restrict to triggers on this physical table, or null for every table
		 * @return array{string, array<string, string>} SQL and its parameters
		 * @throws QuelException When the engine has no triggers
		 */
		private function listQuery(?string $table): array {
			return match ($this->connection->getDatabaseType()) {
				'pgsql' => [
					'SELECT t.tgname AS trigger_name, p.prosrc AS body FROM pg_trigger t JOIN pg_proc p ON p.oid = t.tgfoid JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal' . ($table === null ? '' : ' AND c.relname = :table'),
					$table === null ? [] : ['table' => $table],
				],

				'sqlsrv' => [
					'SELECT t.name AS trigger_name, sm.definition AS body FROM sys.triggers t JOIN sys.sql_modules sm ON sm.object_id = t.object_id JOIN sys.objects o ON o.object_id = t.parent_id' . ($table === null ? '' : ' WHERE o.name = :table'),
					$table === null ? [] : ['table' => $table],
				],

				'mysql', 'mariadb' => [
					'SELECT TRIGGER_NAME AS trigger_name, ACTION_STATEMENT AS body FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()' . ($table === null ? '' : ' AND EVENT_OBJECT_TABLE = :table'),
					$table === null ? [] : ['table' => $table],
				],

				default => throw new QuelException("Triggers can't be scanned on '{$this->connection->getDatabaseType()}'.", 'routine_destruction_error'),
			};
		}
	}
