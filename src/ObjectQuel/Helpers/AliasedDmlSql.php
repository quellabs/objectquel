<?php

	namespace Quellabs\ObjectQuel\ObjectQuel\Helpers;

	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;

	/**
	 * Renders UPDATE/DELETE statements whose target table carries a range alias,
	 * in the form the engine accepts: `UPDATE t as a SET ...` on most engines,
	 * `UPDATE a SET ... FROM t as a` on SQL Server.
	 */
	class AliasedDmlSql {

		/**
		 * @param string $tableName Unquoted table name
		 * @param string $alias Unquoted range alias
		 * @param string $setSql Compiled SET assignments
		 * @param string $whereSql Compiled WHERE condition
		 * @param SqlIdentifierQuoter $quoter Quoter for the target engine
		 * @param PlatformCapabilitiesInterface $platform Target engine
		 * @return string
		 */
		public static function update(string $tableName, string $alias, string $setSql, string $whereSql, SqlIdentifierQuoter $quoter, PlatformCapabilitiesInterface $platform): string {
			$table = $quoter->quoteIdentifier($tableName);
			$quotedAlias = $quoter->quoteIdentifier($alias);

			if (!$platform->supportsAliasAfterDmlTarget()) {
				return "UPDATE {$quotedAlias} SET {$setSql} FROM {$table} as {$quotedAlias} WHERE {$whereSql}";
			}

			return "UPDATE {$table} as {$quotedAlias} SET {$setSql} WHERE {$whereSql}";
		}

		/**
		 * @param string $tableName Unquoted table name
		 * @param string $alias Unquoted range alias
		 * @param string $whereSql Compiled WHERE condition
		 * @param SqlIdentifierQuoter $quoter Quoter for the target engine
		 * @param PlatformCapabilitiesInterface $platform Target engine
		 * @return string
		 */
		public static function delete(string $tableName, string $alias, string $whereSql, SqlIdentifierQuoter $quoter, PlatformCapabilitiesInterface $platform): string {
			$table = $quoter->quoteIdentifier($tableName);
			$quotedAlias = $quoter->quoteIdentifier($alias);

			if (!$platform->supportsAliasAfterDmlTarget()) {
				return "DELETE {$quotedAlias} FROM {$table} as {$quotedAlias} WHERE {$whereSql}";
			}

			return "DELETE FROM {$table} as {$quotedAlias} WHERE {$whereSql}";
		}
	}
