<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Inspector;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;

	/**
	 * Reads the schema that qualifies stored routine names on the connected engine.
	 */
	class RoutineSchemaIntrospector {

		/**
		 * @var DatabaseAdapter
		 */
		private readonly DatabaseAdapter $adapter;

		/** @var string|null Active PostgreSQL or SQL Server schema; null means not yet read */
		private ?string $routineSchemaCache = null;

		/**
		 * @param DatabaseAdapter $adapter
		 */
		public function __construct(DatabaseAdapter $adapter) {
			$this->adapter = $adapter;
		}

		/**
		 * Returns the connected schema for PostgreSQL and SQL Server, read once.
		 * Other engines use unqualified routine names.
		 * @return string|null
		 * @throws \RuntimeException When the connected schema can't be read
		 */
		public function getRoutineSchema(): ?string {
			$databaseType = $this->adapter->getDatabaseType();

			if (!in_array($databaseType, ['pgsql', 'sqlsrv'], true)) {
				return null;
			}

			if ($this->routineSchemaCache !== null) {
				return $this->routineSchemaCache;
			}

			if ($databaseType === 'pgsql') {
				$query = 'SELECT current_schema() AS routine_schema';
			} else {
				$query = 'SELECT SCHEMA_NAME() AS routine_schema';
			}

			$statement = $this->adapter->execute($query);
			$row = $statement?->fetch('assoc');

			if (!is_array($row) || !is_string($row['routine_schema']) || $row['routine_schema'] === '') {
				throw new \RuntimeException("Can't read the connection's schema, which qualifies routine names on {$databaseType}.");
			}

			return $this->routineSchemaCache = $row['routine_schema'];
		}
	}
