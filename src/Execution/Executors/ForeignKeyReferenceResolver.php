<?php

	namespace Quellabs\ObjectQuel\Execution\Executors;

	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Resolves a column-less `references Table` clause to the target's
	 * own primary key, mirroring @Orm\ForeignKey's optional
	 * referencedColumn. Shared between CreateTableExecutor and
	 * AlterTableExecutor. Single-column only (decision 3): a target with
	 * no primary key, or a composite one, is rejected loudly rather than
	 * guessed at.
	 */
	class ForeignKeyReferenceResolver {

		/**
		 * Looks up $referencedTable's own primary key to default a column-less
		 * `references Table` clause to.
		 * @param DatabaseAdapter $connection
		 * @param string $referencedTable
		 * @return string
		 * @throws QuelException If the table's schema can't be read, has no
		 *         primary key, or has a composite primary key
		 */
		public static function resolveReferencedColumn(DatabaseAdapter $connection, string $referencedTable): string {
			// getPrimaryKeyColumns() throws its own (non-QuelException) error
			// when $referencedTable doesn't exist yet (e.g. a self-referencing
			// foreign key resolved before its own CREATE TABLE runs) — rewrap
			// so every failure on this path is a QuelException.
			try {
				$columns = $connection->getPrimaryKeyColumns($referencedTable);
			} catch (\Throwable $e) {
				throw new QuelException(
					"Cannot default the referenced column for a foreign key to '{$referencedTable}': " .
					"the table's schema could not be read — {$e->getMessage()}",
					'foreign_key_reference_unresolved',
					previous: $e
				);
			}

			if (count($columns) !== 1) {
				if ($columns === []) {
					$reason = "the table has no primary key";
				} else {
					$reason = "the table has a composite primary key";
				}

				throw new QuelException(
					"Cannot default the referenced column for a foreign key to '{$referencedTable}': {$reason}" .
					" — specify the referenced column explicitly, e.g. \"references {$referencedTable} (column)\"",
					'foreign_key_reference_unresolved'
				);
			}

			return $columns[0];
		}
	}
