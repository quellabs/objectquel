<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Schema;

	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Derives the canonical name for a foreign key constraint —
	 * `fk_{table}_{column}`, or `fk_{table}_{col1}_{col2}_...` for a
	 * composite one — on every dialect, including SQLite (which parses
	 * `CONSTRAINT name` but never lets you address it afterward).
	 *
	 * DatabaseAdapter::getSqliteForeignKeys() and Sculpt\Helpers\ForeignKeyComparator
	 * both delegate here instead of re-deriving the convention themselves,
	 * so it can't silently drift between them.
	 *
	 * Promoted here to the canonical name emitted by
	 * QuelToSQLCreate/QuelToSQLAlter on all four dialects — never
	 * author-supplied. Deliberately not a method on DDLTypeMapper, which
	 * is explicitly scoped to session-temp-table DDL only.
	 */
	class ForeignKeyConstraintNamer {

		/**
		 * Tightest identifier-length limit shared by every supported
		 * dialect: PostgreSQL 63, MySQL/MariaDB 64, SQL Server 128, SQLite
		 * unenforced. Applied uniformly rather than per-dialect.
		 */
		private const int MAX_LENGTH = 63;

		/**
		 * Derives the canonical foreign-key constraint name.
		 * @param string $tableName
		 * @param string $column
		 * @return string
		 */
		public static function name(string $tableName, string $column): string {
			return self::nameForColumns($tableName, [$column]);
		}

		/**
		 * Same derivation as name(), generalized to a constraint's full
		 * (possibly composite) column list — needed both by
		 * DatabaseAdapter::getSqliteForeignKeys()/Sculpt\Helpers\ForeignKeyComparator,
		 * which introspect existing constraints, and by nameForColumnsOrThrow()
		 * below, which emits new ones.
		 * @param string[] $columns
		 */
		public static function nameForColumns(string $tableName, array $columns): string {
			return 'fk_' . $tableName . '_' . implode('_', $columns);
		}

		/**
		 * Same as nameForColumns(), but throws when the derived identifier
		 * would exceed the length limit above — a clear compile-time
		 * QuelException instead of a confusing "identifier name is too
		 * long" failure from the database. Used only where a name is about
		 * to be emitted in new DDL (QuelToSQLCreate/QuelToSQLAlter) — not
		 * by introspection or target-config generation, which must still
		 * name an existing constraint regardless of its length.
		 * @param string[] $columns
		 * @throws QuelException
		 */
		public static function nameForColumnsOrThrow(string $tableName, array $columns): string {
			$name = self::nameForColumns($tableName, $columns);

			if (strlen($name) > self::MAX_LENGTH) {
				throw new QuelException(
					"Cannot derive a foreign key constraint name for '{$tableName}.(" . implode(', ', $columns) . ")': " .
					"'{$name}' is " . strlen($name) . " characters, exceeding the " . self::MAX_LENGTH .
					"-character identifier limit shared by every supported dialect — rename the table or column(s)",
					'foreign_key_name_too_long'
				);
			}

			return $name;
		}
	}
