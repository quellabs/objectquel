<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Inspector;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\BindingEvent;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\EventBindingNaming;

	/**
	 * Scans live bindings (triggers, and PostgreSQL's generated helper functions) for a
	 * call to a given routine, so `destroy function` can refuse while one still depends on it,
	 * for any binding at all on a given table, so an `alter table` that would change a
	 * mapped column can refuse while one still exists, and for every binding in the schema,
	 * for `quel:list-triggers` — see "Binding dependency discovery" in
	 * objectquel-equel-triggers-design.md. There is no separate binding registry; the live
	 * DDL is the only source of truth, so this is a DDL-time, rare-operation scan, not
	 * something run per query.
	 * @phpstan-import-type BindingListEntry from DatabaseAdapter
	 */
	class RoutineDependencyInspector {

		private readonly DatabaseAdapter $connection;

		/**
		 * @param DatabaseAdapter $connection Connection whose catalog is read
		 */
		public function __construct(DatabaseAdapter $connection) {
			$this->connection = $connection;
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
		 * Finds every binding trigger on a table, regardless of which routine it calls — so
		 * an `alter table` changing a mapped column (drop/rename/retype, or a primary-key
		 * change SQL Server's row-pairing relies on) can refuse while any binding still
		 * depends on the table's current column shape. A trigger only counts when the routine
		 * it calls can actually be read back with `isTrigger` ObjectQuel metadata —
		 * otherwise it's either not ours, or not a binding at all (e.g. a user trigger that
		 * happens to also call some unrelated procedure), and alter table has no reason to care.
		 * @param string $table Physical table being altered
		 * @return list<string> Names of binding triggers found on it; empty when none exist
		 * @throws QuelException When the lookup fails or triggers are unsupported
		 */
		public function findBindingTriggersOnTable(string $table): array {
			$bindings = [];

			foreach ($this->listTriggerBodies($table) as ['trigger_name' => $name, 'body' => $body]) {
				$routineName = $this->extractCalledRoutineName($body);

				if ($routineName !== null && $this->isTriggerDeclaredRoutine($routineName)) {
					$bindings[] = $name;
				}
			}

			return $bindings;
		}

		/**
		 * Lists every live binding in the connected schema — every trigger whose body (or,
		 * on PostgreSQL, generated helper) calls a tfunction — for
		 * `quel:list-triggers`.
		 * @return list<BindingListEntry>
		 * @throws QuelException When the lookup fails or triggers are unsupported
		 */
		public function listBindings(): array {
			$bindings = [];

			foreach ($this->listTriggerBodies() as ['trigger_name' => $name, 'table' => $table, 'event' => $nativeEvent, 'body' => $body]) {
				$routineName = $this->extractCalledRoutineName($body);

				if ($routineName === null) {
					continue;
				}

				$event = self::normalizeEvent($nativeEvent);

				if ($event === null) {
					continue;
				}

				if (!$this->isTriggerDeclaredRoutine($routineName)) {
					continue;
				}

				$bindings[] = [
					'table'   => $table,
					'event'   => $event,
					'routine' => $routineName,
					'alias'   => self::aliasFromTriggerName($name, $table),
				];
			}

			usort($bindings, static function (array $a, array $b): int {
				$tableOrder = $a['table'] <=> $b['table'];

				if ($tableOrder !== 0) {
					return $tableOrder;
				}

				return $a['alias'] <=> $b['alias'];
			});

			return $bindings;
		}

		/**
		 * Maps a native trigger event description back to EQUEL's own vocabulary.
		 * @param string $native 'INSERT'/'UPDATE'/'DELETE', or an engine-specific variant of one
		 * @return BindingEvent|null Null when it isn't one of the three physical write events
		 */
		private static function normalizeEvent(string $native): ?BindingEvent {
			return match (true) {
				str_contains($native, 'INSERT') => BindingEvent::Append,
				str_contains($native, 'UPDATE') => BindingEvent::Replace,
				str_contains($native, 'DELETE') => BindingEvent::Delete,
				default => null,
			};
		}

		/**
		 * Recovers the complete alias by removing the fixed table-hash prefix.
		 * Unrecognized trigger names are shown whole rather than guessed at.
		 * @param string $triggerName Physical trigger name
		 * @param string $table Physical table the trigger is on
		 * @return string
		 */
		private static function aliasFromTriggerName(string $triggerName, string $table): string {
			$prefix = EventBindingNaming::triggerNamePrefix($table);
			return str_starts_with($triggerName, $prefix) ? substr($triggerName, strlen($prefix)) : $triggerName;
		}

		/**
		 * @param string $routineName Routine name recovered from a trigger body
		 * @return bool True when it exists and its metadata reports isTrigger
		 */
		private function isTriggerDeclaredRoutine(string $routineName): bool {
			try {
				return (bool)($this->connection->getRoutineMetadata($routineName)['isTrigger'] ?? false);
			} catch (QuelException) {
				// Missing, ambiguous, or without recognizable ObjectQuel metadata — not a binding of ours.
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
		 * Lists every trigger's name, table, native event and the body to search for a call:
		 * the trigger definition itself for MySQL/MariaDB and SQL Server, or the generated
		 * helper function's source for PostgreSQL (triggers there call the helper, not the
		 * routine directly — see PostgresEventBindingLowering).
		 * @param string|null $table Restrict to triggers on this physical table, or null for every table
		 * @return list<array{trigger_name: string, table: string, event: string, body: string}>
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
				$rows[] = [
					'trigger_name' => (string)$row['trigger_name'],
					'table'        => (string)($row['table_name'] ?? ''),
					'event'        => (string)($row['event'] ?? ''),
					'body'         => (string)($row['body'] ?? ''),
				];
			}

			return $rows;
		}

		/**
		 * @param string|null $table Restrict to triggers on this physical table, or null for every table
		 * @return array{string, array<string, string>} SQL and its parameters
		 * @throws QuelException When the engine has no triggers
		 */
		private function listQuery(?string $table): array {
			switch ($this->connection->getDatabaseType()) {
				case 'pgsql':
					// EQUEL only creates AFTER ROW triggers, so these event bits identify the write event.
					$sql = "SELECT t.tgname AS trigger_name, c.relname AS table_name, CASE WHEN t.tgtype & 4 <> 0 THEN 'INSERT' WHEN t.tgtype & 8 <> 0 THEN 'DELETE' WHEN t.tgtype & 16 <> 0 THEN 'UPDATE' END AS event, p.prosrc AS body FROM pg_trigger t JOIN pg_proc p ON p.oid = t.tgfoid JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace s ON s.oid = c.relnamespace WHERE s.nspname = :schema AND NOT t.tgisinternal";
					$parameters = ['schema' => (string)$this->connection->getRoutineSchema()];
					$tableColumn = 'c.relname';
					break;

				case 'sqlsrv':
					$sql = 'SELECT t.name AS trigger_name, o.name AS table_name, te.type_desc AS event, sm.definition AS body FROM sys.triggers t JOIN sys.sql_modules sm ON sm.object_id = t.object_id JOIN sys.objects o ON o.object_id = t.parent_id JOIN sys.schemas s ON s.schema_id = o.schema_id JOIN sys.trigger_events te ON te.object_id = t.object_id WHERE s.name = :schema';
					$parameters = ['schema' => (string)$this->connection->getRoutineSchema()];
					$tableColumn = 'o.name';
					break;

				case 'mysql':
				case 'mariadb':
					$sql = 'SELECT TRIGGER_NAME AS trigger_name, EVENT_OBJECT_TABLE AS table_name, EVENT_MANIPULATION AS event, ACTION_STATEMENT AS body FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()';
					$parameters = [];
					$tableColumn = 'EVENT_OBJECT_TABLE';
					break;

				default:
					throw new QuelException("Triggers can't be scanned on '{$this->connection->getDatabaseType()}'.", 'routine_destruction_error');
			}

			if ($table !== null) {
				$sql .= " AND {$tableColumn} = :table";
				$parameters['table'] = $table;
			}

			return [$sql, $parameters];
		}
	}
