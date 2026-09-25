<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Derives the canonical name for a foreign key constraint —
	 * `fk_{table}_{column}` on every dialect, including SQLite (which
	 * parses `CONSTRAINT name` but never lets you address it afterward).
	 *
	 * DatabaseAdapter::getSqliteForeignKeys() and Sculpt\Helpers\ForeignKeyComparator
	 * both delegate here instead of re-deriving the convention themselves,
	 * so it can't silently drift between them.
	 *
	 * Promoted here to the canonical name emitted by
	 * QuelToSQLCreate/QuelToSQLAlter on all four dialects — never
	 * author-supplied (see objectquel-foreign-key-design.md, decision 1).
	 * Deliberately not a method on DDLTypeMapper, which is explicitly
	 * scoped to session-temp-table DDL only.
	 */
	class ForeignKeyConstraintNamer {

		/**
		 * Tightest identifier-length limit shared by every supported
		 * dialect: PostgreSQL 63, MySQL/MariaDB 64, SQL Server 128, SQLite
		 * unenforced. Applied uniformly rather than per-dialect.
		 */
		private const int MAX_LENGTH = 63;

		public static function name(string $tableName, string $column): string {
			return self::nameForColumns($tableName, [$column]);
		}

		/**
		 * Same derivation as name(), generalized to a composite constraint's
		 * full column list — needed by DatabaseAdapter::getSqliteForeignKeys(),
		 * which introspects constraints ObjectQuel may not itself have
		 * created (ObjectQuel's own `foreign key` clause is single-column
		 * only, see objectquel-foreign-key-design.md, decision 3).
		 * @param string[] $columns
		 */
		public static function nameForColumns(string $tableName, array $columns): string {
			return 'fk_' . $tableName . '_' . implode('_', $columns);
		}

		/**
		 * Same as name(), but throws when the derived identifier would
		 * exceed the length limit above — a clear compile-time
		 * QuelException instead of a confusing "identifier name is too
		 * long" failure from the database. Used only where a name is about
		 * to be emitted in new DDL (QuelToSQLCreate/QuelToSQLAlter) — not
		 * by introspection or target-config generation, which must still
		 * name an existing constraint regardless of its length.
		 * @throws QuelException
		 */
		public static function nameOrThrow(string $tableName, string $column): string {
			$name = self::name($tableName, $column);

			if (strlen($name) > self::MAX_LENGTH) {
				throw new QuelException(
					"Cannot derive a foreign key constraint name for '{$tableName}.{$column}': " .
					"'{$name}' is " . strlen($name) . " characters, exceeding the " . self::MAX_LENGTH .
					"-character identifier limit shared by every supported dialect — rename the table or column",
					'foreign_key_name_too_long'
				);
			}

			return $name;
		}
	}
