<?php

	namespace Quellabs\ObjectQuel\DatabaseAdapter\Inspector;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;

	/**
	 * SQLite fulltext-index support: resolving the base table a SQLite FTS5
	 * external-content virtual table was built against. See
	 * objectquel-destroy-index-plan.md's "Fulltext index destroy on
	 * sqlsrv/sqlite" section.
	 */
	readonly class SqliteFulltextIndexInspector {

		/**
		 * @var DatabaseAdapter
		 */
		private DatabaseAdapter $adapter;

		/**
		 * @param DatabaseAdapter $adapter
		 */
		public function __construct(DatabaseAdapter $adapter) {
			$this->adapter = $adapter;
		}

		/**
		 * Returns the base table name a SQLite FTS5 external-content
		 * virtual table named $indexName was built against, or null if no
		 * such virtual table exists. The FTS5 table is an ordinary
		 * sqlite_master row (type='table') indistinguishable from any other
		 * table except by its own `CREATE VIRTUAL TABLE ... USING
		 * fts5(...)` text — parsed here for the `content=` option
		 * QuelToSQLCreateIndex::compileSqliteFulltext() always sets to the
		 * base table name.
		 * @param string $indexName
		 * @return string|null
		 */
		public function getFts5BaseTable(string $indexName): ?string {
			$statement = $this->adapter->execute("
				SELECT
					sql
				FROM sqlite_master
				WHERE type = 'table' AND name = :name
			", [
				'name' => $indexName
			]);

			if ($statement === null) {
				return null;
			}

			$row = $statement->fetchAssoc();
			$statement->closeCursor();

			if (!$row || !isset($row['sql']) || !preg_match('/using\s+fts5/i', $row['sql'])) {
				return null;
			}

			if (!preg_match("/content\s*=\s*'([^']*)'/i", $row['sql'], $matches)) {
				return null;
			}

			return $matches[1];
		}

		/**
		 * Returns every FTS5 external-content virtual table built against
		 * $baseTable, keyed by its own name, with the columns it indexes —
		 * the reverse of getFts5BaseTable(). Needed so IndexComparator can
		 * recognize an already-created fulltext index as present, since
		 * getIndexes() can never see a virtual table.
		 * @param string $baseTable
		 * @return array<string, array{columns: list<string>}>
		 */
		public function getFts5IndexesForTable(string $baseTable): array {
			$statement = $this->adapter->execute("SELECT name, sql FROM sqlite_master WHERE type = 'table'");

			if ($statement === null) {
				return [];
			}

			$result = [];

			foreach ($statement->fetchAll('assoc') as $row) {
				if (!preg_match('/using\s+fts5/i', $row['sql'])) {
					continue;
				}

				if (!preg_match("/content\s*=\s*'([^']*)'/i", $row['sql'], $contentMatch) || $contentMatch[1] !== $baseTable) {
					continue;
				}

				$columns = $this->parseFts5Columns($row['sql']);

				if ($columns !== []) {
					$result[$row['name']] = ['columns' => $columns];
				}
			}

			return $result;
		}

		/**
		 * Parses the quoted column list out of a `CREATE VIRTUAL TABLE ...
		 * USING fts5(col1, col2, content=..., content_rowid=...)`
		 * statement — everything before the first `content=` option,
		 * matching the exact form
		 * QuelToSQLCreateIndex::compileSqliteFulltext() always generates
		 * (backtick-quoted columns, since SQLite identifiers are quoted
		 * with backticks — see SqlIdentifierQuoter).
		 * @param string $createTableSql
		 * @return list<string>
		 */
		private function parseFts5Columns(string $createTableSql): array {
			if (!preg_match('/using\s+fts5\s*\((.*?)\s*,\s*content\s*=/is', $createTableSql, $matches)) {
				return [];
			}

			preg_match_all('/`([^`]+)`/', $matches[1], $columnMatches);
			return $columnMatches[1];
		}
	}
