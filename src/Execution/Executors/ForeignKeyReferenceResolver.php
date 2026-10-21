<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Resolves a column-less `references Table` clause to the target's own
	 * primary key, mirroring @Orm\ForeignKey's optional
	 * referencedColumn/columns. Shared between CreateTableExecutor and
	 * AlterTableExecutor. The target's primary key must have the same
	 * number of columns as the foreign key's local column list — a target
	 * with no primary key, or one whose column count doesn't match, is
	 * rejected loudly rather than guessed at.
	 */
	class ForeignKeyReferenceResolver {

		/**
		 * Looks up $referencedTable's own primary key to default a column-less
		 * `references Table` clause to.
		 * @param DatabaseAdapter $connection
		 * @param string $referencedTable
		 * @param int $expectedColumnCount Number of local columns the foreign key declares
		 * @return list<string>
		 * @throws QuelException If the table's schema can't be read, has no
		 *         primary key, or its primary key's column count doesn't
		 *         match $expectedColumnCount
		 */
		public static function resolveReferencedColumns(DatabaseAdapter $connection, string $referencedTable, int $expectedColumnCount): array {
			// getPrimaryKeyColumns() throws its own (non-QuelException) error
			// when $referencedTable doesn't exist yet (e.g. a self-referencing
			// foreign key resolved before its own CREATE TABLE runs) — rewrap
			// so every failure on this path is a QuelException.
			try {
				$columns = $connection->getPrimaryKeyColumns($referencedTable);
			} catch (\Throwable $e) {
				throw new QuelException(
					"Cannot default the referenced column(s) for a foreign key to '{$referencedTable}': " .
					"the table's schema could not be read — {$e->getMessage()}",
					'foreign_key_reference_unresolved',
					previous: $e
				);
			}

			if (count($columns) === $expectedColumnCount) {
				return array_values($columns);
			}

			if ($columns === []) {
				$reason = "the table has no primary key";
			} elseif ($expectedColumnCount === 1) {
				$reason = "the table has a composite primary key";
			} else {
				$reason = "the table's primary key has " . count($columns) . " column(s), but the foreign key declares {$expectedColumnCount}";
			}

			throw new QuelException(
				"Cannot default the referenced column(s) for a foreign key to '{$referencedTable}': {$reason}" .
				" — specify the referenced column(s) explicitly, e.g. \"references {$referencedTable} (column {, column})\"",
				'foreign_key_reference_unresolved'
			);
		}
	}
