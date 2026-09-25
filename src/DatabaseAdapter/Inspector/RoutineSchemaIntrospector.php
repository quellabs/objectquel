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

		/** @var string|null SQL Server default schema; null means not yet read */
		private ?string $routineSchemaCache = null;

		/**
		 * @param DatabaseAdapter $adapter
		 */
		public function __construct(DatabaseAdapter $adapter) {
			$this->adapter = $adapter;
		}

		/**
		 * Returns the schema that qualifies routine names, or null when unqualified
		 * names are used. SQL Server only calls a scalar function by a schema-qualified name,
		 * so there it returns the connection's default schema, read once.
		 * @return string|null
		 * @throws \RuntimeException When the default schema can't be read
		 */
		public function getRoutineSchema(): ?string {
			if ($this->adapter->getDatabaseType() !== 'sqlsrv') {
				return null;
			}

			if ($this->routineSchemaCache !== null) {
				return $this->routineSchemaCache;
			}

			$statement = $this->adapter->execute('SELECT SCHEMA_NAME() AS routine_schema');
			$row = $statement?->fetch('assoc');

			if (!is_array($row) || !is_string($row['routine_schema']) || $row['routine_schema'] === '') {
				throw new \RuntimeException("Can't read the connection's default schema, which qualifies routine names on SQL Server.");
			}

			return $this->routineSchemaCache = $row['routine_schema'];
		}
	}
