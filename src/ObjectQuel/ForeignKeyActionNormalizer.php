<?php

	namespace Quellabs\ObjectQuel\ObjectQuel;

	/**
	 * Normalizes a foreign key's ON DELETE/ON UPDATE action for the target
	 * dialect. RESTRICT is valid MySQL/MariaDB/PostgreSQL/SQLite syntax,
	 * but T-SQL's FOREIGN KEY clause only accepts NO ACTION | CASCADE |
	 * SET NULL | SET DEFAULT. NO ACTION is the behavioral equivalent on
	 * SQL Server, so RESTRICT maps to it there; every other action/dialect
	 * passes through unchanged.
	 */
	class ForeignKeyActionNormalizer {

		public static function forDialect(string $action, string $databaseType): string {
			if ($databaseType === 'sqlsrv' && $action === 'RESTRICT') {
				return 'NO ACTION';
			}

			return $action;
		}
	}
