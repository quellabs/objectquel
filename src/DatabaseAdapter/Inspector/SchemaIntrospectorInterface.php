<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Inspector;

	use Quellabs\ObjectQuel\DatabaseAdapter\ColumnDefinition;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\ForeignKeyDefinition;

	/**
	 * Per-engine schema introspection: columns, foreign keys, and index usage
	 * statistics. DatabaseAdapter picks the right implementation via
	 * getDatabaseType() and delegates getColumns()/getForeignKeys()/
	 * getIndexUsageStatistics() to it (see DatabaseAdapter::getSchemaIntrospector())
	 * instead of dispatching on engine type in a single large class — one
	 * class per engine, each holding only the SQL/parsing logic that engine
	 * actually needs.
	 * @phpstan-import-type IndexUsageStats from DatabaseAdapter
	 */
	interface SchemaIntrospectorInterface {

		/**
		 * Retrieves detailed column definitions for a database table.
		 * @param string $tableName
		 * @return array<string, ColumnDefinition>
		 */
		public function getColumns(string $tableName): array;

		/**
		 * Retrieves foreign key constraint definitions for a database table.
		 * @param string $tableName
		 * @return array<string, ForeignKeyDefinition> Constraint name => definition
		 */
		public function getForeignKeys(string $tableName): array;

		/**
		 * Retrieves per-index read/write usage counters, or null when this
		 * engine exposes no such statistics. Null means "unavailable", not
		 * "zero".
		 * @param string[] $tables Table names to fetch statistics for
		 * @return array<string, array<string, IndexUsageStats>>|null Table name => index name => stats
		 */
		public function getIndexUsageStatistics(array $tables): ?array;
	}
