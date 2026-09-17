<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Inspector;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;

	/**
	 * SQL Server fulltext-index support: detecting whether a table has a
	 * T-SQL fulltext index, and reading the extended property
	 * QuelToSQLCreateIndex/QuelToSQLDestroyIndex use to correlate a QUEL
	 * index name against it. Unlike SchemaIntrospectorInterface's four
	 * engines, these queries have no MySQL/PostgreSQL/SQLite equivalent —
	 * T-SQL fulltext indexes are unnamed and live in their own sys.* catalog
	 * views, invisible to DatabaseAdapter::getIndexes(). See
	 * objectquel-destroy-index-plan.md's "Fulltext index destroy on
	 * sqlsrv/sqlite" section.
	 */
	class SqlServerFulltextIndexInspector {

		/**
		 * @var DatabaseAdapter
		 */
		private readonly DatabaseAdapter $adapter;

		/**
		 * @param DatabaseAdapter $adapter
		 */
		public function __construct(DatabaseAdapter $adapter) {
			$this->adapter = $adapter;
		}

		/**
		 * Whether a SQL Server table currently has a fulltext index. T-SQL
		 * fulltext indexes live in sys.fulltext_indexes, not in the ordinary
		 * schema-collection index/constraint lists
		 * DatabaseAdapter::getIndexes() reads from, so they're otherwise
		 * invisible to it.
		 * @param string $tableName
		 * @return bool
		 */
		public function hasFulltextIndex(string $tableName): bool {
			$statement = $this->adapter->execute("
				SELECT 1 AS found
				FROM sys.fulltext_indexes fi
				JOIN sys.tables t ON t.object_id = fi.object_id
				WHERE t.name = :tableName
			", [
				'tableName' => $tableName
			]);

			if ($statement === null) {
				return false;
			}

			$row = $statement->fetchAssoc();
			$statement->closeCursor();

			return (bool)$row;
		}

		/**
		 * Reads a table-level extended property — SQL Server's standard,
		 * inspectable (via sys.extended_properties, same as any DB tool)
		 * object-annotation mechanism, not a hidden framework-side registry.
		 * Used by QuelToSQLCreateIndex/QuelToSQLDestroyIndex to correlate a
		 * QUEL index name against a table's fulltext index, which is itself
		 * unnamed at the T-SQL level (see hasFulltextIndex()). Assumes the
		 * default 'dbo' schema, matching every other sqlsrv code path in this
		 * codebase — no schema-qualification exists for QUEL-created objects.
		 * @param string $tableName
		 * @param string $propertyName
		 * @return string|null The property's value, or null if unset
		 */
		public function getExtendedProperty(string $tableName, string $propertyName): ?string {
			$statement = $this->adapter->execute("
				SELECT
					CAST(value AS NVARCHAR(4000)) AS property_value
				FROM sys.extended_properties
				WHERE major_id = OBJECT_ID(:tableName)
				  AND minor_id = 0
				  AND class = 1
				  AND name = :propertyName
			", [
				'tableName' => $tableName,
				'propertyName' => $propertyName
			]);

			if ($statement === null) {
				return null;
			}

			$row = $statement->fetchAssoc();
			$statement->closeCursor();

			return $row['property_value'] ?? null;
		}
	}
