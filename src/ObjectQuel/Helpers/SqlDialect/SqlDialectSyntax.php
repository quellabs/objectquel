<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers\SqlDialect;

	/** Renders SQL fragments shared by persistence, write queries and schema generation. */
	class SqlDialectSyntax {
		/**
		 * Returns the native current-datetime expression.
		 * @param string $databaseType Database engine identifier
		 * @return string SQL expression
		 */
		public static function currentDatetime(string $databaseType): string {
			return match ($databaseType) {
				'sqlite' => 'CURRENT_TIMESTAMP',
				'sqlsrv' => 'SYSDATETIME()',
				default => 'NOW()',
			};
		}

		/**
		 * Returns the database's native JSON column type.
		 * @param string $databaseType Database engine identifier
		 * @return string SQL type name
		 */
		public static function nativeJsonType(string $databaseType): string {
			return $databaseType === 'pgsql' ? 'jsonb' : 'json';
		}
	}
