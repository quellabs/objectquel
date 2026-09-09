<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	/**
	 * Derives the canonical name for a single-column foreign key constraint
	 * — `fk_{table}_{column}` on every dialect, including SQLite (which
	 * parses `CONSTRAINT name` but never lets you address it afterward).
	 *
	 * Same convention DatabaseAdapter::getSqliteForeignKeys() already
	 * synthesizes purely for introspection round-tripping, promoted here to
	 * the canonical name emitted by QuelToSQLCreate/QuelToSQLAlter on all
	 * four dialects — never author-supplied (see
	 * objectquel-foreign-key-design.md, decision 1). Deliberately not a
	 * method on DDLTypeMapper, which is explicitly scoped to
	 * session-temp-table DDL only.
	 */
	class ForeignKeyConstraintNamer {

		public static function name(string $tableName, string $column): string {
			return 'fk_' . $tableName . '_' . $column;
		}
	}
