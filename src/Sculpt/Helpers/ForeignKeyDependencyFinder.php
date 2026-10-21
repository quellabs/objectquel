<?php

	namespace Quellabs\ObjectQuel\Sculpt\Helpers;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\ForeignKeyDefinition;

	/**
	 * Finds every live foreign key constraint — on any table — that
	 * references one of a given set of tables, including a table
	 * referencing itself.
	 *
	 * A reverse lookup, unlike DatabaseAdapter::getForeignKeys()'s normal
	 * single-table "what does this table reference" direction — there's no
	 * per-engine catalog shortcut for "what references this table", so it's
	 * a scan over every table in the schema rather than one query. Used by
	 * QuelMigrationBuilder to find what would block a primary key change
	 * before it runs, since every engine that enforces named foreign keys
	 * rejects that change outright otherwise.
	 */
	class ForeignKeyDependencyFinder {

		/** @var DatabaseAdapter Database connection used for the live schema scan */
		private DatabaseAdapter $connection;

		/**
		 * @param DatabaseAdapter $connection Active database connection
		 */
		public function __construct(DatabaseAdapter $connection) {
			$this->connection = $connection;
		}

		/**
		 * @param list<string> $tableNames Tables to find referencing constraints for
		 * @return array<string, array<string, ForeignKeyDefinition>> Owner table => constraint name => live definition
		 */
		public function findReferencing(array $tableNames): array {
			$tableNameSet = array_flip($tableNames);
			$result = [];

			foreach ($this->connection->getTables() as $ownerTable) {
				foreach ($this->connection->getForeignKeys($ownerTable) as $name => $definition) {
					if (isset($tableNameSet[$definition->referencedTable])) {
						$result[$ownerTable][$name] = $definition;
					}
				}
			}

			return $result;
		}
	}
