<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Derives the canonical name for a column's DEFAULT constraint —
	 * `df_{table}_{column}` — needed only on SQL Server, where `ADD COLUMN
	 * ... DEFAULT ...` creates a named constraint object that must be
	 * addressed by name to drop (unlike MySQL/PostgreSQL, where `DROP
	 * DEFAULT` targets the column directly). See QuelToSQLAlter's
	 * `backfill` compilation. Never author-supplied — always derived, same
	 * as ForeignKeyConstraintNamer.
	 */
	class DefaultConstraintNamer {

		/**
		 * Shared cross-dialect limit (see ForeignKeyConstraintNamer::MAX_LENGTH)
		 * rather than SQL Server's actual 128 — one rule, not a per-dialect split.
		 */
		private const int MAX_LENGTH = 63;

		public static function name(string $tableName, string $column): string {
			return 'df_' . $tableName . '_' . $column;
		}

		/**
		 * Same as name(), but throws when the derived identifier would
		 * exceed the length limit above — a clear compile-time
		 * QuelException instead of a confusing "identifier name is too
		 * long" failure from the database.
		 * @throws QuelException
		 */
		public static function nameOrThrow(string $tableName, string $column): string {
			$name = self::name($tableName, $column);

			if (strlen($name) > self::MAX_LENGTH) {
				throw new QuelException(
					"Cannot derive a default constraint name for '{$tableName}.{$column}': " .
					"'{$name}' is " . strlen($name) . " characters, exceeding the " . self::MAX_LENGTH .
					"-character identifier limit shared by every supported dialect — rename the table or column",
					'default_constraint_name_too_long'
				);
			}

			return $name;
		}
	}
