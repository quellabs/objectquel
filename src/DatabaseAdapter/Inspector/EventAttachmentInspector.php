<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Inspector;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Reads attachment (trigger) existence from the connected database catalog — creating an
	 * attachment must not silently replace a conflicting existing object (see "Attachment
	 * identity and removal" in objectquel-equel-triggers-design.md).
	 */
	class EventAttachmentInspector {

		/**
		 * @param DatabaseAdapter $connection Connection whose catalog is read
		 */
		public function __construct(private readonly DatabaseAdapter $connection) {
		}

		/**
		 * Checks whether a trigger by this name already exists on this table.
		 * @param string $table Physical table the trigger would be on
		 * @param string $name Generated trigger name (see EventAttachmentNaming)
		 * @return bool
		 * @throws QuelException When the lookup fails or triggers are unsupported
		 */
		public function triggerExists(string $table, string $name): bool {
			[$sql, $parameters] = $this->existenceQuery($table, $name);
			$result = $this->connection->execute($sql, $parameters);

			if ($result === null) {
				throw new QuelException("Failed to look up trigger '{$name}': {$this->connection->getLastErrorMessage()}", 'routine_definition_error');
			}

			$row = $result->fetch('assoc');
			return is_array($row) && is_numeric($row['n'] ?? null) && (int)$row['n'] > 0;
		}

		/**
		 * @param string $table Physical table
		 * @param string $name Trigger name
		 * @return array{string, array<string, string>} SQL and its parameters
		 * @throws QuelException When the engine has no triggers
		 */
		private function existenceQuery(string $table, string $name): array {
			return match ($this->connection->getDatabaseType()) {
				'pgsql' => [
					'SELECT COUNT(*) AS n FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE t.tgname = :name AND c.relname = :table AND NOT t.tgisinternal',
					['name' => $name, 'table' => $table],
				],

				'sqlsrv' => [
					'SELECT COUNT(*) AS n FROM sys.triggers t JOIN sys.objects o ON o.object_id = t.parent_id WHERE t.name = :name AND o.name = :table',
					['name' => $name, 'table' => $table],
				],

				'mysql', 'mariadb' => [
					'SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = :name AND EVENT_OBJECT_TABLE = :table',
					['name' => $name, 'table' => $table],
				],

				default => throw new QuelException("Triggers can't be looked up on '{$this->connection->getDatabaseType()}'.", 'routine_definition_error'),
			};
		}
	}
