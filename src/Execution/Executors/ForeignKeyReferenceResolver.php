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

		public static function resolveReferencedColumn(DatabaseAdapter $connection, string $referencedTable): string {
			$columns = $connection->getPrimaryKeyColumns($referencedTable);

			if (count($columns) !== 1) {
				throw new QuelException(
					"Cannot default the referenced column for a foreign key to '{$referencedTable}': " .
					($columns === [] ? "the table has no primary key" : "the table has a composite primary key") .
					" — specify the referenced column explicitly, e.g. \"references {$referencedTable} (column)\"",
					'foreign_key_reference_unresolved'
				);
			}

			return $columns[0];
		}
	}
