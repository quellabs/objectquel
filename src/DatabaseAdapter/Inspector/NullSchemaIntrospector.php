<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Inspector;

	use Quellabs\ObjectQuel\DatabaseAdapter\ColumnDefinition;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\ForeignKeyDefinition;

	/**
	 * Fallback for an engine getDatabaseType() cannot map to a concrete
	 * introspector. getDatabaseType() is presently a closed enumeration (see
	 * DatabaseAdapter::getDatabaseType()), so this exists purely as a
	 * defensive fallback for a future unmapped engine value — not a branch
	 * reachable today — matching the old dispatch match()'s 'default' arms.
	 * @phpstan-import-type IndexUsageStats from DatabaseAdapter
	 */
	class NullSchemaIntrospector implements SchemaIntrospectorInterface {

		/**
		 * Always reports no columns — this engine has no concrete introspector.
		 * @param string $tableName
		 * @return array<string, ColumnDefinition>
		 */
		public function getColumns(string $tableName): array {
			return [];
		}

		/**
		 * Always reports no foreign keys — this engine has no concrete introspector.
		 * @param string $tableName
		 * @return array<string, ForeignKeyDefinition>
		 */
		public function getForeignKeys(string $tableName): array {
			return [];
		}

		/**
		 * Always reports index usage statistics as unavailable — this engine
		 * has no concrete introspector.
		 * @param string[] $tables
		 * @return array<string, array<string, IndexUsageStats>>|null
		 */
		public function getIndexUsageStatistics(array $tables): ?array {
			return null;
		}
	}
